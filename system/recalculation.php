<?php
/**
 ***********************************************************************************************
 * Recalculation of the membership fees
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 ***********************************************************************************************
 */

/**
 * ****************************************************************************
 * Parameters:
 *
 * mode : preview - preview of the new fees
 * write - save the new fees
 * print - preview for printing
 *
 * ***************************************************************************
 */
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\Infrastructure\Exception;
use Admidio\Roles\Entity\Role;
use Admidio\Users\Entity\User;
use Plugins\MembershipFee\classes\Config\ConfigTable;

require_once (__DIR__ . '/../../../system/common.php');
require_once (__DIR__ . '/common_function.php');

// only authorized user are allowed to start this module
if (! isUserAuthorized()) {
    throw new Exception('SYS_NO_RIGHTS');
}

// CSRF protection: reject the request if the token is missing or invalid
checkCsrfToken();

// Initialize and check the parameters
$getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
    'defaultValue' => 'preview',
    'validValues' => array(
        'preview',
        'write',
        'print'
    )
));

$pPreferences = new ConfigTable();
$pPreferences->read();

$user = new User($gDb, $gProfileFields);

// set headline of the script
$headline = $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION');

$gNavigation->addUrl(CURRENT_URL, $headline);

if ($getMode == 'preview') // Default
{
    $members = array();
    $message = '';

    // a # is stored in $pPreferences->config instead of a space; # is replaced again here
    $text_token = ($pPreferences->config['Beitrag']['beitrag_text_token'] == '#') ? ' ' : $pPreferences->config['Beitrag']['beitrag_text_token'];

    $role_separator = ($pPreferences->config['Beitrag']['beitrag_role_separator'] != '') ? $pPreferences->config['Beitrag']['beitrag_role_separator'] : ' ';

    // read all contribution roles
    $contributingRolls = beitragsrollen_einlesen('', array(
        'FIRST_NAME',
        'LAST_NAME',
        'IBAN',
        'DEBTOR'
    ));

    // check whether entries exist in the role selection
    if (isset($_POST['recalculation_roleselection'])) {
        $_SESSION['pMembershipFee']['recalculation_rol_sel'] = $_POST['recalculation_roleselection'];

        // remove unselected contribution roles from the array $contributingRolls
        $message .= '<strong>' . $gL10n->get('SYS_NOTE') . ':</strong> ' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_ROLLQUERY_INFO') . '</strong><br/><br/>';
        foreach ($contributingRolls as $rol => $roldata) {
            if (! in_array($rol, $_POST['recalculation_roleselection'])) {
                unset($contributingRolls[$rol]);
            } else {
                $message .= '- ' . $roldata['rolle'] . '<br/>';
            }
        }

        // prepare role data for list_members()
        $selectionRolls = array();
        $role = new Role($gDb);
        foreach ($_POST['recalculation_roleselection'] as $rol) {
            $role->readDataById($rol);
            $selectionRolls[$role->getValue('rol_name')] = 0;
        }
    } else {
        $selectionRolls = 0;
        unset($_SESSION['pMembershipFee']['recalculation_rol_sel']);
    }

    // if $_SESSION['pMembershipFee']['recalculation_user'] exists, a calculation has already been performed
    if (! isset($_SESSION['pMembershipFee']['recalculation_user'])) {

        // iterate through these roles and determine a payer for the family roles
        foreach ($contributingRolls as $rol => $roldata) {
            // families only
            if ($roldata['rollentyp'] == 'fam') {
                // iterate through all members of this role and determine a payer
                // 1st pass: determine the first member for whom (account number AND bank code) or IBAN is set
                foreach ($roldata['members'] as $key => $data) {
                    $contributingRolls[$rol]['has_to_pay'] = $key;

                    if (strlen($data['IBAN']) !== 0) {
                        $contributingRolls[$rol]['has_to_pay'] = $key;
                        break;
                    }
                }
                // iterate through all members of this role and determine a payer
                // 2nd pass: if there is a role leader, overwrite the payer, since it takes precedence
                foreach ($roldata['members'] as $key => $data) {
                    if (isGroupLeader($key, $rol)) {
                        $contributingRolls[$rol]['has_to_pay'] = $key;
                        break;
                    }
                }
            }
        }

        // read all active members
        $members = list_members(array(
            'FIRST_NAME',
            'LAST_NAME',
            'FEE' . $gCurrentOrgId,
            'CONTRIBUTORY_TEXT' . $gCurrentOrgId,
            'PAID' . $gCurrentOrgId,
            'ACCESSION' . $gCurrentOrgId,
            'DEBTOR'
        ), $selectionRolls);

        // iterate through all members and determine the fees based on role memberships
        foreach ($members as $member => $memberdata) {
            $members[$member]['FEE_NEW'] = 0;
            $members[$member]['CONTRIBUTORY_TEXT_NEW'] = '';

            foreach ($contributingRolls as $rol => $roldata) {
                $rolDescription = ' ';
                if ($roldata['rol_description'] != '') {
                    $rolDescription = $role_separator . $roldata['rol_description'] . ' ';
                }

                // all roles except family roles
                if (($roldata['rollentyp'] != 'fam') && (array_key_exists($member, $roldata['members']))) {
                    // anteilige Beitragsberechnung (Beginn)
                    if ($pPreferences->config['Beitrag']['beitrag_anteilig'] == true) // based on the start of a role membership
                    {
                        $time_begin = strtotime((string) $roldata['members'][$member]['mem_begin']);
                    } else // anhand des Beitrittsdatums
                    {
                        $time_begin = strtotime($members[$member]['ACCESSION' . $gCurrentOrgId]);
                    }

                    // pro-rata fee calculation based on the end of a role membership
                    // Info: since the field "exit date" does not exist, only the end of a role membership can be processed

                    // the default date '9999-12-31' cannot be processed on some systems
                    if ($roldata['members'][$member]['mem_end'] == '9999-12-31') {
                        $time_end = strtotime('2038-01-19');
                    } else {
                        $time_end = strtotime((string) $roldata['members'][$member]['mem_end']);
                    }

                    // fee calculation only if the member joined in the current year. In principle, only the join date is checked
                    // the start of a role membership is not evaluated, since members with future role memberships were not read anyway
                    if ($time_begin < strtotime((date('Y') + 1) . '-01-01')) {

                        // calculate pro-rata fee if the member joined or left in the current year
                        // && contribution period (cost_period) must not be "One-time" (-1)
                        // && contribution period (cost_period) must not be "Yearly" (1)
                        if ((strtotime(date('Y') . '-01-01') < $time_begin || $time_end < strtotime(date('Y') . '-12-31')) && ($roldata['rol_cost_period'] != - 1) && ($roldata['rol_cost_period'] != 1)) {

                            if (strtotime(date('Y') . '-01-01') < $time_begin) {
                                $month_begin = date('n', $time_begin);
                            } else {
                                $month_begin = 1;
                            }
                            if (strtotime(date('Y') . '-12-31') > $time_end) {
                                $month_end = date('n', $time_end);
                            } else {
                                $month_end = 12;
                            }

                            $segment_begin = ceil($month_begin * $roldata['rol_cost_period'] / 12);
                            $segment_end = ceil($month_end * $roldata['rol_cost_period'] / 12);

                            $members[$member]['FEE_NEW'] += ($segment_end - $segment_begin + 1) * $roldata['rol_cost'] / $roldata['rol_cost_period'];
                            $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= $rolDescription;

                            if ($pPreferences->config['Beitrag']['beitrag_suffix'] != '') {
                                $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= ' ' . $pPreferences->config['Beitrag']['beitrag_suffix'] . ' ';
                            }
                            // beitrag_suffix should be displayed only once, but if rol_description is empty,
                            // it is appended several times in a row with many spaces in between, so replace
                            // first replace two consecutive spaces with one space
                            // $members[$member]['CONTRIBUTORY_TEXT_NEW'] = str_replace(' ', ' ', $members[$member]['CONTRIBUTORY_TEXT_NEW']); //toDo testen
                            // jetzt mehrfache beitrag_suffix loeschen
                            $members[$member]['CONTRIBUTORY_TEXT_NEW'] = str_replace($pPreferences->config['Beitrag']['beitrag_suffix'] . ' ' . $pPreferences->config['Beitrag']['beitrag_suffix'], $pPreferences->config['Beitrag']['beitrag_suffix'], $members[$member]['CONTRIBUTORY_TEXT_NEW']);
                        } else // no pro-rata calculation
                        {
                            $members[$member]['FEE_NEW'] += $roldata['rol_cost'];
                            $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= $rolDescription;
                        }
                    }
                }
            }

            // if defined: fee text with the name of the user
            if (($pPreferences->config['Beitrag']['beitrag_textmitnam'] == true) && ($members[$member]['FEE_NEW'] != 0) && ! (($members[$member]['LAST_NAME'] . ' ' . $members[$member]['FIRST_NAME'] == $members[$member]['DEBTOR']) || ($members[$member]['FIRST_NAME'] . ' ' . $members[$member]['LAST_NAME'] == $members[$member]['DEBTOR']) || (empty($members[$member]['DEBTOR'])))) {
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= $text_token . $members[$member]['LAST_NAME'] . ' ' . $members[$member]['FIRST_NAME'] . $text_token;
            }
        }

        // iterate through all roles and their members and add the fees of a member
        // who is also a family member to the payer of the family
        foreach ($contributingRolls as $rol => $roldata) {
            $rolDescription = ' ';
            if ($roldata['rol_description'] != '') {
                $rolDescription = $role_separator . $roldata['rol_description'] . ' ';
            }

            // only roles with the prefix of a family && the family role must have members
            if (($roldata['rollentyp'] == 'fam') && (count($roldata['members']) > 0)) {
                // if defined: fee text with all family members
                if ($pPreferences->config['Beitrag']['beitrag_textmitfam'] == true) {
                    $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] .= ' ';
                    foreach ($roldata['members'] as $member => $memberdata) {
                        $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] .= $text_token . $members[$member]['LAST_NAME'] . ' ' . $members[$member]['FIRST_NAME'];
                    }
                    $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] .= $text_token . ' ';
                }

                // iterate through all members of this role and assign the members' fees to the payer
                foreach ($roldata['members'] as $member => $memberdata) {
                    // not for the payer himself and only if an additional fee was calculated for the member
                    if (($roldata['has_to_pay'] != $member) && ($members[$member]['FEE_NEW'] > 0)) {
                        $members[$roldata['has_to_pay']]['FEE_NEW'] += $members[$member]['FEE_NEW'];
                        $members[$member]['FEE_NEW'] = 0;
                        $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] .= $members[$member]['CONTRIBUTORY_TEXT_NEW'] . ' ';

                        // if not defined: fee text with all family members, still append last name and first name
                        if (! $pPreferences->config['Beitrag']['beitrag_textmitnam']) {
                            $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] .= $text_token . $memberdata['LAST_NAME'] . ' ' . $memberdata['FIRST_NAME'] . $text_token . ' ';
                        }
                        $members[$member]['CONTRIBUTORY_TEXT_NEW'] = '';
                    }
                }

                // is this family role defined as a multiplier role?
                if (in_array($rol, $pPreferences->config['multiplier']['roles'])) {
                    $members[$roldata['has_to_pay']]['FEE_NEW'] = $members[$roldata['has_to_pay']]['FEE_NEW'] * $roldata['rol_cost'] / 100;
                    $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] = $rolDescription . $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] . ' ';
                } else {
                    if ($pPreferences->config['Beitrag']['beitrag_anteilig'] == true) // pro-rata fee calculation based on the start of a role membership
                    {
                        $time_begin = strtotime($roldata['members'][$roldata['has_to_pay']]['mem_begin']);
                    } else // anteilige Betragsberechnung anhand des Beitrittsdatums
                    {
                        $time_begin = strtotime($members[$roldata['has_to_pay']]['ACCESSION' . $gCurrentOrgId]);
                    }

                    // the default date '9999-12-31' cannot be processed on some systems
                    if ($roldata['members'][$member]['mem_end'] == '9999-12-31') {
                        $time_end = strtotime('2038-01-19');
                    } else {
                        $time_end = strtotime($roldata['members'][$member]['mem_end']);
                    }

                    // fee calculation only if the member joined in the current year. In principle, only the join date is checked
                    // the start of a role membership is not evaluated, since members with future role memberships were not read anyway
                    if ($time_begin < strtotime((date('Y') + 1) . '-01-01')) {

                        // calculate pro-rata fee if the member (in this case the payer of the family role) joined or left in the current year
                        // && contribution period (cost_period) must not be "One-time" (-1)
                        // && contribution period (cost_period) must not be "Yearly" (1)
                        if ((strtotime(date('Y') . '-01-01') < $time_begin || $time_end < strtotime(date('Y') . '-12-31')) && ($roldata['rol_cost_period'] != - 1) && ($roldata['rol_cost_period'] != 1)) {

                            if (strtotime(date('Y') . '-01-01') < $time_begin) {
                                $month_begin = date('n', $time_begin);
                            } else {
                                $month_begin = 1;
                            }
                            if (strtotime(date('Y') . '-12-31') > $time_end) {
                                $month_end = date('n', $time_end);
                            } else {
                                $month_end = 12;
                            }

                            $segment_begin = ceil($month_begin * $roldata['rol_cost_period'] / 12);
                            $segment_end = ceil($month_end * $roldata['rol_cost_period'] / 12);

                            $members[$roldata['has_to_pay']]['FEE_NEW'] += ($segment_end - $segment_begin + 1) * $roldata['rol_cost'] / $roldata['rol_cost_period'];
                            $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] = $rolDescription . $pPreferences->config['Beitrag']['beitrag_suffix'] . ' ' . $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] . ' ';
                        } else {
                            $members[$roldata['has_to_pay']]['FEE_NEW'] += $roldata['rol_cost'];
                            $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] = $rolDescription . $members[$roldata['has_to_pay']]['CONTRIBUTORY_TEXT_NEW'] . ' ';
                        }
                    }
                }
            }
        }

        foreach ($members as $member => $memberdata) {
            // letzte Datenaufbereitung (aufsummieren, ueberschreiben, runden...)
            if ((empty($members[$member]['FEE' . $gCurrentOrgId]) || (! (empty($members[$member]['FEE' . $gCurrentOrgId])) && (isset($_POST['recalculation_modus']) && (($_POST['recalculation_modus'] == 'overwrite') || ($_POST['recalculation_modus'] == 'summation'))))) && (! isset($_POST['recalculation_notpaid']) || (isset($_POST['recalculation_notpaid']) && $members[$member]['PAID' . $gCurrentOrgId] == '')) && ($members[$member]['FEE_NEW'] > $pPreferences->config['Beitrag']['beitrag_mindestbetrag'])) {
                // if present, remove the first $role_separator
                // Bsp: SV Musterverein +Jahresbeitrag+Spartenbeitrag soll sein SV Musterverein Jahresbeitrag+Spartenbeitrag
                if (substr($members[$member]['CONTRIBUTORY_TEXT_NEW'], 0, strlen($role_separator)) == $role_separator) {
                    $members[$member]['CONTRIBUTORY_TEXT_NEW'] = substr($members[$member]['CONTRIBUTORY_TEXT_NEW'], strlen($role_separator));
                }

                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = $pPreferences->config['Beitrag']['beitrag_prefix'] . ' ' . $members[$member]['CONTRIBUTORY_TEXT_NEW'] . ' ';

                // round all fees to 2 decimal places
                $members[$member]['FEE_NEW'] = round($members[$member]['FEE_NEW'], 2);

                // ggf. abrunden
                if ($pPreferences->config['Beitrag']['beitrag_abrunden'] == true) {
                    $members[$member]['FEE_NEW'] = floor($members[$member]['FEE_NEW']);
                }

                if (isset($_POST['recalculation_modus']) && $_POST['recalculation_modus'] == 'summation') {
                    $members[$member]['FEE_NEW'] += (float) $members[$member]['FEE' . $gCurrentOrgId];
                    $members[$member]['CONTRIBUTORY_TEXT_NEW'] .= ' ' . $members[$member]['CONTRIBUTORY_TEXT' . $gCurrentOrgId] . ' ';
                }

                // remove leading and trailing spaces in the fee text
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = trim($members[$member]['CONTRIBUTORY_TEXT_NEW']);
                // replace two consecutive spaces with one space
                $members[$member]['CONTRIBUTORY_TEXT_NEW'] = str_replace('  ', ' ', $members[$member]['CONTRIBUTORY_TEXT_NEW']);
            } else {
                unset($members[$member]); // if no new fee was calculated, remove this member from the list
            }
        }
        // save members in session (for mode write and mode print)
        $_SESSION['pMembershipFee']['recalculation_user'] = $members;
    }

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-preview', $headline);
    $page->setContentFullWidth();

    if (sizeof($_SESSION['pMembershipFee']['recalculation_user']) > 0) {
        $datatable = true;
        $hoverRows = true;
        $classTable = 'table table-condensed';
        $table = new HtmlTable('table_new_recalculation', $page, $hoverRows, $datatable, $classTable);
        $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
        $table->setColumnAlignByArray(array(
            'left',
            'left',
            'center',
            'center',
            'center',
            'center'
        ));
        $columnValues = array(
            $gL10n->get('SYS_LASTNAME'),
            $gL10n->get('SYS_FIRSTNAME'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
            $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW'),
            $gL10n->get('PLG_MEMBERSHIPFEE_FEE_PREVIOUS'),
            $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_PREVIOUS')
        );
        $table->addRowHeadingByArray($columnValues);

        foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $member => $data) {
            $user->readDataById($member);

            $columnValues = array();
            $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $data['LAST_NAME'] . '</a>';
            $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
                'user_uuid' => $user->getValue('usr_uuid')
            )) . '">' . $data['FIRST_NAME'] . '</a>';
            $columnValues[] = '
                <a class="admidio-icon-link openPopup" href="javascript:void(0);" data-href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation_edit.php', array(
                'user_id' => $member
            )) . '">' . '
                    ' . $data['FEE_NEW'] . '
                </a>';
            $columnValues[] = '
                <a class="admidio-icon-link openPopup" href="javascript:void(0);" data-href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation_edit.php', array(
                'user_id' => $member
            )) . '">' . '
                    ' . $data['CONTRIBUTORY_TEXT_NEW'] . '
                </a>';
            $columnValues[] = $data['FEE' . $gCurrentOrgId];
            $columnValues[] = $data['CONTRIBUTORY_TEXT' . $gCurrentOrgId];
            $table->addRowByArray($columnValues);
        }

        $page->addHtml($table->show(false));

        $form = new HtmlForm('recalculation_preview_form', SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
            'mode' => 'write'
        )), $page);

        $form->addSubmitButton('btn_next_page', $gL10n->get('SYS_SAVE'), array(
            'icon' => 'bi-check-lg',
            'class' => 'btn btn-primary'
        ));
        $form->addDescription($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_PREVIEW'));

        $page->addHtml($form->show(false));
    } else {
        $page->addHtml($gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NO_DATA') . '<br/>');
    }

    if (! empty($message)) {
        $page->addHtml($message);
    }
    $page->show();
} elseif ($getMode == 'write') {
    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-write', $headline);
    $page->setContentFullWidth();

    $page->addPageFunctionsMenuItem('menu_item_print_view', $gL10n->get('SYS_PRINT_PREVIEW'), 'javascript:void(0);', 'bi-printer');

    $page->addJavascript('
    	$("#menu_item_print_view").click(function() {
            window.open("' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/recalculation.php', array(
        'mode' => 'print'
    )) . '", "_blank");
        });', true);

    $datatable = true;
    $hoverRows = true;
    $classTable = 'table table-condensed';

    $table = new HtmlTable('table_saved_recalculation', $page, $hoverRows, $datatable, $classTable);
    $table->setDatatablesRowsPerPage($gSettingsManager->getInt('groups_roles_members_per_page'));
    $table->setColumnAlignByArray(array(
        'left',
        'left',
        'center',
        'center'
    ));
    $columnValues = array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW')
    );
    $table->addRowHeadingByArray($columnValues);

    foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $member => $data) {
        $user->readDataById($member);

        $columnValues = array();
        $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
            'user_uuid' => $user->getValue('usr_uuid')
        )) . '">' . $data['LAST_NAME'] . '</a>';
        $columnValues[] = '<a href="' . SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_MODULES . '/profile/profile.php', array(
            'user_uuid' => $user->getValue('usr_uuid')
        )) . '">' . $data['FIRST_NAME'] . '</a>';
        $columnValues[] = $data['FEE_NEW'];
        $columnValues[] = $data['CONTRIBUTORY_TEXT_NEW'];
        $table->addRowByArray($columnValues);

        $user->setValue('FEE' . $gCurrentOrgId, $data['FEE_NEW']);
        $user->setValue('CONTRIBUTORY_TEXT' . $gCurrentOrgId, $data['CONTRIBUTORY_TEXT_NEW']);
        $user->save();
    }

    $page->addHtml('<div style="width:100%; height: 500px; overflow:auto; border:20px;">');
    $page->addHtml($table->show(false));
    $page->addHtml('</div><br/>');
    $page->addHtml('<strong>' . $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_SAVED') . '</strong><br/><br/>');
    $page->show();
} elseif ($getMode == 'print') {
    // create html page object without the custom theme files
    $hoverRows = false;
    $datatable = false;
    $classTable = 'table table-condensed table-striped';

    $page = new HtmlPage('plg-mitgliedsbeitrag-recalculation-print', $gL10n->get('PLG_MEMBERSHIPFEE_RECALCULATION_NEW'));
    $page->setPrintMode();

    $table = new HtmlTable('table_print_recalculation', $page, $hoverRows, $datatable, $classTable);
    $table->setColumnAlignByArray(array(
        'left',
        'left',
        'center',
        'center'
    ));
    $columnValues = array(
        $gL10n->get('SYS_LASTNAME'),
        $gL10n->get('SYS_FIRSTNAME'),
        $gL10n->get('PLG_MEMBERSHIPFEE_FEE_NEW'),
        $gL10n->get('PLG_MEMBERSHIPFEE_CONTRIBUTORY_TEXT_NEW')
    );
    $table->addRowHeadingByArray($columnValues);

    foreach ($_SESSION['pMembershipFee']['recalculation_user'] as $data) {
        $columnValues = array();
        $columnValues[] = $data['LAST_NAME'];
        $columnValues[] = $data['FIRST_NAME'];
        $columnValues[] = $data['FEE_NEW'];
        $columnValues[] = $data['CONTRIBUTORY_TEXT_NEW'];
        $table->addRowByArray($columnValues);
    }
    $page->addHtml($table->show(false));
    $page->show();
}
