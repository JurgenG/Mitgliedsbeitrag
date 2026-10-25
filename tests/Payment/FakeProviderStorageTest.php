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
use Plugins\MembershipFee\classes\Payment\PaymentResult;

/**
 * FakeProvider with a storage file, as used in a local Admidio instance
 */
final class FakeProviderStorageTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/membershipfee_fake_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function create(FakeProvider $provider): PaymentResult
    {
        return $provider->createPayment(
            '25.00',
            'EUR',
            'Membership fee 2026',
            'https://example.org/pay.php?mode=return',
            'https://example.org/webhook',
            array('org_id' => '1', 'user_uuid' => 'abc', 'fee' => '25.00')
        );
    }

    public function testPaymentIsVisibleToALaterInstance(): void
    {
        $created = $this->create(new FakeProvider($this->file));

        $this->assertEquals($created, (new FakeProvider($this->file))->getPayment($created->id));
    }

    public function testStatusChangeIsStored(): void
    {
        $id = $this->create(new FakeProvider($this->file))->id;
        $paidAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');

        (new FakeProvider($this->file))->setStatus($id, PaymentResult::STATUS_PAID, $paidAt);
        $payment = (new FakeProvider($this->file))->getPayment($id);

        $this->assertTrue($payment->isPaid());
        $this->assertEquals($paidAt, $payment->paidAt);
        $this->assertNull($payment->checkoutUrl);
    }

    public function testSeveralPaymentsAreKept(): void
    {
        $first = $this->create(new FakeProvider($this->file));
        $second = $this->create(new FakeProvider($this->file));

        $provider = new FakeProvider($this->file);
        $this->assertSame($first->id, $provider->getPayment($first->id)->id);
        $this->assertSame($second->id, $provider->getPayment($second->id)->id);
    }

    public function testUnknownPaymentWithoutFileThrows(): void
    {
        $this->expectException(PaymentException::class);
        (new FakeProvider($this->file))->getPayment('tr_fakemissing');
    }

    public function testCheckoutUrlPointsToConfiguredCheckoutPage(): void
    {
        $provider = new FakeProvider($this->file, 'http://localhost:8090/adm_plugins/MembershipFee/system/fake_checkout.php');
        $payment = $this->create($provider);

        $this->assertSame(
            'http://localhost:8090/adm_plugins/MembershipFee/system/fake_checkout.php?payment=' . $payment->id,
            $payment->checkoutUrl
        );
    }

    public function testCheckoutUrlAppendsToExistingQuery(): void
    {
        $payment = $this->create(new FakeProvider($this->file, 'https://example.org/checkout.php?x=1'));

        $this->assertSame('https://example.org/checkout.php?x=1&payment=' . $payment->id, $payment->checkoutUrl);
    }

    public function testRedirectUrlIsKeptForTheCheckoutPage(): void
    {
        $id = $this->create(new FakeProvider($this->file))->id;

        $this->assertSame('https://example.org/pay.php?mode=return', (new FakeProvider($this->file))->getRedirectUrl($id));
    }

    public function testRedirectUrlOfUnknownPaymentThrows(): void
    {
        $this->expectException(PaymentException::class);
        (new FakeProvider())->getRedirectUrl('tr_fakemissing');
    }
}
