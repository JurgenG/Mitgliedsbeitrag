<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Payment;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Payment\FakeProvider;
use Plugins\MembershipFee\classes\Payment\InvalidPayLinkException;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentMember;
use Plugins\MembershipFee\classes\Payment\PaymentResult;
use Plugins\MembershipFee\classes\Payment\PaymentService;

final class PaymentServiceTest extends TestCase
{
    private const UUID = 'b2c4e6f8-0000-4000-8000-000000000001';
    private const REDIRECT_URL = 'https://example.org/pay.php?mode=return';
    private const WEBHOOK_URL = 'https://example.org/payment_webhook.php?provider=fake&org=1';

    private FakeProvider $provider;
    private InMemoryPaymentMemberStore $store;
    private PaymentService $service;
    private string $token;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
        $this->store = new InMemoryPaymentMemberStore();
        $this->token = str_repeat('ab', 32);
        $this->store->put(new PaymentMember(self::UUID, 25.0, false, $this->token, 'Membership fee 2026'));
        $this->service = new PaymentService(
            $this->provider,
            $this->store,
            1,
            new DateTimeZone('Europe/Brussels'),
            'Membership fee'
        );
    }

    private function start(?string $token = null): ?PaymentResult
    {
        return $this->service->startPayment(self::UUID, $token ?? $this->token, self::REDIRECT_URL, self::WEBHOOK_URL);
    }

    private function webhook(string $paymentId): string
    {
        return $this->service->handleWebhook(array('id' => $paymentId), array(), 'id=' . $paymentId);
    }

    private function setFee(float $fee, bool $paid = false): void
    {
        $member = $this->store->findMember(self::UUID);
        $this->store->put(new PaymentMember(self::UUID, $fee, $paid, $member->token, $member->purpose));
    }

    /**
     * @dataProvider amounts
     */
    public function testFormatAmountGivesTwoDecimalsWithDot(float $fee, string $expected): void
    {
        $this->assertSame($expected, PaymentService::formatAmount($fee));
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function amounts(): array
    {
        return array(
            'integer'       => array(25.0, '25.00'),
            'one decimal'   => array(12.5, '12.50'),
            'float noise'   => array(0.1 + 0.2, '0.30'),
            'rounded'       => array(1234.567, '1234.57'),
            'no thousands'  => array(1500.0, '1500.00')
        );
    }

    // ---- tokens -------------------------------------------------------------------------------

    public function testGetOrCreateTokenReturnsExistingToken(): void
    {
        $this->assertSame($this->token, $this->service->getOrCreateToken(self::UUID));
    }

    public function testGetOrCreateTokenCreatesAndStoresTokenOnce(): void
    {
        $this->store->put(new PaymentMember(self::UUID, 25.0, false, '', 'Membership fee 2026'));

        $token = $this->service->getOrCreateToken(self::UUID);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $this->store->findMember(self::UUID)->token);
        $this->assertSame($token, $this->service->getOrCreateToken(self::UUID));
    }

    public function testGetOrCreateTokenForUnknownMemberThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->service->getOrCreateToken('unknown-uuid');
    }

    // ---- startPayment -------------------------------------------------------------------------

    public function testStartPaymentCreatesPaymentForCurrentFee(): void
    {
        $this->setFee(12.5);

        $payment = $this->start();

        $this->assertNotNull($payment);
        $this->assertSame('12.50', $payment->amount);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame('Membership fee 2026', $payment->description);
        $this->assertNotNull($payment->checkoutUrl);
        $this->assertSame(
            array('org_id' => '1', 'user_uuid' => self::UUID, 'fee' => '12.50'),
            $payment->metadata
        );
    }

    public function testStartPaymentCreatesANewPaymentOnEveryCall(): void
    {
        $this->assertNotSame($this->start()->id, $this->start()->id);
    }

    public function testStartPaymentUsesFallbackDescriptionWhenPurposeIsEmpty(): void
    {
        $this->store->put(new PaymentMember(self::UUID, 25.0, false, $this->token, ''));

        $this->assertSame('Membership fee', $this->start()->description);
    }

    public function testStartPaymentReturnsNullWhenAlreadyPaid(): void
    {
        $this->setFee(25.0, true);

        $this->assertNull($this->start());
    }

    /**
     * @dataProvider nothingToPay
     */
    public function testStartPaymentReturnsNullWithoutPositiveFee(float $fee): void
    {
        $this->setFee($fee);

        $this->assertNull($this->start());
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nothingToPay(): array
    {
        return array(
            'zero'     => array(0.0),
            'negative' => array(-5.0)
        );
    }

    /**
     * @dataProvider invalidLinks
     */
    public function testStartPaymentRejectsInvalidLink(string $uuid, string $storedToken, string $givenToken): void
    {
        $this->store->put(new PaymentMember(self::UUID, 25.0, false, $storedToken, 'Membership fee 2026'));

        $this->expectException(InvalidPayLinkException::class);
        $this->service->startPayment($uuid, $givenToken, self::REDIRECT_URL, self::WEBHOOK_URL);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidLinks(): array
    {
        $token = str_repeat('ab', 32);

        return array(
            'unknown member'         => array('unknown-uuid', $token, $token),
            'wrong token'            => array(self::UUID, $token, str_repeat('cd', 32)),
            'empty token given'      => array(self::UUID, $token, ''),
            'no token stored, empty' => array(self::UUID, '', ''),
            'prefix of token'        => array(self::UUID, $token, substr($token, 0, 32))
        );
    }

    public function testInvalidPayLinkIsAPaymentException(): void
    {
        $this->assertInstanceOf(PaymentException::class, new InvalidPayLinkException('x'));
    }

    // ---- handleWebhook ------------------------------------------------------------------------

    public function testWebhookIgnoresInvalidCall(): void
    {
        $this->assertSame(
            PaymentService::WEBHOOK_IGNORED,
            $this->service->handleWebhook(array(), array(), '')
        );
    }

    public function testWebhookReportsUnknownPayment(): void
    {
        $this->assertSame(PaymentService::WEBHOOK_UNKNOWN_PAYMENT, $this->webhook('tr_doesnotexist'));
    }

    /**
     * @dataProvider unpaidStatuses
     */
    public function testWebhookDoesNothingForUnpaidPayment(string $status): void
    {
        $payment = $this->start();
        $this->provider->setStatus($payment->id, $status);

        $this->assertSame(PaymentService::WEBHOOK_NOT_PAID, $this->webhook($payment->id));
        $this->assertSame(array(), $this->store->markPaidCalls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unpaidStatuses(): array
    {
        return array(
            'open'     => array(PaymentResult::STATUS_OPEN),
            'canceled' => array(PaymentResult::STATUS_CANCELED),
            'expired'  => array(PaymentResult::STATUS_EXPIRED),
            'failed'   => array(PaymentResult::STATUS_FAILED)
        );
    }

    public function testWebhookIgnoresPaymentOfOtherOrganisation(): void
    {
        $payment = $this->provider->createPayment('25.00', 'EUR', 'x', self::REDIRECT_URL, self::WEBHOOK_URL,
            array('org_id' => '2', 'user_uuid' => self::UUID, 'fee' => '25.00'));
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_NOT_OURS, $this->webhook($payment->id));
        $this->assertSame(array(), $this->store->markPaidCalls);
    }

    public function testWebhookIgnoresPaymentOfUnknownMember(): void
    {
        $payment = $this->provider->createPayment('25.00', 'EUR', 'x', self::REDIRECT_URL, self::WEBHOOK_URL,
            array('org_id' => '1', 'user_uuid' => 'unknown-uuid', 'fee' => '25.00'));
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_NOT_OURS, $this->webhook($payment->id));
    }

    public function testWebhookIgnoresPaymentWithoutMetadata(): void
    {
        $payment = $this->provider->createPayment('25.00', 'EUR', 'x', self::REDIRECT_URL, self::WEBHOOK_URL, array());
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_NOT_OURS, $this->webhook($payment->id));
    }

    public function testWebhookMarksMemberPaid(): void
    {
        $payment = $this->start();
        $this->provider->setStatus(
            $payment->id,
            PaymentResult::STATUS_PAID,
            new DateTimeImmutable('2026-03-15T10:00:00+00:00')
        );

        $this->assertSame(PaymentService::WEBHOOK_MARKED_PAID, $this->webhook($payment->id));
        $this->assertCount(1, $this->store->markPaidCalls);

        $call = $this->store->markPaidCalls[0];
        $this->assertSame(self::UUID, $call['userUuid']);
        $this->assertSame('2026-03-15', $call['paidDate']);
        $this->assertSame($payment->id, $call['paymentId']);
        $this->assertSame('fake', $call['provider']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $call['newToken']);
        $this->assertNotSame($this->token, $call['newToken']);
    }

    public function testPaidDateIsTheProviderTimestampInOrganisationTimezone(): void
    {
        $payment = $this->start();
        // 23:30 UTC on 31 March is already 1 April in Brussels (UTC+2)
        $this->provider->setStatus(
            $payment->id,
            PaymentResult::STATUS_PAID,
            new DateTimeImmutable('2026-03-31T23:30:00+00:00')
        );

        $this->webhook($payment->id);

        $this->assertSame('2026-04-01', $this->store->markPaidCalls[0]['paidDate']);
    }

    public function testOldPayLinkStopsWorkingAfterPayment(): void
    {
        $payment = $this->start();
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);
        $this->webhook($payment->id);

        $this->expectException(InvalidPayLinkException::class);
        $this->start($this->token);
    }

    public function testWebhookIsIdempotent(): void
    {
        $payment = $this->start();
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_MARKED_PAID, $this->webhook($payment->id));
        $this->assertSame(PaymentService::WEBHOOK_ALREADY_PAID, $this->webhook($payment->id));
        $this->assertCount(1, $this->store->markPaidCalls);
    }

    public function testWebhookDoesNotMarkPaidWhenFeeChangedAfterCheckout(): void
    {
        $payment = $this->start();
        $this->setFee(30.0);
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_AMOUNT_MISMATCH, $this->webhook($payment->id));
        $this->assertSame(array(), $this->store->markPaidCalls);
    }

    public function testWebhookDoesNotMarkPaidForOtherCurrency(): void
    {
        $payment = $this->provider->createPayment('25.00', 'USD', 'x', self::REDIRECT_URL, self::WEBHOOK_URL,
            array('org_id' => '1', 'user_uuid' => self::UUID, 'fee' => '25.00'));
        $this->provider->setStatus($payment->id, PaymentResult::STATUS_PAID);

        $this->assertSame(PaymentService::WEBHOOK_AMOUNT_MISMATCH, $this->webhook($payment->id));
        $this->assertSame(array(), $this->store->markPaidCalls);
    }
}
