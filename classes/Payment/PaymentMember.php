<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentMember
 * @brief The payment-relevant data of one member in one organisation
 */

namespace Plugins\MembershipFee\classes\Payment;

final class PaymentMember
{
    /**
     * @param string $userUuid Admidio user uuid
     * @param float  $fee      Current fee FEE{org}, already normalised with normalize_fee()
     * @param bool   $paid     True if PAID{org} is set
     * @param string $token    Pay token PAYTOKEN{org}, '' if none was created yet
     * @param string $purpose  Purpose text CONTRIBUTORY_TEXT{org}, may be ''
     */
    public function __construct(
        public readonly string $userUuid,
        public readonly float $fee,
        public readonly bool $paid,
        public readonly string $token,
        public readonly string $purpose
    ) {
    }
}
