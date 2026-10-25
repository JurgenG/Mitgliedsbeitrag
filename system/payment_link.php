<?php
/**
 ***********************************************************************************************
 * Shows a member's personal pay link (creates the pay token if the member has none yet)
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:
 *
 * user_uuid : uuid of the member
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/../../../system/login_valid.php');
    require_once (__DIR__ . '/common_function.php');

    if (!isUserAuthorized() || !isUserAuthorizedFor('payment_links')) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $getUserUuid = admFuncVariableIsValid($_GET, 'user_uuid', 'uuid', array('requireValue' => true));

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    if (!isOnlinePaymentActive($pPreferences)) {
        throw new Exception('PLG_MEMBERSHIPFEE_PAYMENT_DISABLED');
    }

    $user = new User($gDb, $gProfileFields);
    if (!$user->readDataByUuid($getUserUuid)) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    $payLink = getPayLink($getUserUuid, createPaymentService($pPreferences)->getOrCreateToken($getUserUuid));

    $headline = $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK');
    $gNavigation->addUrl(CURRENT_URL, $headline);
    $page = new HtmlPage('plg-mitgliedsbeitrag-payment-link', $headline);

    $form = new HtmlForm('payment_link_form', '', $page);
    $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK_DESC', array(
        $user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME')
    )));
    $form->addInput('payment_link', $gL10n->get('PLG_MEMBERSHIPFEE_PAYMENT_LINK'), $payLink, array(
        'property' => HtmlForm::FIELD_READONLY
    ));
    $form->addButton('btn_open_payment_link', $gL10n->get('PLG_MEMBERSHIPFEE_PAY_NOW'), array(
        'icon' => 'bi-box-arrow-up-right',
        'link' => $payLink,
        'class' => 'btn-primary offset-sm-3'
    ));

    $page->addHtml($form->show());
    $page->show();
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}
