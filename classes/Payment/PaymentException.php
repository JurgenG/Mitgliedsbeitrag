<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class PaymentException
 * @brief Error raised by a payment provider (invalid request, unknown payment, API failure)
 *
 * Deliberately independent of Admidio so the payment classes can be unit tested.
 * Messages are for logs/admins and must never contain API keys.
 */

namespace Plugins\MembershipFee\classes\Payment;

class PaymentException extends \RuntimeException
{
}
