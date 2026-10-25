<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Service\AccessControl;

final class AccessControlTest extends TestCase
{
    /**
     * Role ids as ConfigTable delivers them: strings, and array('') for an empty selection
     */
    private function accessControl(): AccessControl
    {
        return new AccessControl(array(
            'preferences'      => array(''),
            'payment_settings' => array('7'),
            'payment_details'  => array('5', '7'),
            'payment_links'    => array('5'),
            'payment_refunds'  => array(' ')
        ));
    }

    /**
     * @dataProvider paymentPermissions
     */
    public function testAdministratorHasEveryPermission(string $permission): void
    {
        $accessControl = new AccessControl(array());

        $this->assertTrue($accessControl->isAllowed($permission, true, array()));
    }

    /**
     * @dataProvider paymentPermissions
     */
    public function testNobodyElseHasAPermissionByDefault(string $permission): void
    {
        $accessControl = new AccessControl(array($permission => array('')));

        $this->assertFalse($accessControl->isAllowed($permission, false, array(2, 5, 7)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paymentPermissions(): array
    {
        return array(
            'settings' => array('payment_settings'),
            'details'  => array('payment_details'),
            'links'    => array('payment_links'),
            'refunds'  => array('payment_refunds')
        );
    }

    public function testMemberOfASelectedRoleIsAllowed(): void
    {
        $this->assertTrue($this->accessControl()->isAllowed('payment_links', false, array(2, 5)));
    }

    public function testMemberOfOtherRolesIsDenied(): void
    {
        $this->assertFalse($this->accessControl()->isAllowed('payment_links', false, array(2, 7)));
    }

    public function testPermissionsAreIndependent(): void
    {
        $treasurer = array(5);

        $this->assertTrue($this->accessControl()->isAllowed('payment_links', false, $treasurer));
        $this->assertTrue($this->accessControl()->isAllowed('payment_details', false, $treasurer));
        $this->assertFalse($this->accessControl()->isAllowed('payment_settings', false, $treasurer));
        $this->assertFalse($this->accessControl()->isAllowed('payment_refunds', false, $treasurer));
    }

    public function testEmptyOrBlankRoleEntriesNeverMatch(): void
    {
        $this->assertFalse($this->accessControl()->isAllowed('payment_refunds', false, array(0)));
        $this->assertFalse($this->accessControl()->isAllowed('preferences', false, array(0)));
    }

    public function testMissingConfigForKnownPermissionDenies(): void
    {
        $accessControl = new AccessControl(array());

        $this->assertFalse($accessControl->isAllowed('payment_details', false, array(5)));
    }

    public function testUnknownPermissionThrowsEvenForAdministrator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->accessControl()->isAllowed('payment_setings', true, array());
    }
}
