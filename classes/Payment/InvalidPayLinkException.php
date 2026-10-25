<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class InvalidPayLinkException
 * @brief The pay link names an unknown member or carries a wrong or outdated token
 */

namespace Plugins\MembershipFee\classes\Payment;

class InvalidPayLinkException extends PaymentException
{
}
