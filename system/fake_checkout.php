<?php
/**
 ***********************************************************************************************
 * Checkout page of the fake payment provider: DEVELOPMENT ONLY
 *
 * Lets a developer decide what the "provider" does with a payment (pay, fail, cancel, expire,
 * pending), optionally runs the webhook logic, then sends the payer back like a real provider.
 * Refuses to work unless MEMBERSHIPFEE_PAYMENT_FAKE=1 and the fake provider is active.
 * Texts are English only on purpose: members never see this page.
 * See docs/online-payments.md, section Trying it out with the fake provider.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * payment      : id of the fake payment
 * status       : (POST) paid, failed, canceled, expired or pending
 * send_webhook : (POST) run the webhook logic right away
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentResult;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/common_function.php');

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    if (!isOnlinePaymentActive($pPreferences) || $pPreferences->config['payment']['provider'] !== 'fake') {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getPaymentId = admFuncVariableIsValid($_GET, 'payment', 'string', array('requireValue' => true));
    $provider = getPaymentProviderFactory()->create('fake');

    try {
        $payment = $provider->getPayment($getPaymentId);
    } catch (PaymentException $e) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    $choices = array(
        PaymentResult::STATUS_PAID     => array('Pay', 'btn-success', 'successful payment'),
        PaymentResult::STATUS_FAILED   => array('Fail', 'btn-danger', 'declined card or bank'),
        PaymentResult::STATUS_CANCELED => array('Cancel', 'btn-secondary', 'payer aborts at checkout'),
        PaymentResult::STATUS_EXPIRED  => array('Expire', 'btn-secondary', 'payer left the checkout open too long'),
        PaymentResult::STATUS_PENDING  => array('Pending', 'btn-warning', 'bank has not confirmed yet; open this page again later to pay')
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrfToken();

        $postStatus = admFuncVariableIsValid($_POST, 'status', 'string', array(
            'requireValue' => true,
            'validValues' => array_keys($choices)
        ));

        if ($payment->checkoutUrl === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        $provider->setStatus($getPaymentId, $postStatus);

        if (isset($_POST['send_webhook'])) {
            // in-process instead of an HTTP call: inside Docker the public URL often isn't reachable
            $outcome = createPaymentService($pPreferences)->handleWebhook(array('id' => $getPaymentId), array(), '');
            logPaymentWebhookOutcome($outcome, $getPaymentId);
        }

        header('Location: ' . $provider->getRedirectUrl($getPaymentId), true, 303);
        exit();
    }

    $headline = 'Fake payment provider';
    $page = new HtmlPage('plg-mitgliedsbeitrag-fake-checkout', $headline);

    $html = '<div class="alert alert-warning">Development only. No money is moved. This page simulates the payment provider\'s checkout.</div>'
        . '<dl class="row">'
        . '<dt class="col-sm-3">Payment</dt><dd class="col-sm-9"><code>' . SecurityUtils::encodeHTML($payment->id) . '</code></dd>'
        . '<dt class="col-sm-3">Amount</dt><dd class="col-sm-9">' . SecurityUtils::encodeHTML($payment->amount . ' ' . $payment->currency) . '</dd>'
        . '<dt class="col-sm-3">Purpose</dt><dd class="col-sm-9">' . SecurityUtils::encodeHTML($payment->description) . '</dd>'
        . '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">' . SecurityUtils::encodeHTML($payment->status) . '</dd>'
        . '</dl>';

    if ($payment->checkoutUrl === null) {
        $html .= '<p>This payment is finished and can no longer be changed.</p>'
            . '<a class="btn btn-primary" href="' . SecurityUtils::encodeHTML($provider->getRedirectUrl($getPaymentId)) . '">Back to the return page</a>';
    } else {
        $html .= '<form method="post" action="' . SecurityUtils::encodeHTML(CURRENT_URL) . '">'
            . '<input type="hidden" name="adm_csrf_token" value="' . SecurityUtils::encodeHTML($gCurrentSession->getCsrfToken()) . '" />'
            . '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="send_webhook" id="send_webhook" value="1" checked="checked" />'
            . '<label class="form-check-label" for="send_webhook">Send webhook (untick to simulate a late or lost webhook)</label></div>';
        foreach ($choices as $status => $choice) {
            $html .= '<p><button type="submit" name="status" value="' . $status . '" class="btn ' . $choice[1] . '" style="min-width: 8em">' . $choice[0] . '</button> '
                . '<span class="text-muted">' . $choice[2] . '</span></p>';
        }
        $html .= '</form>';
    }

    $page->addHtml($html);
    $page->show();
} catch (Exception|PaymentException $e) {
    $gMessage->show($e->getMessage());
}
