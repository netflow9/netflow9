<?php
//CLD: 2026-10-20T21:01:44+03:00: Date values (made by Builtins::dateCtor, methods in Builtins::dateMethod)

declare(strict_types=1);

namespace sys\J\Runtime;

/**
 * A JavaScript Date: milliseconds since 1970-01-01T00:00:00Z, NAN for an Invalid Date.
 * Mutable, like in JS (the set* methods). Local time is PHP's default timezone (date_default_timezone_get()).
 * The static helpers are the date algorithms of the ECMAScript spec (MakeDay, MakeTime, LocalTime, UTC, TimeClip, Date.parse, the string formats).
 */
final class JsDate
{
    public const MS_PER_DAY = 86400000;
    public const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    public const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** @var array<string, \DateTimeZone> */
    private static array $zones = [];

    public function __construct(public float $time = NAN)
    {
    }

    public function isValid(): bool
    {
        return !is_nan($this->time);
    }

    // ============================================================ arithmetic

    /** TimeClip: NAN outside ±8.64e15 ms, else an integral number of ms. */
    public static function clip(float $t): float
    {
        if (!is_finite($t) || abs($t) > 8.64e15) {
            return NAN;
        }
        return ($t < 0 ? ceil($t) : floor($t)) + 0.0;
    }

    private static function trunc(float $x): float
    {
        return $x < 0 ? ceil($x) : floor($x);
    }

    /** MakeDay: days since the epoch of year $y, month $m (0-based) and day $d (1-based); month and day may overflow (month 12 = January of the next year). */
    public static function makeDay(float $y, float $m, float $d): float
    {
        if (!is_finite($y) || !is_finite($m) || !is_finite($d)) {
            return NAN;
        }
        [$y, $m, $d] = [self::trunc($y), self::trunc($m), self::trunc($d)];
        $ym = $y + floor($m / 12);
        if (abs($ym) > 400000 || abs($d) > 1e9) {
            return NAN;
        }
        $mn = fmod($m, 12);
        if ($mn < 0) {
            $mn += 12;
        }
        return self::daysFromCivil((int) $ym, (int) $mn + 1, 1) + $d - 1;
    }

    /** MakeTime: ms of hours, minutes, seconds and ms (any of them may overflow). */
    public static function makeTime(float $h, float $mi, float $s, float $ms): float
    {
        if (!is_finite($h) || !is_finite($mi) || !is_finite($s) || !is_finite($ms)) {
            return NAN;
        }
        return self::trunc($h) * 3600000 + self::trunc($mi) * 60000 + self::trunc($s) * 1000 + self::trunc($ms);
    }

    public static function makeDate(float $day, float $time): float
    {
        if (!is_finite($day) || !is_finite($time)) {
            return NAN;
        }
        return $day * self::MS_PER_DAY + $time;
    }

    /** Days since 1970-01-01 of a proleptic Gregorian date, month 1-12 (H. Hinnant's days_from_civil). */
    public static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return $era * 146097 + $doe - 719468;
    }

    /** @return array{0: int, 1: int, 2: int} year, month 0-11, day 1-31 of a day number (civil_from_days) */
    public static function civilFromDays(int $z): array
    {
        $z += 719468;
        $era = intdiv($z >= 0 ? $z : $z - 146096, 146097);
        $doe = $z - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $d = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $m = $mp < 10 ? $mp + 3 : $mp - 9;
        return [$yoe + $era * 400 + ($m <= 2 ? 1 : 0), $m - 1, $d];
    }

    /**
     * The fields of a (valid) time in ms, in the order the setters take them; `day` is the weekday (0 = Sunday).
     * Pass localTime($t) for the local fields.
     *
     * @return array{year: int, month: int, date: int, hours: int, minutes: int, seconds: int, ms: int, day: int}
     */
    public static function fields(float $t): array
    {
        $day = (int) floor($t / self::MS_PER_DAY);
        $inDay = (int) ($t - $day * self::MS_PER_DAY);
        [$y, $m, $d] = self::civilFromDays($day);
        return [
            'year' => $y, 'month' => $m, 'date' => $d,
            'hours' => intdiv($inDay, 3600000), 'minutes' => intdiv($inDay, 60000) % 60,
            'seconds' => intdiv($inDay, 1000) % 60, 'ms' => $inDay % 1000,
            'day' => (($day % 7) + 11) % 7, // 1970-01-01 was a Thursday
        ];
    }

    // ============================================================ local time

    /** The local timezone's offset from UTC at the UTC time $t, in ms (east of Greenwich is positive). */
    public static function offsetAt(float $t): int
    {
        $name = date_default_timezone_get();
        $zone = self::$zones[$name] ??= new \DateTimeZone($name);
        try {
            return $zone->getOffset(new \DateTimeImmutable('@' . (int) floor($t / 1000))) * 1000;
        } catch (\Exception) {
            return 0;
        }
    }

    public static function localTime(float $t): float
    {
        return $t + self::offsetAt($t);
    }

    /** UTC: the UTC time of a local time; in a DST gap or overlap, the offset from before the change is used (as the spec and V8 do). */
    public static function utc(float $local): float
    {
        if (!is_finite($local)) {
            return NAN;
        }
        // the offsets a day before and after (at most one change between them)
        $before = self::offsetAt($local - self::MS_PER_DAY);
        $after = self::offsetAt($local + self::MS_PER_DAY);
        if ($before !== $after && self::offsetAt($local - $before) !== $before && self::offsetAt($local - $after) === $after) {
            return $local - $after;
        }
        return $local - $before;
    }

    // ============================================================ parsing

    /**
     * Date.parse: the ISO 8601 forms of the spec (`2026-10-20` is UTC midnight, `2026-10-20T10:00` without an offset is local time;
     * a space may stand for the T), else the usual forms PHP knows (`Oct 20 2026 10:00`, `2026/10/20`, what toString / toUTCString give),
     * but not relative ones (`tomorrow`, `+1 day`). NAN when it is not a date.
     */
    public static function parse(string $s): float
    {
        $s = trim($s);
        if ($s === '' || strlen($s) > 100) {
            return NAN;
        }
        $iso = '/^([+-]\d{6}|\d{4})(?:-(\d\d)(?:-(\d\d))?)?(?:[T ](\d\d):(\d\d)(?::(\d\d)(?:[.,](\d{1,9}))?)?\s*(Z|[+-]\d\d:?\d\d)?)?$/i';
        if (preg_match($iso, $s, $m)) {
            if ($m[1] === '-000000') {
                return NAN;
            }
            $get = fn (int $i, int $default) => isset($m[$i]) && $m[$i] !== '' ? (int) $m[$i] : $default;
            [$y, $mo, $d, $h, $mi, $sec] = [(int) $m[1], $get(2, 1), $get(3, 1), $get(4, 0), $get(5, 0), $get(6, 0)];
            $ms = isset($m[7]) && $m[7] !== '' ? (int) substr(str_pad($m[7], 3, '0'), 0, 3) : 0;
            //a day up to 31 in any month rolls over (2024-02-30 is March 1), as V8 does
            if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $mi > 59 || $sec > 59
                || $h > 24 || ($h === 24 && ($mi > 0 || $sec > 0 || $ms > 0))) {
                return NAN;
            }
            $t = self::makeDate(self::makeDay($y, $mo - 1, $d), self::makeTime($h, $mi, $sec, $ms));
            $zone = $m[8] ?? '';
            if (strtoupper($zone) === 'Z') {
                // UTC
            } elseif ($zone !== '') {
                $digits = str_replace(':', '', substr($zone, 1));
                $t -= ($zone[0] === '-' ? -1 : 1) * ((int) substr($digits, 0, 2) * 60 + (int) substr($digits, 2, 2)) * 60000;
            } elseif (isset($m[4]) && $m[4] !== '') {
                $t = self::utc($t); // a date and time without offset: local time
            }
            return self::clip($t);
        }

        // a leading weekday name is relative for PHP ("next Tuesday"); JS ignores it
        $s = (string) preg_replace('/^(sun|mon|tue|wed|thu|fri|sat)[a-z]*\.?,?\s+/i', '', $s);
        $info = date_parse($s);
        if ($info['error_count'] > 0 || isset($info['relative']) || $info['year'] === false || $info['month'] === false || $info['day'] === false) {
            return NAN;
        }
        try {
            $dt = new \DateTimeImmutable($s, new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return NAN;
        }
        return self::clip((float) $dt->format('U') * 1000 + (int) $dt->format('v'));
    }


    // ============================================================ formatting

    /** toISOString: `2026-10-20T18:05:03.000Z` (years outside 0-9999 as ±YYYYYY); null for an Invalid Date. */
    public function toIso(): ?string
    {
        if (!$this->isValid()) {
            return null;
        }
        $f = self::fields($this->time);
        $year = $f['year'] >= 0 && $f['year'] <= 9999
            ? sprintf('%04d', $f['year'])
            : ($f['year'] < 0 ? '-' : '+') . sprintf('%06d', abs($f['year']));
        return sprintf('%s-%02d-%02dT%02d:%02d:%02d.%03dZ', $year, $f['month'] + 1, $f['date'], $f['hours'], $f['minutes'], $f['seconds'], $f['ms']);
    }

    //CLD: 2026-10-20T21:40:00+03:00: for hosts that want their own text (Interpreter option dateFormat)
    /** The date in a PHP date() format, in local time (PHP's default timezone); null for an Invalid Date. */
    public function formatPhp(string $format): ?string
    {
        if (!$this->isValid()) {
            return null;
        }
        $ms = (int) $this->time;
        $seconds = intdiv($ms, 1000) - ($ms % 1000 < 0 ? 1 : 0);
        $dt = \DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', $seconds, $ms - $seconds * 1000));
        if ($dt === false) {
            return null;
        }
        return $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($format);
    }

    /**
     * The string forms: `string` (toString: `Tue Oct 20 2026 21:05:03 GMT+0300`, without the timezone's name), `date` (toDateString),
     * `time` (toTimeString), `utc` (toUTCString: `Tue, 20 Oct 2026 18:05:03 GMT`), `iso` (toISOString),
     * `localeDate` / `localeTime` / `locale` (toLocale*String, always like en-US: `10/20/2026, 9:05:03 PM`). `Invalid Date` for an Invalid Date.
     */
    public function format(string $form = 'string'): string
    {
        if (!$this->isValid()) {
            return 'Invalid Date';
        }
        if ($form === 'iso') {
            return (string) $this->toIso();
        }
        $utc = $form === 'utc';
        $f = self::fields($utc ? $this->time : self::localTime($this->time));
        $year = ($f['year'] < 0 ? '-' : '') . sprintf('%04d', abs($f['year']));
        $offset = intdiv(self::offsetAt($this->time), 60000);
        $zone = sprintf('GMT%s%02d%02d', $offset < 0 ? '-' : '+', intdiv(abs($offset), 60), abs($offset) % 60);
        $date = sprintf('%s %s %02d %s', self::DAYS[$f['day']], self::MONTHS[$f['month']], $f['date'], $year);
        $time = sprintf('%02d:%02d:%02d %s', $f['hours'], $f['minutes'], $f['seconds'], $zone);
        $localeDate = sprintf('%d/%d/%d', $f['month'] + 1, $f['date'], $f['year']);
        $localeTime = sprintf('%d:%02d:%02d %s', ($f['hours'] + 11) % 12 + 1, $f['minutes'], $f['seconds'], $f['hours'] < 12 ? 'AM' : 'PM');
        return match ($form) {
            'date' => $date,
            'time' => $time,
            'utc' => sprintf('%s, %02d %s %s %02d:%02d:%02d GMT', self::DAYS[$f['day']], $f['date'], self::MONTHS[$f['month']], $year, $f['hours'], $f['minutes'], $f['seconds']),
            'localeDate' => $localeDate,
            'localeTime' => $localeTime,
            'locale' => $localeDate . ', ' . $localeTime,
            default => $date . ' ' . $time,
        };
    }
}
