<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('thai_date')) {
    /** Default Buddhist-Era date display, e.g. "20 มิ.ย. 2569". */
    function thai_date(mixed $date): string
    {
        return thai_date_short($date);
    }
}

if (! function_exists('thai_month_short')) {
    /** Thai abbreviated month name for a 1-12 month number, e.g. 6 => "มิ.ย." */
    function thai_month_short(int $month): string
    {
        return [
            1 => 'ม.ค.',
            2 => 'ก.พ.',
            3 => 'มี.ค.',
            4 => 'เม.ย.',
            5 => 'พ.ค.',
            6 => 'มิ.ย.',
            7 => 'ก.ค.',
            8 => 'ส.ค.',
            9 => 'ก.ย.',
            10 => 'ต.ค.',
            11 => 'พ.ย.',
            12 => 'ธ.ค.',
        ][$month] ?? '';
    }
}

if (! function_exists('thai_date_short')) {
    function thai_date_short(mixed $date): string
    {
        if (blank($date)) {
            return '-';
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $date->format('j').' '.thai_month_short($date->month).' '.($date->year + 543);
    }
}

if (! function_exists('thai_datetime')) {
    function thai_datetime(mixed $date): string
    {
        if (blank($date)) {
            return '-';
        }

        $date = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return thai_date_short($date).$date->format(' H:i');
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
