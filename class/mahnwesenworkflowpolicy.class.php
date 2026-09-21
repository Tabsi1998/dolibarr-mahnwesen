<?php
/*
 * Mahnwesen - Dolibarr custom module
 * GPL-3.0-or-later
 */

/**
 * The workflow rules of dunning, without a database (#24, #29).
 *
 * Levels are 1 (payment reminder) to 4 (3rd dunning notice). $thresholds maps
 * a level to its days after the due date, $enabled a level to whether the
 * stage is switched on, $completed a level to whether it was sent or skipped.
 * Dates are local 'Y-m-d H:i:s' strings, timestamps Unix seconds.
 */
class MahnwesenWorkflowPolicy
{
    /** The highest enabled stage whose days after the due date have passed, 0 for none. */
    public static function stageForDaysLate($daysLate, array $thresholds, array $enabled)
    {
        $daysLate = max(0, (int) $daysLate);
        $stage = 0;
        foreach ($thresholds as $level => $days) {
            if (!empty($enabled[(int) $level]) && $daysLate >= (int) $days) {
                $stage = (int) $level;
            }
        }
        return $stage;
    }

    /** The first enabled stage up to the calendar stage that is not completed, 0 for none: stages go in order. */
    public static function nextRequiredLevel($calculatedLevel, array $enabled, array $completed)
    {
        $calculatedLevel = max(0, min(4, (int) $calculatedLevel));
        for ($level = 1; $level <= $calculatedLevel; $level++) {
            if (!empty($enabled[$level]) && empty($completed[$level])) {
                return $level;
            }
        }
        return 0;
    }

    /** The closest earlier enabled stage, 0 if the stage is the first. */
    public static function previousEnabledLevel($level, array $enabled)
    {
        for ($previous = ((int) $level) - 1; $previous >= 1; $previous--) {
            if (!empty($enabled[$previous])) {
                return $previous;
            }
        }
        return 0;
    }

    /** The next enabled stage after the calendar stage, 0 if none follows. */
    public static function nextFutureLevel($calculatedLevel, array $enabled)
    {
        for ($level = max(0, (int) $calculatedLevel) + 1; $level <= 4; $level++) {
            if (!empty($enabled[$level])) {
                return $level;
            }
        }
        return 0;
    }

    /** When a stage is due by the calendar: the due date plus its days, at the start of the day. */
    public static function stageDueAt($dueYmd, $thresholdDays)
    {
        if (empty($dueYmd) || $thresholdDays === null) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($dueYmd.' 00:00:00');
            return $date->modify('+'.((int) $thresholdDays).' days')->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * When a stage is due after the previous one was completed.
     *
     * The later of: the calendar date; the start of the day the spacing
     * between the two stages ends (#18); the day after the payment deadline
     * the previous notice named (#64).
     *
     * @param string $calendarDue From stageDueAt()
     * @param int|null $completedAt When the previous stage was completed
     * @param int $gapDays Days between the thresholds of the two stages
     * @param int $paymentDays Payment period of the previous stage
     * @param int|null $sentAt When the previous notice was sent, null if it was skipped
     * @return string
     */
    public static function spacedDueAt($calendarDue, $completedAt, $gapDays, $paymentDays, $sentAt)
    {
        if (empty($completedAt)) {
            return $calendarDue;
        }
        $due = date('Y-m-d 00:00:00', strtotime('+'.max(0, (int) $gapDays).' days', (int) $completedAt));
        if ((int) $paymentDays > 0 && !empty($sentAt)) {
            $afterDeadline = date('Y-m-d 00:00:00', strtotime('+'.((int) $paymentDays + 1).' days', (int) $sentAt));
            if (strtotime($afterDeadline) > strtotime($due)) {
                $due = $afterDeadline;
            }
        }
        return strtotime($due) > strtotime($calendarDue) ? $due : $calendarDue;
    }
}
