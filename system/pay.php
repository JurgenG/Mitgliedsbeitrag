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
 * mode     : pay    - (default) create a payment and redirect to the provider's checkout;
 *                     if paying more is allowed, GET shows the amount form and its POST pays
 *            return - page the payer returns to after checkout
 * payment_amount : (POST) amount chosen by the member, at least the fee
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\InvalidAmountException;
use Plugins\MembershipFee\classes\Payment\InvalidPayLinkException;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentService;

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

    // paying more than the fee: the member first chooses the amount in a form (GET), its POST starts the payment
    $amount = null;
    if ($pPreferences->config['payment']['allow_higher_amount'] ?? 0) {
        $member = $paymentService->checkPayLink($getUser, $getToken);

        if ($member === null) {
            $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK_INVALID'), $headline);
            // => EXIT
        }
        if ($member->paid || $member->fee <= 0) {
            $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_NOTHING_TO_PAY'), $headline);
            // => EXIT
        }

        $showAmountForm = function (string $error) use ($member, $headline, $getUser, $getToken): void {
            global $gL10n, $gSettingsManager;

            $minimum = PaymentService::formatAmount($member->fee);
            $minimumText = $minimum . ' ' . $gSettingsManager->getString('system_currency');

            $page = new HtmlPage('plg-mitgliedsbeitrag-pay', $headline);
            $form = new HtmlForm('pay_form', getPayLink($getUser, $getToken), $page);
            if ($error !== '') {
                $form->addDescription('<div class="alert alert-danger">' . $error . '</div>');
            }
            if ($member->purpose !== '') {
                $form->addStaticControl('pay_purpose', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT'), SecurityUtils::encodeHTML($member->purpose));
            }
            $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_AMOUNT_DESC', array($minimumText)));
            $form->addInput('payment_amount', $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_AMOUNT'), $minimum, array(
                'type' => 'number',
                'minNumber' => (float) $minimum,
                'step' => 0.01,
                'property' => HtmlForm::FIELD_REQUIRED
            ));
            $form->addSubmitButton('btn_pay', $gL10n->get('PLG_MEMBERSHIPFEE_PAY_NOW'), array(
                'icon' => 'bi-credit-card',
                'class' => 'offset-sm-3'
            ));
            $page->addHtml($form->show());
            $page->show();
            exit();
        };

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $showAmountForm('');
        }
        checkCsrfToken();
        $amount = normalize_fee(admFuncVariableIsValid($_POST, 'payment_amount', 'string', array('defaultValue' => '')));
    }

    try {
        $payment = $paymentService->startPayment(
            $getUser,
            $getToken,
            getPayLink($getUser, $getToken, 'return'),
            getPaymentWebhookUrl($pPreferences->config['payment']['provider']),
            $amount
        );
    } catch (InvalidAmountException $e) {
        $showAmountForm($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_AMOUNT_TOO_LOW', array(
            PaymentService::formatAmount($member->fee) . ' ' . $gSettingsManager->getString('system_currency')
        )));
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
