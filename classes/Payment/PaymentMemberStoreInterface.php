<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @interface PaymentMemberStoreInterface
 * @brief Reads and writes the payment data of members of one organisation
 *
 * Keeps PaymentService independent of Admidio; production code implements it with User entities.
 */

namespace Plugins\MembershipFee\classes\Payment;

interface PaymentMemberStoreInterface
{
    /**
     * Returns the member's payment data, or null if there is no such member
     * @param string $userUuid
     */
    public function findMember(string $userUuid): ?PaymentMember;

    /**
     * Stores a new pay token for the member
     * @param string $userUuid
     * @param string $token
     */
    public function saveToken(string $userUuid, string $token): void;

    /**
     * Marks the member's fee as paid and stores the payment, all in one save
     * @param string $userUuid
     * @param string $paidDate  Date in the organisation's timezone, Y-m-d
     * @param string $paymentId Provider's payment id
     * @param string $provider  Provider name, see PaymentProviderInterface::getName()
     * @param string $newToken   Replaces the pay token so the old link stops working
     * @param string $paidAmount Amount paid, two decimals; becomes the member's fee FEE{org}
     */
    public function markPaid(string $userUuid, string $paidDate, string $paymentId, string $provider, string $newToken, string $paidAmount): void;
}
