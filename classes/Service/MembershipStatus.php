<?php
/**
 ***********************************************************************************************
 * @copyright The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/**
 * @class MembershipStatus
 * @brief Works out a member's status for the start page box: paid until, payment deadline, warnings
 *
 * Independent of Admidio globals so it can be unit tested. See docs/membership-status.md.
 */

namespace Plugins\MembershipFee\classes\Service;

use DateTimeImmutable;
use InvalidArgumentException;

final class MembershipStatus
{
    public const PAID = 'paid';
    public const ENDING = 'ending';
    public const EXPIRED = 'expired';
    public const OPEN = 'open';
    public const DUE_SOON = 'due_soon';
    public const OVERDUE = 'overdue';
    public const NO_FEE = 'no_fee';

    /** States in which the member can pay online */
    public const PAYABLE_STATES = array(self::OPEN, self::DUE_SOON, self::OVERDUE);

    /**
     * @param int $periodEndDay   Day of the month on which the membership period ends
     * @param int $periodEndMonth Month in which the membership period ends
     * @param int $warningDays    Highlight a deadline or period end this many days ahead
     * @throws InvalidArgumentException for an impossible date (29 February included) or negative days
     */
    public function __construct(
        private readonly int $periodEndDay,
        private readonly int $periodEndMonth,
        private readonly int $warningDays
    ) {
        // a non-leap year, so 29 February is refused: it wouldn't exist every year
        if (!checkdate($periodEndMonth, $periodEndDay, 2025)) {
            throw new InvalidArgumentException('Invalid end of the membership period');
        }
        if ($warningDays < 0) {
            throw new InvalidArgumentException('Warning days must not be negative');
        }
    }

    /**
     * Returns the end of the membership period that contains the given date
     * @param DateTimeImmutable $date
     */
    public function periodEndFor(DateTimeImmutable $date): DateTimeImmutable
    {
        $date = $date->setTime(0, 0);
        $end = $date->setDate((int) $date->format('Y'), $this->periodEndMonth, $this->periodEndDay);

        return $end < $date ? $end->modify('+1 year') : $end;
    }

    /**
     * Works out the member's status
     * @param float                  $fee      Current fee FEE{org}
     * @param DateTimeImmutable|null $paidDate PAID{org}, null if not paid
     * @param DateTimeImmutable|null $dueDate  DUEDATE{org}, null if not set
     * @param DateTimeImmutable      $today
     * @return array{state: string, paidUntil: DateTimeImmutable|null, deadline: DateTimeImmutable|null}
     *         state is one of the constants; paidUntil is set for paid states, deadline for payable states
     */
    public function evaluate(float $fee, ?DateTimeImmutable $paidDate, ?DateTimeImmutable $dueDate, DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0);

        if ($paidDate !== null) {
            $paidUntil = $this->periodEndFor($paidDate);

            return array(
                'state' => $this->classify($paidUntil, $today, self::EXPIRED, self::ENDING, self::PAID),
                'paidUntil' => $paidUntil,
                'deadline' => null
            );
        }

        if ($fee <= 0) {
            return array('state' => self::NO_FEE, 'paidUntil' => null, 'deadline' => null);
        }

        $deadline = $dueDate !== null ? $dueDate->setTime(0, 0) : $this->periodEndFor($today);

        return array(
            'state' => $this->classify($deadline, $today, self::OVERDUE, self::DUE_SOON, self::OPEN),
            'paidUntil' => null,
            'deadline' => $deadline
        );
    }

    /**
     * Picks a state by how far a date is from today
     * @param DateTimeImmutable $date
     * @param DateTimeImmutable $today
     * @param string            $past  State if the date has passed
     * @param string            $close State if the date is today or within the warning days
     * @param string            $far   State otherwise
     * @return string
     */
    private function classify(DateTimeImmutable $date, DateTimeImmutable $today, string $past, string $close, string $far): string
    {
        if ($date < $today) {
            return $past;
        }

        return $date <= $today->modify('+' . $this->warningDays . ' days') ? $close : $far;
    }
}
