<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class MollieApiKey
 * @brief Validation, masking and resolution of the Mollie API key (see docs/online-payments.md)
 *
 * Independent of Admidio globals so it can be unit tested. The key is a secret: never log it,
 * never echo it into a form, show only mask().
 */

namespace Plugins\MembershipFee\classes\Payment\Mollie;

final class MollieApiKey
{
    public const ERROR_FORMAT = 'format'; ///< key is not a well-formed Mollie API key
    public const ERROR_MODE = 'mode';     ///< key prefix does not match the selected test/live mode

    private const PATTERN = '/^(test|live)_[A-Za-z0-9]{30,}$/D';

    /**
     * Checks a key for the selected mode.
     *
     * @param string $key      The API key
     * @param bool   $testMode True if test mode is selected
     * @return string|null null if the key is acceptable, otherwise self::ERROR_FORMAT or self::ERROR_MODE
     */
    public static function validate(string $key, bool $testMode): ?string
    {
        if (preg_match(self::PATTERN, $key, $matches) !== 1) {
            return self::ERROR_FORMAT;
        }

        if (($matches[1] === 'test') !== $testMode) {
            return self::ERROR_MODE;
        }

        return null;
    }

    /**
     * Returns a display version of the key that reveals only the prefix and the last four characters.
     *
     * @param string $key The API key
     * @return string e.g. "live_••••abcd", "" for an empty key, "••••" for a malformed key
     */
    public static function mask(string $key): string
    {
        if ($key === '') {
            return '';
        }

        if (preg_match(self::PATTERN, $key, $matches) !== 1) {
            return '••••';
        }

        return $matches[1] . '_••••' . substr($key, -4);
    }

    /**
     * Decides which key is saved: an empty submission keeps the stored key.
     *
     * @param string $submitted Value of the (never pre-filled) form field
     * @param string $stored    Key currently stored in the configuration
     * @return string the key to save
     */
    public static function resolveForSave(string $submitted, string $stored): string
    {
        $submitted = trim($submitted);

        return $submitted === '' ? $stored : $submitted;
    }

    /**
     * Returns the key to use for API calls: a non-empty override (environment variable) wins.
     *
     * @param string      $stored   Key stored in the configuration
     * @param string|null $override Value of MEMBERSHIPFEE_MOLLIE_API_KEY, if any
     * @return string the key to use
     */
    public static function effectiveKey(string $stored, ?string $override): string
    {
        $override = trim((string) $override);

        return $override === '' ? $stored : $override;
    }
}
