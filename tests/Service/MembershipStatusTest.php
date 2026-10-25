<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

namespace Plugins\MembershipFee\Tests\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Plugins\MembershipFee\classes\Service\MembershipStatus;

final class MembershipStatusTest extends TestCase
{
    private static function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /**
     * @dataProvider periodEnds
     */
    public function testPeriodEndOnOrAfterADate(int $day, int $month, string $date, string $expected): void
    {
        $status = new MembershipStatus($day, $month, 30);

        $this->assertSame($expected, $status->periodEndFor(self::date($date))->format('Y-m-d'));
    }

    /**
     * @return array<string, array{int, int, string, string}>
     */
    public static function periodEnds(): array
    {
        return array(
            'calendar year'          => array(31, 12, '2026-10-25', '2026-12-31'),
            'on the end date itself' => array(31, 12, '2026-12-31', '2026-12-31'),
            'day after the end'      => array(31, 12, '2027-01-01', '2027-12-31'),
            'season, before end'     => array(30, 6, '2026-03-01', '2026-06-30'),
            'season, after end'      => array(30, 6, '2026-08-15', '2027-06-30')
        );
    }

    /**
     * @dataProvider invalidPeriodEnds
     */
    public function testInvalidPeriodEndThrows(int $day, int $month): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipStatus($day, $month, 30);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidPeriodEnds(): array
    {
        return array(
            '29 February'  => array(29, 2),
            '31 April'     => array(31, 4),
            'month 13'     => array(1, 13),
            'day 0'        => array(0, 1)
        );
    }

    public function testNegativeWarningDaysThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipStatus(31, 12, -1);
    }

    /**
     * @dataProvider states
     */
    public function testEvaluate(
        float $fee,
        ?string $paidDate,
        ?string $dueDate,
        string $today,
        string $state,
        ?string $paidUntil,
        ?string $deadline
    ): void {
        $status = new MembershipStatus(31, 12, 30);

        $result = $status->evaluate(
            $fee,
            $paidDate === null ? null : self::date($paidDate),
            $dueDate === null ? null : self::date($dueDate),
            self::date($today)
        );

        $this->assertSame($state, $result['state']);
        $this->assertSame($paidUntil, $result['paidUntil']?->format('Y-m-d'));
        $this->assertSame($deadline, $result['deadline']?->format('Y-m-d'));
    }

    /**
     * fee, paid date, due date, today => state, paid until, deadline (period end 31.12, warn 30 days)
     * @return array<string, array{float, ?string, ?string, string, string, ?string, ?string}>
     */
    public static function states(): array
    {
        return array(
            'paid, far from the end'      => array(25.0, '2026-03-10', null, '2026-06-01', MembershipStatus::PAID, '2026-12-31', null),
            'paid, 31 days left'          => array(25.0, '2026-03-10', null, '2026-11-30', MembershipStatus::PAID, '2026-12-31', null),
            'paid, 30 days left'          => array(25.0, '2026-03-10', null, '2026-12-01', MembershipStatus::ENDING, '2026-12-31', null),
            'paid, last day'              => array(25.0, '2026-03-10', null, '2026-12-31', MembershipStatus::ENDING, '2026-12-31', null),
            'paid last period'            => array(25.0, '2026-03-10', null, '2027-01-05', MembershipStatus::EXPIRED, '2026-12-31', null),
            'paid, fee irrelevant'        => array(0.0, '2026-03-10', null, '2026-06-01', MembershipStatus::PAID, '2026-12-31', null),
            'open, deadline = period end' => array(25.0, null, null, '2026-06-01', MembershipStatus::OPEN, null, '2026-12-31'),
            'open, due date far'          => array(25.0, null, '2026-09-01', '2026-06-01', MembershipStatus::OPEN, null, '2026-09-01'),
            'due soon, due date'          => array(25.0, null, '2026-06-20', '2026-06-01', MembershipStatus::DUE_SOON, null, '2026-06-20'),
            'due soon, on the due date'   => array(25.0, null, '2026-06-01', '2026-06-01', MembershipStatus::DUE_SOON, null, '2026-06-01'),
            'due soon, period end'        => array(25.0, null, null, '2026-12-15', MembershipStatus::DUE_SOON, null, '2026-12-31'),
            'overdue'                     => array(25.0, null, '2026-05-01', '2026-06-01', MembershipStatus::OVERDUE, null, '2026-05-01'),
            'no fee'                      => array(0.0, null, null, '2026-06-01', MembershipStatus::NO_FEE, null, null),
            'negative fee'                => array(-5.0, null, '2026-05-01', '2026-06-01', MembershipStatus::NO_FEE, null, null)
        );
    }

    public function testWarningDaysZeroOnlyHighlightsTheDayItself(): void
    {
        $status = new MembershipStatus(31, 12, 0);

        $this->assertSame(MembershipStatus::OPEN, $status->evaluate(25.0, null, null, self::date('2026-12-30'))['state']);
        $this->assertSame(MembershipStatus::DUE_SOON, $status->evaluate(25.0, null, null, self::date('2026-12-31'))['state']);
    }

    public function testCanPayOnlyForOpenStates(): void
    {
        $this->assertSame(
            array(MembershipStatus::OPEN, MembershipStatus::DUE_SOON, MembershipStatus::OVERDUE),
            MembershipStatus::PAYABLE_STATES
        );
    }
}
