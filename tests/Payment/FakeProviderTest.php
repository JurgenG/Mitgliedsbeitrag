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
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Payment\FakeProvider;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentProviderInterface;
use Plugins\MembershipFee\classes\Payment\PaymentResult;

final class FakeProviderTest extends TestCase
{
    private FakeProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new FakeProvider();
    }

    private function create(string $amount = '25.00'): PaymentResult
    {
        return $this->provider->createPayment(
            $amount,
            'EUR',
            'Membership fee 2026',
            'https://example.org/return',
            'https://example.org/webhook',
            array('org_id' => '1', 'user_uuid' => 'abc')
        );
    }

    public function testImplementsProviderInterface(): void
    {
        $this->assertInstanceOf(PaymentProviderInterface::class, $this->provider);
    }

    public function testCreatePaymentReturnsOpenPaymentWithCheckoutUrl(): void
    {
        $payment = $this->create();

        $this->assertMatchesRegularExpression('/^tr_[A-Za-z0-9]+$/', $payment->id);
        $this->assertSame(PaymentResult::STATUS_OPEN, $payment->status);
        $this->assertSame('25.00', $payment->amount);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame('Membership fee 2026', $payment->description);
        $this->assertNotNull($payment->checkoutUrl);
        $this->assertNull($payment->paidAt);
        $this->assertSame(array('org_id' => '1', 'user_uuid' => 'abc'), $payment->metadata);
        $this->assertFalse($payment->isPaid());
    }

    public function testEachPaymentGetsAUniqueId(): void
    {
        $this->assertNotSame($this->create()->id, $this->create()->id);
    }

    public function testGetPaymentReturnsStoredPayment(): void
    {
        $created = $this->create();

        $this->assertEquals($created, $this->provider->getPayment($created->id));
    }

    public function testGetPaymentWithUnknownIdThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->provider->getPayment('tr_unknown');
    }

    public function testSetStatusPaidMarksPaymentAsPaid(): void
    {
        $created = $this->create();
        $paidAt = new DateTimeImmutable('2026-10-25T14:03:00+02:00');

        $this->provider->setStatus($created->id, PaymentResult::STATUS_PAID, $paidAt);
        $payment = $this->provider->getPayment($created->id);

        $this->assertTrue($payment->isPaid());
        $this->assertEquals($paidAt, $payment->paidAt);
        $this->assertNull($payment->checkoutUrl);
    }

    public function testSetStatusRejectsUnknownStatus(): void
    {
        $created = $this->create();

        $this->expectException(PaymentException::class);
        $this->provider->setStatus($created->id, 'bogus');
    }

    /**
     * @dataProvider invalidAmounts
     */
    public function testCreatePaymentRejectsInvalidAmount(string $amount): void
    {
        $this->expectException(PaymentException::class);
        $this->create($amount);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return array(
            'no decimals'   => array('25'),
            'comma decimal' => array('25,00'),
            'one decimal'   => array('25.0'),
            'negative'      => array('-1.00'),
            'zero'          => array('0.00'),
            'empty'         => array('')
        );
    }
}
