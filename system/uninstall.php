<?php
/**
 ***********************************************************************************************
 * Uninstallation of the Admidio plugin MembershipFee
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 * Parameters: 
 * 
 * mode     : security_check - security check
 *            uninst - uninstallation procedure
 *
 ***********************************************************************************************
 */
use Admidio\Categories\Entity\Category;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Menu\Entity\MenuEntry;
use Admidio\ProfileFields\Entity\ProfileField;
use Admidio\Roles\Entity\Role;
use Admidio\UI\Presenter\FormPresenter;
use Admidio\UI\Presenter\PagePresenter;
use Plugins\MembershipFee\classes\Config\ConfigTable;

try {
    require_once (__DIR__ . '/../../../system/common.php');
    require_once (__DIR__ . '/common_function.php');

    // only authorized user are allowed to start this module
    if (! isUserAuthorizedForPreferences()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $pPreferences = new ConfigTable();
    $pPreferences->read();

    $result = $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_STARTMESSAGE');

    // Initialize and check the parameters
    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'security_check',
        'validValues' => array(
            'security_check',
            'uninst'
        )
    ));

    switch ($getMode) {
        case 'security_check':
            // confirmation prompt whether everything should really be deleted

            global $gL10n;

            $title = $gL10n->get('PLG_MEMBERSHIPFEE_UNINSTALLATION');
            $headline = $gL10n->get('PLG_MEMBERSHIPFEE_UNINSTALLATION');

            $gNavigation->addUrl(CURRENT_URL, $headline);

            $page = PagePresenter::withHtmlIDAndHeadline('plg-membershipfee-uninstall-html');
            $page->setTitle($title);
            $page->setHeadline($headline);

            $form = new FormPresenter('membershipfee_uninstall_form', '../templates/uninstall.plugin.membershipfee.tpl', '', $page, array(
                'type' => 'default',
                'method' => 'post',
                'setFocus' => false
            ));

            $form->addButton('btn_exit', $gL10n->get('SYS_YES'), array(
                'icon' => 'bi-check-square',
                'link' => SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/uninstall.php', array(
                    'mode' => 'uninst'
                )),
                'class' => 'btn-primary'
            ));

            $form->addButton('btn_continue', $gL10n->get('SYS_BACK'), array(
                'icon' => 'bi-backspace',
                'link' => $gNavigation->getPreviousUrl(),
                'class' => 'btn-primary'
            ));

            $form->addToHtmlPage(false);

            $page->show();
            break;

        case 'uninst':
            // confirmation was given, everything may be deleted

            // delete access role and menu item
            if ($pPreferences->config['install']['access_role_id'] == 0 || $pPreferences->config['install']['menu_item_id'] == 0) {
                // just to be safe; 'access_role_id' and/or 'menu_item_id' being 0 should actually never happen
                $result .= $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_NO_INST_IDS_FOUND');
            } else {
                $result_role = true;

                $role = new Role($gDb, (int) $pPreferences->config['install']['access_role_id']);

                $result_role = $role->delete();
                $result .= ($result_role ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_ACCESS_ROLE_SUCCESS') : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_ACCESS_ROLE_ERROR'));

                $result_menu = false;

                // read all access roles stored in the configuration table (across organizations)
                $access_roles_prefs = $pPreferences->getAllAccessRoles();

                // the menu item is only removed if the access role created during installation
                // is the only role still stored in the configuration table
                if (count($access_roles_prefs) === 1 && $access_roles_prefs[0] == $pPreferences->config['install']['access_role_id']) {
                    $menu = new MenuEntry($gDb, (int) $pPreferences->config['install']['menu_item_id']);
                    $result_menu = $menu->delete();
                    $result .= ($result_menu ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_MENU_ITEM_SUCCESS') : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_MENU_ITEM_ERROR'));
                } else {
                    // do not remove the menu item if it is still used by another organization
                    $result .= $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_MENU_ITEM_NOT_DELETED');
                }
            }

            // delete configuration data (current organization only)
            $result_data = false;
            $result_db = false;

            $sql = 'DELETE FROM ' . $pPreferences->getTableName() . '
        		          WHERE plp_name LIKE ?
        			        AND plp_org_id = ? ';

            $result_data = $gDb->queryPrepared($sql, array(
                $pPreferences->getShortcut() . '__%',
                $gCurrentOrgId
            ));

            // if the table only had entries of this plugin, it should now be empty and can be deleted
            $sql = 'SELECT * FROM ' . $pPreferences->getTableName() . ' ';

            $statement = $gDb->queryPrepared($sql);

            if ($statement->rowCount() == 0) {
                $sql = 'DROP TABLE ' . $pPreferences->getTableName() . ' ';
                $result_db = $gDb->queryPrepared($sql);
            }

            $result .= ($result_data ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_DATA_DELETE_SUCCESS') : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_DATA_DELETE_ERROR'));
            $result .= ($result_db ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_TABLE_DELETE_SUCCESS') : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_TABLE_DELETE_ERROR'));

            // delete profile fields

            // all profile fields created by Mitgliedsbeitrag
            $fieldArray = array();
            $fieldArray[] = 'ACCESSION' . $gCurrentOrgId;
            $fieldArray[] = 'BANK';
            $fieldArray[] = 'BIC';
            $fieldArray[] = 'CONTRIBUTORY_TEXT' . $gCurrentOrgId;
            $fieldArray[] = 'DEBTOR';
            $fieldArray[] = 'DEBTOR_CITY';
            $fieldArray[] = 'DEBTOR_EMAIL';
            $fieldArray[] = 'DEBTOR_POSTCODE';
            $fieldArray[] = 'DEBTOR_STREET';
            $fieldArray[] = 'DUEDATE' . $gCurrentOrgId;
            $fieldArray[] = 'FEE' . $gCurrentOrgId;
            $fieldArray[] = 'IBAN';
            $fieldArray[] = 'MANDATEDATE' . $gCurrentOrgId;
            $fieldArray[] = 'MANDATEID' . $gCurrentOrgId;
            $fieldArray[] = 'MEMBERNUMBER' . $gCurrentOrgId;
            $fieldArray[] = 'ORIG_DEBTOR_AGENT';
            $fieldArray[] = 'ORIG_IBAN';
            $fieldArray[] = 'ORIG_MANDATEID' . $gCurrentOrgId;
            $fieldArray[] = 'PAID' . $gCurrentOrgId;
            $fieldArray[] = 'PAYMENT_ID' . $gCurrentOrgId;
            $fieldArray[] = 'PAYMENT_PROVIDER' . $gCurrentOrgId;
            $fieldArray[] = 'PAYTOKEN' . $gCurrentOrgId;
            $fieldArray[] = 'SEQUENCETYPE' . $gCurrentOrgId;

            $profileField = new ProfileField($gDb);
            $category = new Category($gDb);

            foreach ($fieldArray as $fieldName) {
                $result_profileField = false;
                $catId = '';

                // the online payment fields only exist if online payments were ever enabled
                if (!$profileField->readDataByColumns(array(
                    'usf_name_intern' => $fieldName
                ))) {
                    continue;
                }

                $catId = $profileField->getValue('usf_cat_id');
                $category->readDataById((int) $catId);

                if ($category->getValue('cat_org_id') === $gCurrentOrgId) {
                    // the profile field is organization-specific and can therefore be deleted
                    $result_profileField = $profileField->delete();

                    $result .= ($result_profileField ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_PROFILE_FIELD_SUCCESS', array(
                        $fieldName
                    )) : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_PROFILE_FIELD_ERROR', array(
                        $fieldName
                    )));
                } else {
                    // the profile field is shared by all organizations, so check before deleting whether it is still used elsewhere

                    // the current configuration data has already been deleted; if another installation still exists now, do not delete
                    if ($pPreferences->getAllPluginInstallations() > 0) {
                        $result .= $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_PROFILE_FIELD_NOT_DELETED', array(
                            $fieldName
                        ));
                    } else {
                        $result_profileField = $profileField->delete();

                        $result .= ($result_profileField ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_PROFILE_FIELD_SUCCESS', array(
                            $fieldName
                        )) : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_PROFILE_FIELD_ERROR', array(
                            $fieldName
                        )));
                    }
                }
            }

            // delete mail data
            $result_mail = false;

            $sql = 'DELETE FROM ' . TBL_TEXTS . '
                          WHERE txt_name LIKE ?
                            AND txt_org_id = ? ';
            $result_mail = $gDb->queryPrepared($sql, array(
                'PMBMAIL_%',
                $gCurrentOrgId
            ));

            $result .= ($result_mail ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_MAIL_TEXTS_SUCCESS') : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_MAIL_TEXTS_ERROR'));

            // all profile fields created by Mitgliedsbeitrag
            $catArray = array();
            $catArray[] = 'ACCOUNT_DATA';
            $catArray[] = 'MANDATE' . $gCurrentOrgId;
            $catArray[] = 'MEMBERSHIP' . $gCurrentOrgId;
            $catArray[] = 'MEMBERSHIP_FEE' . $gCurrentOrgId;

            $catId = $profileField->getValue('usf_cat_id');
            $category->readDataById((int) $catId);

            foreach ($catArray as $cat) {
                $result_category = false;
                $category->readDataByColumns(array(
                    'cat_name_intern' => $cat
                ));

                try {
                    if ($category->getValue('cat_org_id') === $gCurrentOrgId) {
                        // the profile field is organization-specific and can therefore be deleted
                        $result_category = $category->delete();

                        $result .= ($result_category ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_SUCCESS', array(
                            $cat
                        )) : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_ERROR', array(
                            $cat
                        )));
                    } else {
                        // the profile field is shared by all organizations, so check before deleting whether it is still used elsewhere

                        // the current configuration data has already been deleted; if another installation still exists now, do not delete
                        if ($pPreferences->getAllPluginInstallations() > 0) {
                            $result .= $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_NOT_DELETED', array(
                                $cat
                            ));
                        } else {
                            $result_category = $category->delete();

                            $result .= ($result_category ? $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_SUCCESS', array(
                                $cat
                            )) : $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_ERROR', array(
                                $cat
                            )));
                        }
                    }
                } catch (Exception $e) {
                    $result .= $gL10n->get('PLG_MEMBERSHIPFEE_UNINST_CATEGORY_NOT_DELETED', array(
                        $cat
                    ));
                }
            }

            // output final message
            $gNavigation->clear();
            $gMessage->setForwardUrl($gHomepage);
            $gMessage->show($result);
            break;
    }
} catch (Exception $e) {
    $gMessage->show($e->getMessage());
}