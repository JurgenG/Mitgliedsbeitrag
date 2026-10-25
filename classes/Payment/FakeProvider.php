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
 * @brief Payment provider for development and tests; no network, no API key
 *
 * Status changes that a real provider would make (payer pays, payment expires, ...)
 * are simulated with setStatus(). Without a storage file the payments live in memory (unit tests);
 * with one they survive between requests, so the flow can be tried out in a local Admidio.
 */

namespace Plugins\MembershipFee\classes\Payment;

use DateTimeImmutable;
use DateTimeInterface;

class FakeProvider implements PaymentProviderInterface
{
    /** @var array<string, PaymentResult> */
    private array $payments = array();

    /** @var array<string, string> */
    private array $redirectUrls = array();

    private bool $unavailable = false;

    /**
     * @param string|null $storageFile JSON file to keep the payments in, null to keep them in memory
     * @param string      $checkoutUrl Checkout page; the payment id is appended as parameter "payment"
     */
    public function __construct(
        private readonly ?string $storageFile = null,
        private readonly string $checkoutUrl = 'https://fake.invalid/checkout'
    ) {
    }

    public function getName(): string
    {
        return 'fake';
    }

    /**
     * Like Mollie: the webhook only carries the payment id as POST parameter "id"
     */
    public function extractWebhookPaymentId(array $post, array $headers, string $rawBody): ?string
    {
        $id = $post['id'] ?? null;

        if (!is_string($id) || !preg_match('/^tr_[A-Za-z0-9]+$/', $id)) {
            return null;
        }

        return $id;
    }

    public function createPayment(
        string $amount,
        string $currency,
        string $description,
        string $redirectUrl,
        string $webhookUrl,
        array $metadata
    ): PaymentResult {
        PaymentResult::assertValidAmount($amount);

        $this->load();
        $id = 'tr_fake' . bin2hex(random_bytes(6));
        $separator = str_contains($this->checkoutUrl, '?') ? '&' : '?';
        $this->payments[$id] = new PaymentResult(
            $id,
            PaymentResult::STATUS_OPEN,
            $amount,
            $currency,
            $description,
            $this->checkoutUrl . $separator . 'payment=' . $id,
            null,
            $metadata
        );
        $this->redirectUrls[$id] = $redirectUrl;
        $this->store();

        return $this->payments[$id];
    }

    public function getPayment(string $paymentId): PaymentResult
    {
        if ($this->unavailable) {
            throw new PaymentUnavailableException('Fake provider: unavailable');
        }

        $this->load();

        if (!isset($this->payments[$paymentId])) {
            throw new PaymentException('Unknown payment: ' . $paymentId);
        }

        return $this->payments[$paymentId];
    }

    /**
     * Simulates a provider that can't be reached: getPayment() then throws PaymentUnavailableException
     * @param bool $unavailable
     */
    public function setUnavailable(bool $unavailable): void
    {
        $this->unavailable = $unavailable;
    }

    /**
     * Returns where the payer goes after checkout, for the fake checkout page
     * @param string $paymentId
     * @throws PaymentException if the payment doesn't exist
     */
    public function getRedirectUrl(string $paymentId): string
    {
        $this->getPayment($paymentId);

        return $this->redirectUrls[$paymentId];
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
        $this->store();
    }

    /**
     * Reads the payments from the storage file, if there is one
     * @return void
     */
    private function load(): void
    {
        if ($this->storageFile === null || !is_file($this->storageFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($this->storageFile), true);

        foreach (is_array($data) ? $data : array() as $id => $row) {
            $this->payments[$id] = new PaymentResult(
                $id,
                $row['status'],
                $row['amount'],
                $row['currency'],
                $row['description'],
                $row['checkoutUrl'],
                $row['paidAt'] !== null ? new DateTimeImmutable($row['paidAt']) : null,
                $row['metadata']
            );
            $this->redirectUrls[$id] = $row['redirectUrl'];
        }
    }

    /**
     * Writes all payments to the storage file, if there is one
     * @return void
     */
    private function store(): void
    {
        if ($this->storageFile === null) {
            return;
        }

        $data = array();
        foreach ($this->payments as $id => $payment) {
            $data[$id] = array(
                'status'      => $payment->status,
                'amount'      => $payment->amount,
                'currency'    => $payment->currency,
                'description' => $payment->description,
                'checkoutUrl' => $payment->checkoutUrl,
                'paidAt'      => $payment->paidAt?->format(DateTimeInterface::ATOM),
                'metadata'    => $payment->metadata,
                'redirectUrl' => $this->redirectUrls[$id]
            );
        }

        if (file_put_contents($this->storageFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new PaymentException('Fake provider: cannot write ' . $this->storageFile);
        }
    }
}
