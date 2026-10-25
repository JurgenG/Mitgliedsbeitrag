<?php
/**
 ***********************************************************************************************
 * Installation routine for the Admidio plugin MembershipFee
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters:  none
 *
 ***********************************************************************************************
 */
use Admidio\Categories\Entity\Category;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Menu\Entity\MenuEntry;
use Admidio\Roles\Entity\Role;
use Admidio\Roles\Entity\RolesRights;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/common_function.php');

    // only administrators are allowed to start this module
    if (! $gCurrentUser->isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $gNavigation->addStartUrl(CURRENT_URL);

    // first step: check whether a role ("access role") exists (create or update it if necessary)

    // first determine the id (cat_id) of the category General
    $category = new Category($gDb);
    $category->readDataByColumns(array(
        'cat_org_id' => $gCurrentOrgId,
        'cat_type' => 'ROL',
        'cat_name_intern' => 'COMMON'
    ));
    $categoryCommonId = $category->getValue('cat_id');

    $role = new Role($gDb);

    // read an existing role, if any (reading via 'rol_name' and 'rol_description' only works if the user has not changed this data)
    $role->readDataByColumns(array(
        'rol_cat_id' => $categoryCommonId,
        'rol_name' => $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_TO_PLUGIN'),
        'rol_description' => $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_TO_PLUGIN_DESC')
    ));

    // only if there is no role, enter new data (more than one role is not considered)
    if ($role->getValue('rol_id') === 0) {
        $role->saveChangesWithoutRights(); // toDo: assign extended permissions to the role if necessary
        $role->setValue('rol_edit_user', 1);
        $role->setValue('rol_cat_id', $categoryCommonId, false);
        $role->setValue('rol_name', $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_TO_PLUGIN'));
        $role->setValue('rol_description', $gL10n->get('PLG_MEMBERSHIPFEE_ACCESS_TO_PLUGIN_DESC'));
        $role->save();
    }

    // add the current user to this role
    $role->startMembership((int) $gCurrentUser->getValue('usr_id'));
    $role->save();

    // second step: check whether a menu item exists and create it if necessary

    // first determine the id (men_id) of the menu level Extensions
    $menuParent = new MenuEntry($gDb);
    $menuParent->readDataByColumns(array(
        'men_name_intern' => 'extensions'
    ));
    $menIdParent = $menuParent->getValue('men_id');

    $menu = new MenuEntry($gDb);

    // read an existing menu item, if any
    $menu->readDataByColumns(array(
        'men_url' => FOLDER_PLUGINS . PLUGIN_FOLDER . '/index.php'
    ));

    // enter data for this menu item
    $menu->setValue('men_men_id_parent', $menIdParent);
    $menu->setValue('men_url', FOLDER_PLUGINS . PLUGIN_FOLDER . '/index.php');
    $menu->setValue('men_icon', 'currency-euro');
    $menu->setValue('men_name', 'PLG_MEMBERSHIPFEE_NAME');
    $menu->setValue('men_description', 'PLG_MEMBERSHIPFEE_NAME_DESC');
    $menu->save();

    // add the previously created role to this menu item ('Visible for')
    $rightMenuView = new RolesRights($gDb, 'menu_view', $menu->getValue('men_id'));
    $rightMenuView->addRoles(array(
        $role->getValue('rol_id')
    ));

    // so that the menu entries are updated on screen: reload all sessions
    $gCurrentSession->reloadAllSessions();

    // last step: process the configuration data and create profile fields and categories

    $pPreferences = new ConfigTable();
    $checked = $pPreferences->checkforupdate();

    $pPreferences->init();
    $pPreferences->config['install']['access_role_id'] = $role->getValue('rol_id'); // for the uninstall routine: store the ID of the access role in the configuration table
    $pPreferences->config['install']['menu_item_id'] = $menu->getValue('men_id'); // for the uninstall routine: store the ID of the menu item in the configuration table
    $pPreferences->save();

    if ($checked === 2) {
        // run a shortened installation routine and create profile fields and categories without a user interface
        admRedirect(SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/install_db.php', array(
            'mode' => 'anlegen'
        )));
    }

    admRedirect(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/membership_fee.php');
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}


