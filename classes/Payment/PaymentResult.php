<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentResult
 * @brief Provider-independent snapshot of a payment as reported by the payment provider
 */

namespace Plugins\MembershipFee\classes\Payment;

use DateTimeImmutable;

final class PaymentResult
{
    public const STATUS_OPEN = 'open';
    public const STATUS_PENDING = 'pending';
    public const STATUS_AUTHORIZED = 'authorized';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = array(
        self::STATUS_OPEN,
        self::STATUS_PENDING,
        self::STATUS_AUTHORIZED,
        self::STATUS_PAID,
        self::STATUS_CANCELED,
        self::STATUS_EXPIRED,
        self::STATUS_FAILED
    );

    /**
     * @param string                $id          Provider's payment id, e.g. tr_xxxx
     * @param string                $status      One of the STATUS_* constants
     * @param string                $amount      Amount with two decimals, e.g. "25.00"
     * @param string                $currency    ISO 4217 code, e.g. "EUR"
     * @param string                $description Purpose shown to the payer
     * @param string|null           $checkoutUrl URL the payer is sent to; null once the payment is no longer payable
     * @param DateTimeImmutable|null $paidAt     Moment the payment was paid, null if not paid
     * @param array<string, string> $metadata    Data we attached when creating the payment
     */
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $description,
        public readonly ?string $checkoutUrl,
        public readonly ?DateTimeImmutable $paidAt,
        public readonly array $metadata
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new PaymentException('Unknown payment status: ' . $status);
        }
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Validates an amount string as the payment providers expect it: positive, two decimals, dot separator
     * @param string $amount
     * @throws PaymentException
     */
    public static function assertValidAmount(string $amount): void
    {
        if (!preg_match('/^\d+\.\d{2}$/', $amount) || (float) $amount <= 0) {
            throw new PaymentException('Invalid payment amount: ' . $amount);
        }
    }
}
