<?php
/**
 ***********************************************************************************************
 * Membership fee overview
 *
 * Plugin shows the logged-in member's own membership status: paid until, fee, payment deadline
 * and a "Pay now" button for an open fee (see docs/membership-status.md)
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Usage:
 *
 * To display an overview, the following lines must be inserted into "themes/simple/templates/system/overview.tpl"
 * (preferably in a child theme, see docs/membership-status.md):
 *
 * <div class="admidio-overview-plugin col-sm-6 col-lg-4 col-xl-3" id="admidio-plugin-membership-fee">
 *    <div class="card admidio-card">
 *        <div class="card-body">
 *            {load_admidio_plugin plugin="MembershipFee" file="system/membership_fee_overview.php"}
 *        </div>
 *    </div>
 * </div>
 *
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Service\MembershipStatus;

// Admidio requires this file inside a method: $gTimezone is not among the globals it declares
global $gTimezone;

$rootPath = dirname(__DIR__, 3);
$pluginFolder = basename(__DIR__);

require_once($rootPath . '/system/common.php');
require_once(__DIR__ . '/common_function.php');

echo '<div id="plugin_'. $pluginFolder. '" class="admidio-plugin-content">';

echo '<h3>'.$gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE').'</h3>';

if ($gValidLogin)
{
    $user = new User($gDb, $gProfileFields, $gCurrentUserId);

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    // DATE fields hold Y-m-d in the database
    $toDate = function (string $value): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date === false ? null : $date;
    };
    $dateFormat = $gSettingsManager->getString('system_date');

    $membershipStatus = new MembershipStatus(
        (int) ($pPreferences->config['membership_status']['period_end_day'] ?? 31),
        (int) ($pPreferences->config['membership_status']['period_end_month'] ?? 12),
        (int) ($pPreferences->config['membership_status']['warning_days'] ?? 30)
    );
    $status = $membershipStatus->evaluate(
        normalize_fee((string) $user->getValue('FEE'.$gCurrentOrgId, 'database')),
        $toDate((string) $user->getValue('PAID'.$gCurrentOrgId, 'database')),
        $toDate((string) $user->getValue('DUEDATE'.$gCurrentOrgId, 'database')),
        new DateTimeImmutable('today', new DateTimeZone($gTimezone))
    );

    if ($status['state'] === MembershipStatus::NO_FEE)
    {
        echo $gL10n->get('PLG_MEMBERSHIPFEE_OVERVIEW_NO_DATA', array($user->getValue('FIRST_NAME'), $user->getValue('LAST_NAME')));
    }
    else
    {
        // alert class and the date shown in the status text, per state
        $statusDisplay = array(
            MembershipStatus::PAID     => array('success', $status['paidUntil']),
            MembershipStatus::ENDING   => array('warning', $status['paidUntil']),
            MembershipStatus::EXPIRED  => array('secondary', $status['paidUntil']),
            MembershipStatus::OPEN     => array('info', $status['deadline']),
            MembershipStatus::DUE_SOON => array('warning', $status['deadline']),
            MembershipStatus::OVERDUE  => array('danger', $status['deadline'])
        );
        list($alertClass, $statusDate) = $statusDisplay[$status['state']];

        echo '<div class="alert alert-' . $alertClass . '" id="plg_membership_fee_overview_status">'
            . $gL10n->get('PLG_MEMBERSHIPFEE_STATUS_' . strtoupper($status['state']), array($statusDate->format($dateFormat)))
            . '</div>';

        // create a static form
        $form = new HtmlForm('plugin-membership_fee-static-form', '#', null, array('type' => 'vertical', 'setFocus' => false));

        if (!empty($user->getValue('FEE'.$gCurrentOrgId)))
        {
            $form->addStaticControl('plg_membership_fee_overview_fee', $gL10n->get('PLG_MEMBERSHIPFEE_FEE'), $user->getValue('FEE'.$gCurrentOrgId).' '.$gSettingsManager->getString('system_currency'));
        }
        if (!empty($user->getValue('CONTRIBUTORY_TEXT'.$gCurrentOrgId)))
        {
            $form->addStaticControl('plg_membership_fee_overview_contributory_text', $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT'), $user->getValue('CONTRIBUTORY_TEXT'.$gCurrentOrgId));
        }
        if (!empty($user->getValue('PAID'.$gCurrentOrgId)))
        {
            $form->addStaticControl('plg_membership_fee_overview_paid', $gL10n->get('PLG_MEMBERSHIPFEE_PAID_ON'), $user->getValue('PAID'.$gCurrentOrgId));
        }
        if (!empty($user->getValue('DUEDATE'.$gCurrentOrgId)))
        {
            $form->addStaticControl('plg_membership_fee_overview_duedate', $gL10n->get('PLG_MEMBERSHIPFEE_DUEDATE'), $user->getValue('DUEDATE'.$gCurrentOrgId));
        }
        echo $form->show();

        if (in_array($status['state'], MembershipStatus::PAYABLE_STATES, true) && isOnlinePaymentActive($pPreferences))
        {
            // a failing provider setup must never break the start page: then there is simply no button
            try
            {
                $userUuid = $user->getValue('usr_uuid');
                $payLink = getPayLink($userUuid, createPaymentService($pPreferences)->getOrCreateToken($userUuid));

                echo '<a class="btn btn-primary" id="plg_membership_fee_overview_pay" href="' . SecurityUtils::encodeHTML($payLink) . '">'
                    . '<i class="bi bi-credit-card"></i> ' . $gL10n->get('PLG_MEMBERSHIPFEE_PAY_NOW') . '</a>';
            }
            catch (PaymentException $e)
            {
                $gLogger->warning('MembershipFee: no pay link on the start page: ' . $e->getMessage());
            }
        }
    }
}
else
{
    echo $gL10n->get('PLG_MEMBERSHIPFEE_OVERVIEW_NOVALIDLOGIN');
}

echo '</div>';
