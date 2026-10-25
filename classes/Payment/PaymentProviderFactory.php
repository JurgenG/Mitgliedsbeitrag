<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentProviderFactory
 * @brief Decides which payment providers may be selected and builds the active one
 *
 * The fake provider is only available on development machines, see ENV_FAKE.
 */

namespace Plugins\MembershipFee\classes\Payment;

final class PaymentProviderFactory
{
    /** Environment variable that must be exactly "1" to allow the fake provider */
    public const ENV_FAKE = 'MEMBERSHIPFEE_PAYMENT_FAKE';

    /**
     * @param bool   $fakeAllowed         True on development machines only, see isFakeAllowed()
     * @param string $fakeStorageFile     JSON file in which the fake provider keeps its payments
     * @param string $fakeCheckoutUrl     URL of fake_checkout.php
     */
    public function __construct(
        private readonly bool $fakeAllowed,
        private readonly string $fakeStorageFile,
        private readonly string $fakeCheckoutUrl
    ) {
    }

    /**
     * Tells from the value of the environment variable ENV_FAKE whether the fake provider is allowed
     * @param string|false $environmentValue Result of getenv(ENV_FAKE)
     */
    public static function isFakeAllowed(string|false $environmentValue): bool
    {
        return $environmentValue === '1';
    }

    /**
     * Names of the providers that can be selected and created
     * @return array<int, string>
     */
    public function selectableProviders(): array
    {
        // 'mollie' is added once MollieProvider exists
        return $this->fakeAllowed ? array('fake') : array();
    }

    /**
     * Builds the provider with the given name
     * @param string $name
     * @throws PaymentException if the provider can't be selected
     */
    public function create(string $name): PaymentProviderInterface
    {
        if (!in_array($name, $this->selectableProviders(), true)) {
            throw new PaymentException('Payment provider not available: ' . $name);
        }

        return new FakeProvider($this->fakeStorageFile, $this->fakeCheckoutUrl);
    }
}
