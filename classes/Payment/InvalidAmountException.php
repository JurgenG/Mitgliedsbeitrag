<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class InvalidAmountException
 * @brief The amount chosen on the pay page is below the fee, or above it while paying more isn't allowed
 */

namespace Plugins\MembershipFee\classes\Payment;

class InvalidAmountException extends PaymentException
{
}
