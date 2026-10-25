<?php
/**
 ***********************************************************************************************
 * Public pay page: a member's personal link starts an online payment of the open fee
 *
 * No login: members pay without an Admidio account, access is the unguessable token.
 * See docs/online-payments.md.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * org      : id of the organisation the link belongs to
 * user     : uuid of the member
 * token    : pay token of the member
 * mode     : pay    - (default) create a payment and redirect to the provider's checkout
 *            return - page the payer returns to after checkout
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\InvalidPayLinkException;
use Plugins\MembershipFee\classes\Payment\PaymentException;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/common_function.php');

    $getOrg = admFuncVariableIsValid($_GET, 'org', 'int', array('requireValue' => true));
    $getUser = admFuncVariableIsValid($_GET, 'user', 'uuid', array('requireValue' => true));
    $getToken = admFuncVariableIsValid($_GET, 'token', 'string', array('defaultValue' => ''));
    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'pay',
        'validValues' => array('pay', 'return')
    ));

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE');

    if ((int) $getOrg !== (int) $gCurrentOrgId || !isOnlinePaymentActive($pPreferences)) {
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_DISABLED'), $headline);
        // => EXIT
    }

    $paymentService = createPaymentService($pPreferences);

    if ($getMode === 'return') {
        $member = $paymentService->checkPayLink($getUser, $getToken);

        if ($member === null) {
            // the token changes when a payment is registered, so this is the normal case after paying
            $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK_INVALID'), $headline);
        } elseif ($member->paid || $member->fee <= 0) {
            $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_NOTHING_TO_PAY'), $headline);
        } else {
            $gMessage->setForwardUrl(getPayLink($getUser, $getToken));
            $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_NOT_CONFIRMED'), $headline);
        }
        // => EXIT
    }

    try {
        $payment = $paymentService->startPayment(
            $getUser,
            $getToken,
            getPayLink($getUser, $getToken, 'return'),
            getPaymentWebhookUrl($pPreferences->config['payment']['provider'])
        );
    } catch (InvalidPayLinkException $e) {
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK_INVALID'), $headline);
        // => EXIT
    } catch (PaymentException $e) {
        // provider messages never contain the API key, see PaymentException
        $gLogger->error('MembershipFee: payment could not be started: ' . $e->getMessage());
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_PROVIDER_ERROR'), $headline);
        // => EXIT
    }

    if ($payment === null) {
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_NOTHING_TO_PAY'), $headline);
        // => EXIT
    }

    // not admRedirect(): it routes external URLs through an interstitial page
    header('Location: ' . $payment->checkoutUrl, true, 303);
    exit();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
