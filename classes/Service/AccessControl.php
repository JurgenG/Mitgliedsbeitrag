<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class AccessControl
 * @brief Decides role-based plugin permissions from the 'access' section of the plugin config
 *
 * Administrators have every permission. Everyone else needs membership in one of the roles
 * selected for that permission. Independent of Admidio globals so it can be unit tested;
 * use isUserAuthorizedFor() in common_function.php from plugin scripts.
 */

namespace Plugins\MembershipFee\classes\Service;

use InvalidArgumentException;

final class AccessControl
{
    public const PERMISSIONS = array(
        'preferences',
        'payment_settings',
        'payment_details',
        'payment_links',
        'payment_refunds'
    );

    /** @var array<string, array<int|string>> */
    private array $accessConfig;

    /**
     * @param array<string, array<int|string>> $accessConfig The plugin config section 'access' (permission => role ids)
     */
    public function __construct(array $accessConfig)
    {
        $this->accessConfig = $accessConfig;
    }

    /**
     * @param string     $permission      One of self::PERMISSIONS
     * @param bool       $isAdministrator True if the user is an Admidio administrator
     * @param array<int> $userRoleIds     Ids of the roles the user is currently a member of
     * @throws InvalidArgumentException for an unknown permission, so typos never pass unnoticed
     */
    public function isAllowed(string $permission, bool $isAdministrator, array $userRoleIds): bool
    {
        if (!in_array($permission, self::PERMISSIONS, true)) {
            throw new InvalidArgumentException('Unknown permission: ' . $permission);
        }

        if ($isAdministrator) {
            return true;
        }

        $allowedRoleIds = array_filter(array_map('intval', $this->accessConfig[$permission] ?? array()));
        $userRoleIds = array_map('intval', $userRoleIds);

        return count(array_intersect($allowedRoleIds, $userRoleIds)) > 0;
    }
}
