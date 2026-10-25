<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @interface PaymentProviderInterface
 * @brief Contract every online payment provider (Mollie, fake, ...) implements
 *
 * The rest of the plugin only talks to this interface, never to a provider directly.
 */

namespace Plugins\MembershipFee\classes\Payment;

interface PaymentProviderInterface
{
    /**
     * Creates a one-off payment and returns it including the checkout URL for the payer
     * @param string                $amount      Amount with two decimals, e.g. "25.00"
     * @param string                $currency    ISO 4217 code, e.g. "EUR"
     * @param string                $description Purpose shown to the payer
     * @param string                $redirectUrl Where the payer returns to after checkout
     * @param string                $webhookUrl  Where the provider reports status changes
     * @param array<string, string> $metadata    Data returned unchanged with the payment
     * @throws PaymentException
     */
    public function createPayment(
        string $amount,
        string $currency,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        array $metadata
    ): PaymentResult;

    /**
     * Fetches the current state of a payment from the provider
     * @param string $paymentId
     * @throws PaymentException if the payment does not exist or the provider can't be reached
     */
    public function getPayment(string $paymentId): PaymentResult;
}
