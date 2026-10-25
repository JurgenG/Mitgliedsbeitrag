<?php
/**
 ***********************************************************************************************
 * Configuration data for the Admidio plugin Mitgliedsbeitrag
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 *
 ***********************************************************************************************
 */
global $gProfileFields, $gL10n;

$config_default = array();

// Default values of a new installation
// Language-dependent defaults come from the language files (PLG_MEMBERSHIPFEE_DEFAULT_*)

// Age roles
$config_default['Altersrollen'] = array(
    'altersrollen_token' => array(
        '*'
    ),
    'altersrollen_offset' => 0
);

// Family roles
$config_default['Familienrollen'] = array(
    'familienrollen_beitrag' => array(
        0
    ),
    'familienrollen_zeitraum' => array(
        12
    ),
    'familienrollen_beschreibung' => array(
        $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_FAMILY_ROLE_DESCRIPTION')
    ),
    'familienrollen_prefix' => array(
        $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_FAMILY_ROLE_PREFIX')
    ),
    'familienrollen_pruefung' => array(
        ''
    )
);

// Fee
$config_default['Beitrag'] = array(
    'beitrag_prefix' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_CONTRIBUTION_PREFIX', array(date('Y'))),
    'beitrag_suffix' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_CONTRIBUTION_SUFFIX'),
    'beitrag_textmitnam' => 1,
    'beitrag_textmitfam' => '',
    'beitrag_text_token' => '#',
    'beitrag_role_separator' => '',
    'beitrag_anteilig' => '',
    'beitrag_abrunden' => 1,
    'beitrag_mindestbetrag' => 0
);

// Account data
$config_default['Kontodaten'] = array(
    'bank' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_BANK'),
    'inhaber' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_ACCOUNT_HOLDER'),
    'iban' => 'DE123456789',
    'bic' => 'ABCDEFGH',
    'ci' => 'DE98ZZZ09999999999',
    'origcreditor' => '',
    'origci' => ''
);

// Mandate reference
$config_default['Mandatsreferenz'] = array(
    'prefix_fam' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_MANDATE_PREFIX_FAM'),
    'prefix_mem' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_MANDATE_PREFIX_MEM'),
    'prefix_pay' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_MANDATE_PREFIX_PAY'),
    'min_length' => 15,
    'data_field' => '-- User_ID --'
);

// Role check
$config_default['Rollenpruefung'] = array(
    'altersrollenfamilienrollen' => array(
        ' '
    ),
    'altersrollenpflicht' => array(
        ' '
    ),
    'familienrollenpflicht' => '',
    'fixrollenpflicht' => array(
        ' '
    ),
    'bezugskategorie' => array(
        ' '
    ),
    'altersrollenaltersrollen' => array(
        ' '
    ),
    'familienrollenfix' => array(
        ' '
    ),
    'altersrollenfix' => array(
        ' '
    ),
    'fixrollenfixrollen' => array(
        ' '
    ),
    'age_staggered_roles_exclusion' => array(
        ' '
    )
);

$config_default['tests_enable'] = array(
    'age_staggered_roles' => 1,
    'role_membership_age_staggered_roles' => 1,
    'role_membership_duty_and_exclusion' => 1,
    'family_roles' => 1,
    'account_details' => 1,
    'mandate_management' => 1,
    'iban_check' => 1,
    'bic_check' => 1
);

// Invoice export
$config_default['Rechnungs-Export'] = array(
    'rechnung_dateiname' => $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_BILL_FILE_NAME'),
    'rechnung_dateityp' => 'xlsx'
);

// SEPA
$config_default['SEPA'] = array(
    'dateiname' => 'sepa',
    'direct_debit_format' => 'v8',
    'kontroll_dateiname' => 'sepa',
    'kontroll_dateityp' => 'xlsx',
    'vorabinformation_dateiname' => 'export',
    'vorabinformation_dateityp' => 'xlsx',
    'duedate_for_all' => 0
);

// Plugin information
$config_default['Plugininformationen']['version'] = '';
$config_default['Plugininformationen']['stand'] = '';

// Columns for the view definitions
$config_default['columnconfig'] = array(
    'payments_fields' => array(
        'p' . $gProfileFields->getProperty('PAID' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('DUEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('SEQUENCETYPE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('FEE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'mandates_fields' => array(
        'p' . $gProfileFields->getProperty('MANDATEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('MANDATEID' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'bill_fields' => array(
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    ),
    'duedates_fields' => array(
        'p' . $gProfileFields->getProperty('DUEDATE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('SEQUENCETYPE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('FEE' . $GLOBALS['gCurrentOrgId'], 'usf_id'),
        'p' . $gProfileFields->getProperty('LAST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('FIRST_NAME', 'usf_id'),
        'p' . $gProfileFields->getProperty('BIRTHDAY', 'usf_id')
    )
);

// Member number
$config_default['membernumber'] = array(
    'format' => '',
    'fill_gaps' => 1
);

// Access authorization for the preferences module
$config_default['access']['preferences'] = array();

// Info for uninstall
$config_default['install']['access_role_id'] = 0;
$config_default['install']['menu_item_id'] = 0;

// Individual contributions
$config_default['individual_contributions'] = array(
    'access_to_module' => '0',
    'desc' => array(
        ' '
    ),
    'short_desc' => array(
        ' '
    ),
    'role' => array(
        ' '
    ),
    'amount' => array(
        ' '
    ),
    'profilefield' => array(
        ' '
    )
);

// Multiplier roles (family roles only)
$config_default['multiplier']['roles'] = array();

/*
 * Using this character combination, configuration data that is managed as an array at runtime
 * is combined into a string and stored in the Admidio database.
 * If the predefined character combination (#_#) must also be used, e.g. in the description
 * of a configuration, the plugin can no longer read stored configuration data
 * correctly. In this case the predefined character combination must be changed (e.g. to !-!)
 *
 * Attention: An uninstall must be performed before making a change!
 * Values already stored in the database can no longer be read after a change!
 */
$dbtoken = '#_#';
