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
     * Stable lowercase key of the provider (e.g. "mollie"), used in config, webhook URL and stored payment data
     */
    public function getName(): string;

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
     * @throws PaymentUnavailableException if the provider can't be reached or has a temporary error
     * @throws PaymentException if the payment does not exist or the request fails otherwise
     */
    public function getPayment(string $paymentId): PaymentResult;

    /**
     * Verifies an incoming webhook call the provider's way and returns the id of the payment to look up.
     * Callers must never act on the webhook data itself, only on getPayment() of the returned id.
     * @param array<string, mixed>  $post    Parsed POST parameters ($_POST)
     * @param array<string, string> $headers Request headers, e.g. for signature checks
     * @param string                $rawBody Unparsed request body, e.g. for signature checks
     * @return string|null Payment id, or null if the call is invalid and must be ignored
     */
    public function extractWebhookPaymentId(array $post, array $headers, string $rawBody): ?string;
}
