<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Payment\Mollie;

use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Payment\Mollie\MollieApiKey;

final class MollieApiKeyTest extends TestCase
{
    private const TEST_KEY = 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM';
    private const LIVE_KEY = 'live_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM';

    public function testValidKeysPassValidation(): void
    {
        $this->assertNull(MollieApiKey::validate(self::TEST_KEY, true));
        $this->assertNull(MollieApiKey::validate(self::LIVE_KEY, false));
    }

    /**
     * @dataProvider malformedKeys
     */
    public function testMalformedKeyIsRejectedAsFormatError(string $key): void
    {
        $this->assertSame(MollieApiKey::ERROR_FORMAT, MollieApiKey::validate($key, true));
    }

    public static function malformedKeys(): array
    {
        return array(
            'empty'          => array(''),
            'no prefix'      => array('dHar4XY7LxsDOtmnkVtjNVWXLSlXsM'),
            'wrong prefix'   => array('prod_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM'),
            'too short'      => array('test_abc'),
            'bad characters' => array('test_dHar4XY7LxsDOtmnkVtjNVWXLS!XsM'),
            'whitespace'     => array('test_dHar4XY7LxsDOtmnkVtj NVWXLSlXsM'),
            'newline'        => array("test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM\n")
        );
    }

    public function testLiveKeyInTestModeIsRejectedAsModeError(): void
    {
        $this->assertSame(MollieApiKey::ERROR_MODE, MollieApiKey::validate(self::LIVE_KEY, true));
    }

    public function testTestKeyInLiveModeIsRejectedAsModeError(): void
    {
        $this->assertSame(MollieApiKey::ERROR_MODE, MollieApiKey::validate(self::TEST_KEY, false));
    }

    public function testMaskShowsOnlyPrefixAndLastFourCharacters(): void
    {
        $masked = MollieApiKey::mask(self::LIVE_KEY);

        $this->assertSame('live_••••lXsM', $masked);
        $this->assertStringNotContainsString('dHar4XY7', $masked);
    }

    public function testMaskOfEmptyOrMalformedKeyRevealsNothing(): void
    {
        $this->assertSame('', MollieApiKey::mask(''));
        $this->assertSame('••••', MollieApiKey::mask('garbage'));
    }

    public function testEmptySubmissionKeepsStoredKey(): void
    {
        $this->assertSame(self::LIVE_KEY, MollieApiKey::resolveForSave('', self::LIVE_KEY));
        $this->assertSame(self::LIVE_KEY, MollieApiKey::resolveForSave('   ', self::LIVE_KEY));
    }

    public function testSubmittedKeyReplacesStoredKeyAndIsTrimmed(): void
    {
        $this->assertSame(self::TEST_KEY, MollieApiKey::resolveForSave('  ' . self::TEST_KEY . "\n", self::LIVE_KEY));
    }

    public function testOverrideWinsOverStoredKey(): void
    {
        $this->assertSame(self::TEST_KEY, MollieApiKey::effectiveKey(self::LIVE_KEY, self::TEST_KEY));
    }

    /**
     * @dataProvider noOverride
     */
    public function testStoredKeyIsUsedWithoutOverride(?string $override): void
    {
        $this->assertSame(self::LIVE_KEY, MollieApiKey::effectiveKey(self::LIVE_KEY, $override));
    }

    public static function noOverride(): array
    {
        return array(
            'null'       => array(null),
            'empty'      => array(''),
            'whitespace' => array('  ')
        );
    }
}
