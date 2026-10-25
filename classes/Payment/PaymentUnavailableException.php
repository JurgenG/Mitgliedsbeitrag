<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentUnavailableException
 * @brief The provider can't be reached or answers with a temporary error; try again later
 */

namespace Plugins\MembershipFee\classes\Payment;

class PaymentUnavailableException extends PaymentException
{
}
