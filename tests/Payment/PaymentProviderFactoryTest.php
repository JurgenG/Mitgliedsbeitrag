<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Payment;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Payment\FakeProvider;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentProviderFactory;

final class PaymentProviderFactoryTest extends TestCase
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

    private function factory(bool $fakeAllowed): PaymentProviderFactory
    {
        return new PaymentProviderFactory($fakeAllowed, $this->file, 'https://example.org/fake_checkout.php');
    }

    /**
     * @dataProvider environmentValues
     */
    public function testFakeIsOnlyAllowedWhenEnvironmentSaysExactlyOne(string|false $value, bool $expected): void
    {
        $this->assertSame($expected, PaymentProviderFactory::isFakeAllowed($value));
    }

    /**
     * @return array<string, array{string|false, bool}>
     */
    public static function environmentValues(): array
    {
        return array(
            'not set' => array(false, false),
            'empty'   => array('', false),
            'zero'    => array('0', false),
            'true'    => array('true', false),
            'one'     => array('1', true)
        );
    }

    public function testEnvironmentVariableName(): void
    {
        $this->assertSame('MEMBERSHIPFEE_PAYMENT_FAKE', PaymentProviderFactory::ENV_FAKE);
    }

    public function testFakeIsSelectableOnlyWhenAllowed(): void
    {
        $this->assertSame(array('fake'), $this->factory(true)->selectableProviders());
        $this->assertSame(array(), $this->factory(false)->selectableProviders());
    }

    public function testCreatesFakeProviderWithStorageAndCheckoutPage(): void
    {
        $provider = $this->factory(true)->create('fake');

        $this->assertInstanceOf(FakeProvider::class, $provider);
        $payment = $provider->createPayment('25.00', 'EUR', 'x', 'https://example.org/return', 'https://example.org/webhook', array());

        $this->assertSame('https://example.org/fake_checkout.php?payment=' . $payment->id, $payment->checkoutUrl);
        $this->assertSame($payment->id, $this->factory(true)->create('fake')->getPayment($payment->id)->id);
    }

    public function testFakeCannotBeCreatedWhenNotAllowed(): void
    {
        $this->expectException(PaymentException::class);
        $this->factory(false)->create('fake');
    }

    public function testMollieIsNotAvailableYet(): void
    {
        $this->assertNotContains('mollie', $this->factory(true)->selectableProviders());

        $this->expectException(PaymentException::class);
        $this->factory(true)->create('mollie');
    }

    public function testUnknownProviderThrows(): void
    {
        $this->expectException(PaymentException::class);
        $this->factory(true)->create('stripe');
    }
}
