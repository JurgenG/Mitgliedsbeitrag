<?php
/**
 ***********************************************************************************************
 * Admidio glue for online payments: builds the PaymentService, pay links and profile fields
 *
 * Included by common_function.php. See docs/online-payments.md.
 *
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */
use Admidio\Infrastructure\Entity\Entity;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Plugins\MembershipFee\classes\Config\ConfigTable;
use Plugins\MembershipFee\classes\Payment\AdmidioPaymentMemberStore;
use Plugins\MembershipFee\classes\Payment\PaymentException;
use Plugins\MembershipFee\classes\Payment\PaymentProviderFactory;
use Plugins\MembershipFee\classes\Payment\PaymentService;

if (basename($_SERVER['SCRIPT_FILENAME']) === 'payment_common.php') {
    exit('This page may not be called directly!');
}

/**
 * Builds the provider factory for this installation; the fake provider only on development machines
 *
 * @return PaymentProviderFactory
 */
function getPaymentProviderFactory(): PaymentProviderFactory
{
    return new PaymentProviderFactory(
        PaymentProviderFactory::isFakeAllowed(getenv(PaymentProviderFactory::ENV_FAKE)),
        ADMIDIO_PATH . FOLDER_DATA . '/membershipfee_fake_payments.json',
        ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/fake_checkout.php'
    );
}

/**
 * Tells whether online payments are enabled with a provider that can currently be used
 *
 * @param ConfigTable $pPreferences
 * @return bool
 */
function isOnlinePaymentActive(ConfigTable $pPreferences): bool
{
    return (bool) $pPreferences->config['payment']['enabled']
        && in_array($pPreferences->config['payment']['provider'], getPaymentProviderFactory()->selectableProviders(), true);
}

/**
 * Builds the PaymentService of the current organisation with the active provider
 *
 * @param ConfigTable $pPreferences
 * @return PaymentService
 * @throws PaymentException if online payments are off or the provider is not available
 */
function createPaymentService(ConfigTable $pPreferences): PaymentService
{
    global $gDb, $gProfileFields, $gCurrentOrgId, $gTimezone, $gL10n;

    if (!isOnlinePaymentActive($pPreferences)) {
        throw new PaymentException('Online payments are not active');
    }

    return new PaymentService(
        getPaymentProviderFactory()->create($pPreferences->config['payment']['provider']),
        new AdmidioPaymentMemberStore($gDb, $gProfileFields, $gCurrentOrgId),
        $gCurrentOrgId,
        new DateTimeZone($gTimezone),
        $gL10n->get('PLG_MEMBERSHIPFEE_MEMBERSHIP_FEE'),
        (bool) ($pPreferences->config['payment']['allow_higher_amount'] ?? 0)
    );
}

/**
 * Writes the outcome of a webhook call to Admidio's log; a paid amount that differs from the fee
 * is a warning, because an admin has to check it by hand
 *
 * @param string $outcome   One of the PaymentService::WEBHOOK_* constants
 * @param string $paymentId Payment id as sent by the provider (unverified)
 * @return void
 */
function logPaymentWebhookOutcome(string $outcome, string $paymentId): void
{
    global $gLogger;

    $context = array('payment_id' => substr(preg_replace('/[^A-Za-z0-9_]/', '', $paymentId), 0, 64));

    if ($outcome === PaymentService::WEBHOOK_AMOUNT_MISMATCH) {
        $gLogger->warning('MembershipFee: paid amount differs from the current fee, check this payment by hand', $context);
    } elseif ($outcome === PaymentService::WEBHOOK_PROVIDER_UNAVAILABLE) {
        $gLogger->warning('MembershipFee: payment provider unavailable, the provider will retry the webhook', $context);
    } else {
        $gLogger->info('MembershipFee: payment webhook: ' . $outcome, $context);
    }
}

/**
 * Returns the URL of pay.php for a member
 *
 * @param string $userUuid
 * @param string $token Pay token of the member
 * @param string $mode  'pay' or 'return'
 * @return string
 */
function getPayLink(string $userUuid, string $token, string $mode = 'pay'): string
{
    global $gCurrentOrgId;

    $params = array('org' => $gCurrentOrgId, 'user' => $userUuid, 'token' => $token);
    if ($mode !== 'pay') {
        $params['mode'] = $mode;
    }

    return SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/pay.php', $params);
}

/**
 * Returns the webhook URL the provider reports status changes to
 *
 * @param string $provider Provider name
 * @return string
 */
function getPaymentWebhookUrl(string $provider): string
{
    global $gCurrentOrgId;

    return SecurityUtils::encodeUrl(ADMIDIO_URL . FOLDER_PLUGINS . PLUGIN_FOLDER . '/system/payment_webhook.php', array(
        'provider' => $provider,
        'org' => $gCurrentOrgId
    ));
}

/**
 * Returns the member's pay link for mails (#payment_link#), or '' if online payments are off
 * or the current user may not send pay links
 *
 * @param string $userUuid
 * @return string
 */
function getPaymentLinkForMail(string $userUuid): string
{
    $pPreferences = new ConfigTable();
    $pPreferences->read();

    if (!isOnlinePaymentActive($pPreferences) || !isUserAuthorizedFor('payment_links')) {
        return '';
    }

    try {
        return getPayLink($userUuid, createPaymentService($pPreferences)->getOrCreateToken($userUuid));
    } catch (PaymentException $e) {
        return '';
    }
}

/**
 * Creates the profile fields PAYTOKEN{org}, PAYMENT_ID{org} and PAYMENT_PROVIDER{org} in the
 * category of the membership fee, if they don't exist yet
 *
 * @return void
 */
function createPaymentProfileFields(): void
{
    global $gDb, $gCurrentOrgId, $gCurrentSession, $gL10n;

    $sql = 'SELECT cat_id
              FROM ' . TBL_CATEGORIES . '
             WHERE cat_name_intern = ? -- \'MEMBERSHIP_FEE\' . $gCurrentOrgId
               AND cat_type = \'USF\'
               AND ( cat_org_id = ? -- $gCurrentOrgId
                OR cat_org_id IS NULL )';
    $catId = (int) $gDb->queryPrepared($sql, array('MEMBERSHIP_FEE' . $gCurrentOrgId, $gCurrentOrgId))->fetchColumn();

    if ($catId === 0) {
        throw new PaymentException('Category MEMBERSHIP_FEE' . $gCurrentOrgId . ' not found');
    }

    $fields = array(
        'PAYTOKEN' => array('PMB_PAYTOKEN', $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_FIELD_DESC_PAYTOKEN')),
        'PAYMENT_ID' => array('PMB_PAYMENT_ID', $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_FIELD_DESC_PAYMENT_ID')),
        'PAYMENT_PROVIDER' => array('PMB_PAYMENT_PROVIDER', $gL10n->get('PLG_MEMBERSHIPFEE_DEFAULT_FIELD_DESC_PAYMENT_PROVIDER'))
    );

    $created = false;
    foreach ($fields as $nameIntern => $field) {
        $sql = 'SELECT COUNT(*) FROM ' . TBL_USER_FIELDS . ' WHERE usf_name_intern = ? -- $nameIntern . $gCurrentOrgId';
        if ((int) $gDb->queryPrepared($sql, array($nameIntern . $gCurrentOrgId))->fetchColumn() > 0) {
            continue;
        }

        $sql = 'SELECT MAX(usf_sequence) FROM ' . TBL_USER_FIELDS . ' WHERE usf_cat_id = ? -- $catId';
        $sequence = (int) $gDb->queryPrepared($sql, array($catId))->fetchColumn() + 1;

        // plain Entity like install_db.php, so usf_name_intern is kept exactly as given
        $userField = new Entity($gDb, TBL_USER_FIELDS, 'usf');
        $userField->setValue('usf_cat_id', $catId);
        $userField->setValue('usf_sequence', $sequence);
        $userField->setValue('usf_name', $field[0]);
        $userField->setValue('usf_name_intern', $nameIntern . $gCurrentOrgId);
        $userField->setValue('usf_type', 'TEXT');
        $userField->setValue('usf_system', 0);
        $userField->setValue('usf_disabled', 1);
        $userField->setValue('usf_hidden', 1);
        $userField->setValue('usf_required_input', 0);
        $userField->setValue('usf_description', $field[1]);
        $userField->save();
        $created = true;
    }

    // every session caches the profile field definitions; like ProfileField::save() in Admidio
    if ($created) {
        $gCurrentSession->reloadAllSessions();
    }
}
