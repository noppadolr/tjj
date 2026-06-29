<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('thai_date')) {
    function thai_date(mixed $date): string
    {
        if (blank($date)) {
            return '-';
        } $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $date->format('d/m/').($date->year + 543);
    }
}
if (! function_exists('thai_datetime')) {
    function thai_datetime(mixed $date): string
    {
        if (blank($date)) {
            return '-';
        } $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $date->format('d/m/').($date->year + 543).$date->format(' H:i');
    }
}

if (! function_exists('thai_date_to_ad')) {
    /** Convert a Buddhist Era date (dd/mm/yyyy) to an ISO Gregorian date. */
    function thai_date_to_ad(mixed $date): string
    {
        $value = trim((string) $date);

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            [, $day, $month, $year] = $matches;
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
            [, $year, $month, $day] = $matches;
        } else {
            throw new InvalidArgumentException('Invalid Thai date format.');
        }

        $year = (int) $year;
        $month = (int) $month;
        $day = (int) $day;
        $year = $year >= 2400 ? $year - 543 : $year;

        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('Invalid Thai date.');
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
