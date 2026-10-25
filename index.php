<?php
/**
 ***********************************************************************************************
 * Mitgliedsbeitrag / Membership fee
 *
 * Version 6.0.3
 *
 * This plugin calculates membership fees based on role assignments.
 *
 * Author: rmb
 *
 * Compatible with Admidio version 5
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Exception;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../system/common.php');
    require_once (__DIR__ . '/../../system/login_valid.php');
    require_once (__DIR__ . '/system/common_function.php');

    // only authorized user are allowed to start this module
    if (! isUserAuthorized()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $gNavigation->addStartUrl(CURRENT_URL);

    $pPreferences = new ConfigTable();
    $checked = $pPreferences->checkforupdate();

    if ($checked === 1) {
        // Only update the configuration table (configuration data exist, but the version differs from Version.php)
        $pPreferences->init();
    } elseif ($checked === 2) {
        // Run the detailed installation routine (at least one profile field is missing)
        $urlInst = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/install_db.php';
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_INSTALL_DETAILED_DESC', array(
            '<a href="' . $urlInst . '">' . $urlInst . '</a>'
        )), $gL10n->get('PLG_MEMBERSHIPFEE_ATTENTION'));
    }

    $pPreferences->read();
    // check whether role_id and/or item_id are stored (important for an uninstall; a previous uninstall may have failed)
    if ($pPreferences->config['install']['access_role_id'] == 0 || $pPreferences->config['install']['menu_item_id'] == 0) {
        $urlInst = ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/install.php';
        $gMessage->show($gL10n->get('PLG_MEMBERSHIPFEE_INSTALL_UPDATE_REQUIRED', array(
            '<a href="' . $urlInst . '">' . $urlInst . '</a>'
        )));
    }

    admRedirect(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php');
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}

