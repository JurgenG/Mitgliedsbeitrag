<?php
/**
 ***********************************************************************************************
 * Public webhook: the payment provider reports a status change of a payment
 *
 * No login, no isUserAuthorized() and no CSRF check: the documented exception to the plugin's rules.
 * Safe because only the payment as fetched from the provider is trusted. Always answers 200.
 * See docs/online-payments.md, section Security.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * provider : name of the provider that calls (must be the active provider)
 * org      : id of the organisation whose credentials are used to fetch the payment
 ***********************************************************************************************
 */
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\PaymentService;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

$outcome = PaymentService::WEBHOOK_IGNORED;

try {
    $getProvider = admFuncVariableIsValid($_GET, 'provider', 'string', array('defaultValue' => ''));
    $getOrg = admFuncVariableIsValid($_GET, 'org', 'int', array('defaultValue' => 0));

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    if ((int) $getOrg === (int) $gCurrentOrgId
        && isOnlinePaymentActive($pPreferences)
        && $getProvider === $pPreferences->config['payment']['provider']) {
        $outcome = createPaymentService($pPreferences)->handleWebhook(
            $_POST,
            function_exists('getallheaders') ? getallheaders() : array(),
            (string) file_get_contents('php://input')
        );
    }
} catch (Throwable $e) {
    $gLogger->error('MembershipFee: payment webhook failed: ' . $e->getMessage());
}

logPaymentWebhookOutcome($outcome, (string) ($_POST['id'] ?? ''));

http_response_code(200);
echo 'OK';
