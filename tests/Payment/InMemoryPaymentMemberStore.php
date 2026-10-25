<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Payment;

use Plugins\MembershipFee\classes\Payment\PaymentMember;
use Plugins\MembershipFee\classes\Payment\PaymentMemberStoreInterface;

/**
 * Member store for tests; records every markPaid() call
 */
final class InMemoryPaymentMemberStore implements PaymentMemberStoreInterface
{
    /** @var array<string, PaymentMember> */
    private array $members = array();

    /** @var array<int, array{userUuid: string, paidDate: string, paymentId: string, provider: string, newToken: string}> */
    public array $markPaidCalls = array();

    public function put(PaymentMember $member): void
    {
        $this->members[$member->userUuid] = $member;
    }

    public function findMember(string $userUuid): ?PaymentMember
    {
        return $this->members[$userUuid] ?? null;
    }

    public function saveToken(string $userUuid, string $token): void
    {
        $member = $this->members[$userUuid];
        $this->put(new PaymentMember($member->userUuid, $member->fee, $member->paid, $token, $member->purpose));
    }

    public function markPaid(string $userUuid, string $paidDate, string $paymentId, string $provider, string $newToken): void
    {
        $this->markPaidCalls[] = array(
            'userUuid'  => $userUuid,
            'paidDate'  => $paidDate,
            'paymentId' => $paymentId,
            'provider'  => $provider,
            'newToken'  => $newToken
        );

        $member = $this->members[$userUuid];
        $this->put(new PaymentMember($member->userUuid, $member->fee, true, $newToken, $member->purpose));
    }
}
