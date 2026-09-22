<?php
/*
 * Mahnwesen - Dolibarr custom module
 * Copyright (C) 2026 Module contributors
 * GPL-3.0-or-later
 */

/**
 * How late-payment interest is counted (#33). Pure rules, no database: the
 * amounts come from the profile's interest rule and the base rates entered in
 * the setup. Nothing here decides what is lawful; it counts with the rates it
 * is given.
 */
class MahnwesenInterestPolicy
{
    /** Days a year is counted with; German and Austrian practice counts actual days over 365. */
    const DAYS_PER_YEAR = 365;

    /**
     * The rate that applies on one day: the fixed rate, or the base rate of
     * that day plus the surcharge in percentage points.
     *
     * @param string $mode none, fixed or base_plus
     * @param float $rate Fixed rate or surcharge, percent a year
     * @param float|null $base Base rate of that day, null when none is entered
     * @return float|null Percent a year, null when no rate applies
     */
    public static function rateOnDay($mode, $rate, $base)
    {
        if ($mode === 'fixed') {
            return max(0.0, (float) $rate);
        }
        if ($mode === 'base_plus' && $base !== null) {
            return max(0.0, (float) $base + (float) $rate);
        }
        return null;
    }

    /**
     * Interest on an amount for the days after the due date.
     *
     * The first day charged is the day after the due date, the last one is
     * $untilYmd. Days a base rate does not cover carry no interest, so nothing
     * is charged before the first base rate the setup holds.
     *
     * @param float $amount Open amount
     * @param string $dueYmd Due date YYYY-MM-DD
     * @param string $untilYmd Last day YYYY-MM-DD
     * @param array $periods Base rates as array('from' => YYYY-MM-DD, 'rate' => float), any order
     * @param string $mode none, fixed or base_plus
     * @param float $rate Fixed rate or surcharge, percent a year
     * @return array{amount:float,days:int,parts:array<int,array{from:string,to:string,days:int,rate:float,amount:float}>}
     */
    public static function interest($amount, $dueYmd, $untilYmd, $periods, $mode, $rate)
    {
        $empty = array('amount' => 0.0, 'days' => 0, 'parts' => array());
        $amount = (float) $amount;
        if ($amount <= 0 || !in_array($mode, array('fixed', 'base_plus'), true)) {
            return $empty;
        }
        $first = self::addDays($dueYmd, 1);
        $last = (string) $untilYmd;
        if ($first === '' || $last === '' || $first > $last) {
            return $empty;
        }
        // Every day of the span with the base rate that applies to it.
        $bounds = array($first);
        foreach ($periods as $period) {
            $from = substr((string) $period['from'], 0, 10);
            if ($from > $first && $from <= $last) {
                $bounds[] = $from;
            }
        }
        sort($bounds);
        $bounds = array_values(array_unique($bounds));
        $parts = array();
        $total = 0.0;
        $days = 0;
        foreach ($bounds as $index => $from) {
            $to = isset($bounds[$index + 1]) ? self::addDays($bounds[$index + 1], -1) : $last;
            if ($from > $to) {
                continue;
            }
            $applied = self::rateOnDay($mode, $rate, self::baseRateOn($from, $periods));
            if ($applied === null || $applied <= 0) {
                continue;
            }
            $spanDays = self::daysBetween($from, $to) + 1;
            $partAmount = $amount * ($applied / 100) * $spanDays / self::DAYS_PER_YEAR;
            $parts[] = array('from' => $from, 'to' => $to, 'days' => $spanDays, 'rate' => $applied, 'amount' => round($partAmount, 2));
            $total += $partAmount;
            $days += $spanDays;
        }
        return array('amount' => round($total, 2), 'days' => $days, 'parts' => $parts);
    }

    /**
     * The base rate of a day: the rate of the latest period that started on or
     * before it, null when none did.
     *
     * @param string $ymd Day
     * @param array $periods Base rates
     * @return float|null
     */
    public static function baseRateOn($ymd, $periods)
    {
        $best = null;
        $bestFrom = '';
        foreach ($periods as $period) {
            $from = substr((string) $period['from'], 0, 10);
            if ($from <= $ymd && $from >= $bestFrom) {
                $best = (float) $period['rate'];
                $bestFrom = $from;
            }
        }
        return $best;
    }

    /** @return string YYYY-MM-DD, '' when the date cannot be read */
    public static function addDays($ymd, $days)
    {
        try {
            $date = new DateTimeImmutable(substr((string) $ymd, 0, 10).' 12:00:00');
        } catch (Exception $e) {
            return '';
        }
        return $date->modify(((int) $days).' days')->format('Y-m-d');
    }

    /** @return int Whole days between two days */
    public static function daysBetween($fromYmd, $toYmd)
    {
        try {
            $from = new DateTimeImmutable(substr((string) $fromYmd, 0, 10).' 12:00:00');
            $to = new DateTimeImmutable(substr((string) $toYmd, 0, 10).' 12:00:00');
        } catch (Exception $e) {
            return 0;
        }
        return (int) $from->diff($to)->days;
    }
}
