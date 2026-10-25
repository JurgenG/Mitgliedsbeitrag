<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class FakeProvider
 * @brief In-memory payment provider for development and tests; no network, no API key
 *
 * Status changes that a real provider would make (payer pays, payment expires, ...)
 * are simulated with setStatus().
 */

namespace Plugins\MembershipFee\classes\Payment;

use DateTimeImmutable;

class FakeProvider implements PaymentProviderInterface
{
    /** @var array<string, PaymentResult> */
    private array $payments = array();

    public function createPayment(
        string $amount,
        string $currency,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        array $metadata
    ): PaymentResult {
        PaymentResult::assertValidAmount($amount);

        $id = 'tr_fake' . bin2hex(random_bytes(6));
        $this->payments[$id] = new PaymentResult(
            $id,
            PaymentResult::STATUS_OPEN,
            $amount,
            $currency,
            $description,
            'https://fake.invalid/checkout/' . $id,
            null,
            $metadata
        );

        return $this->payments[$id];
    }

    public function getPayment(string $paymentId): PaymentResult
    {
        if (!isset($this->payments[$paymentId])) {
            throw new PaymentException('Unknown payment: ' . $paymentId);
        }

        return $this->payments[$paymentId];
    }

    /**
     * Simulates a status change made by the provider
     * @param string                 $paymentId
     * @param string                 $status One of the PaymentResult::STATUS_* constants
     * @param DateTimeImmutable|null $paidAt Defaults to now when the status is paid
     * @throws PaymentException
     */
    public function setStatus(string $paymentId, string $status, ?DateTimeImmutable $paidAt = null): void
    {
        $payment = $this->getPayment($paymentId);
        $payable = in_array($status, array(PaymentResult::STATUS_OPEN, PaymentResult::STATUS_PENDING), true);

        if ($status === PaymentResult::STATUS_PAID && $paidAt === null) {
            $paidAt = new DateTimeImmutable();
        }

        $this->payments[$paymentId] = new PaymentResult(
            $payment->id,
            $status,
            $payment->amount,
            $payment->currency,
            $payment->description,
            $payable ? $payment->checkoutUrl : null,
            $status === PaymentResult::STATUS_PAID ? $paidAt : null,
            $payment->metadata
        );
    }
}
