<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentService
 * @brief Plugin payment logic for one organisation: open fee → payment, paid payment → PAID
 *
 * Talks only to PaymentProviderInterface and PaymentMemberStoreInterface, never to Admidio
 * globals, so it can be unit tested. See docs/online-payments.md.
 */

namespace Plugins\MembershipFee\classes\Payment;

use DateTimeImmutable;
use DateTimeZone;

final class PaymentService
{
    public const CURRENCY = 'EUR';

    public const WEBHOOK_IGNORED = 'ignored';
    public const WEBHOOK_PROVIDER_UNAVAILABLE = 'provider_unavailable';
    public const WEBHOOK_UNKNOWN_PAYMENT = 'unknown_payment';
    public const WEBHOOK_NOT_PAID = 'not_paid';
    public const WEBHOOK_NOT_OURS = 'not_ours';
    public const WEBHOOK_ALREADY_PAID = 'already_paid';
    public const WEBHOOK_AMOUNT_MISMATCH = 'amount_mismatch';
    public const WEBHOOK_MARKED_PAID = 'marked_paid';

    /**
     * @param PaymentProviderInterface    $provider           The active provider
     * @param PaymentMemberStoreInterface $store              Member data of this organisation
     * @param int                         $orgId              The organisation this service acts for
     * @param DateTimeZone                $timezone           Organisation timezone, for the paid date
     * @param string                      $defaultDescription Used when a member has no purpose text
     * @param bool                        $allowHigherAmount  True if members may pay more than their fee
     */
    public function __construct(
        private readonly PaymentProviderInterface $provider,
        private readonly PaymentMemberStoreInterface $store,
        private readonly int $orgId,
        private readonly DateTimeZone $timezone,
        private readonly string $defaultDescription,
        private readonly bool $allowHigherAmount = false
    ) {
    }

    /**
     * Formats a fee as providers expect it: two decimals, dot separator, no thousands separator
     * @param float $fee
     * @return string e.g. "25.00"
     */
    public static function formatAmount(float $fee): string
    {
        return number_format($fee, 2, '.', '');
    }

    /**
     * Converts an amount string with two decimals into cents, so amounts are compared without float noise
     * @param string $amount e.g. "25.00"
     * @return int e.g. 2500
     */
    private static function toCents(string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * Returns the member's pay token, creating and storing one first if there is none
     * @param string $userUuid
     * @throws PaymentException if the member doesn't exist
     */
    public function getOrCreateToken(string $userUuid): string
    {
        $member = $this->store->findMember($userUuid);

        if ($member === null) {
            throw new PaymentException('Unknown member');
        }

        if ($member->token !== '') {
            return $member->token;
        }

        $token = self::newToken();
        $this->store->saveToken($userUuid, $token);

        return $token;
    }

    /**
     * Checks a pay link
     * @param string $userUuid
     * @param string $token Token from the pay link
     * @return PaymentMember|null The member if the link is valid, otherwise null
     */
    public function checkPayLink(string $userUuid, string $token): ?PaymentMember
    {
        $member = $this->store->findMember($userUuid);

        // an empty stored token must never match an empty token from the link
        if ($member === null || $member->token === '' || !hash_equals($member->token, $token)) {
            return null;
        }

        return $member;
    }

    /**
     * Creates a payment for the member's current open fee
     * @param string $userUuid
     * @param string $token       Token from the pay link
     * @param string $redirectUrl Where the payer returns to after checkout
     * @param string $webhookUrl  Where the provider reports status changes
     * @param float|null $amount  Amount chosen by the member, null to pay exactly the fee
     * @return PaymentResult|null The payment to redirect to, or null if there is nothing to pay
     * @throws InvalidPayLinkException if member or token don't match
     * @throws InvalidAmountException if the amount is below the fee, or above it while that isn't allowed
     * @throws PaymentException if the provider fails
     */
    public function startPayment(string $userUuid, string $token, string $redirectUrl, string $webhookUrl, ?float $amount = null): ?PaymentResult
    {
        $member = $this->checkPayLink($userUuid, $token);

        if ($member === null) {
            throw new InvalidPayLinkException('Invalid pay link');
        }

        if ($member->paid || $member->fee <= 0) {
            return null;
        }

        $fee = self::formatAmount($member->fee);
        $payAmount = $amount === null ? $fee : self::formatAmount($amount);

        if (self::toCents($payAmount) < self::toCents($fee)) {
            throw new InvalidAmountException('Amount below the fee');
        }
        if (self::toCents($payAmount) > self::toCents($fee) && !$this->allowHigherAmount) {
            throw new InvalidAmountException('Paying more than the fee is not allowed');
        }

        return $this->provider->createPayment(
            $payAmount,
            self::CURRENCY,
            $member->purpose !== '' ? $member->purpose : $this->defaultDescription,
            $redirectUrl,
            $webhookUrl,
            array(
                'org_id'    => (string) $this->orgId,
                'user_uuid' => $member->userUuid,
                'fee'       => $fee
            )
        );
    }

    /**
     * Handles a provider webhook call; acts only on the payment as fetched from the provider
     * @param array<string, mixed>  $post    Parsed POST parameters
     * @param array<string, string> $headers Request headers
     * @param string                $rawBody Unparsed request body
     * @return string One of the WEBHOOK_* constants
     */
    public function handleWebhook(array $post, array $headers, string $rawBody): string
    {
        $paymentId = $this->provider->extractWebhookPaymentId($post, $headers, $rawBody);

        if ($paymentId === null) {
            return self::WEBHOOK_IGNORED;
        }

        try {
            $payment = $this->provider->getPayment($paymentId);
        } catch (PaymentUnavailableException $e) {
            return self::WEBHOOK_PROVIDER_UNAVAILABLE;
        } catch (PaymentException $e) {
            return self::WEBHOOK_UNKNOWN_PAYMENT;
        }

        if (!$payment->isPaid()) {
            return self::WEBHOOK_NOT_PAID;
        }

        if (($payment->metadata['org_id'] ?? null) !== (string) $this->orgId) {
            return self::WEBHOOK_NOT_OURS;
        }

        $member = $this->store->findMember((string) ($payment->metadata['user_uuid'] ?? ''));

        if ($member === null) {
            return self::WEBHOOK_NOT_OURS;
        }

        if ($member->paid) {
            return self::WEBHOOK_ALREADY_PAID;
        }

        // the fee may have been recalculated after checkout; an admin has to decide then.
        // a higher amount is fine even if paying more was switched off since: we created this payment
        $checkoutFee = (string) ($payment->metadata['fee'] ?? '');
        if ($payment->currency !== self::CURRENCY
            || $checkoutFee !== self::formatAmount($member->fee)
            || self::toCents($payment->amount) < self::toCents($checkoutFee)) {
            return self::WEBHOOK_AMOUNT_MISMATCH;
        }

        $paidAt = $payment->paidAt ?? new DateTimeImmutable();

        $this->store->markPaid(
            $member->userUuid,
            $paidAt->setTimezone($this->timezone)->format('Y-m-d'),
            $payment->id,
            $this->provider->getName(),
            self::newToken(),
            $payment->amount
        );

        return self::WEBHOOK_MARKED_PAID;
    }

    /**
     * Generates an unguessable pay token
     * @return string 64 hex characters
     */
    private static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
