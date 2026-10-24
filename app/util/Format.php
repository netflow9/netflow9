<?php

/*
    CLD: 2026-10-24T16:30:00+03:00: new: formatters for views and messages (static; '' for no value):
	numbers     number(), compact() (1.2K), percent(), ordinal() (2nd), plural() (3 rows)
	sizes       bytes() (1.5 MB), toBytes() ("2M" -> 2097152)
	dates       toDateTime() (UTCDateTime / DateTimeInterface / ObjectId / seconds / text -> the app timezone), date(), datetime() in the account's formats
		    (Account `date_format` / `datetime_format`; empty, or no account (the daemon, commands): app.format.date / app.format.datetime),
		    timeago() (5 minutes ago, yesterday, in 2 hours), timeagoHtml() (a <time> element), duration() (milliseconds -> 2m 13s)
*/
namespace util;

class Format {

    // ------------------------------------------------------------------------------------
    //numbers

    //1234567.891 -> "1,234,568" (decimals 0) / "1,234,567.89" (2)
    public static function number($n, int $decimals = 0, string $point = '.', string $thousands = ','): string {
	if ($n === null || $n === '')
	    return '';
	return number_format((float)$n, $decimals, $point, $thousands);
    }

    //1234 -> "1.2K", 3400000 -> "3.4M", 999 -> "999" (K, M, B, T; trailing zeros dropped)
    public static function compact($n, int $decimals = 1): string {
	if ($n === null || $n === '')
	    return '';
	$n = (float)$n;
	$units = [ '', 'K', 'M', 'B', 'T' ];
	$i = 0;
	while (abs($n) >= 1000 && $i < count($units) - 1) {
	    $n /= 1000;
	    $i++;
	}
	//999.95K rounds to 1000K: 1M
	if ($i > 0 && $i < count($units) - 1 && abs(round($n, $decimals)) >= 1000) {
	    $n /= 1000;
	    $i++;
	}
	return $i === 0 ? self::number($n) : self::trimZeros(number_format($n, $decimals)) . $units[$i];
    }

    //0.4567 -> "45.7%"; $ratio false: 45.67 -> "45.7%" (trailing zeros dropped)
    public static function percent($n, int $decimals = 1, bool $ratio = true): string {
	if ($n === null || $n === '')
	    return '';
	return self::trimZeros(number_format((float)$n * ($ratio ? 100 : 1), $decimals)) . '%';
    }

    //1 -> "1st", 2 -> "2nd", 11 -> "11th", 23 -> "23rd"
    public static function ordinal($n): string {
	if ($n === null || $n === '')
	    return '';
	$n = (int)$n;
	$last = abs($n) % 100;
	$suffix = $last >= 11 && $last <= 13 ? 'th' : ([ 1 => 'st', 2 => 'nd', 3 => 'rd' ][$last % 10] ?? 'th');
	return $n . $suffix;
    }

    //(1, 'row') -> "1 row", (1500, 'row') -> "1,500 rows", (3, 'entry') -> "3 entries"; $plural: the plural when not regular
    public static function plural($n, string $singular, ?string $plural = null): string {
	$n = (float)($n ?? 0);
	if ($plural === null) {
	    if (preg_match('/[^aeiou]y$/i', $singular))
		$plural = substr($singular, 0, -1) . 'ies';
	    elseif (preg_match('/(s|x|z|ch|sh)$/i', $singular))
		$plural = $singular . 'es';
	    else
		$plural = $singular . 's';
	}
	return self::number($n, floor($n) == $n ? 0 : 1) . ' ' . ($n == 1 ? $singular : $plural);
    }

    // ------------------------------------------------------------------------------------
    //sizes

    //1536 -> "1.5 KB", 0 -> "0 B"; binary (1024) or decimal (1000) units, KB / MB / GB / TB / PB; trailing zeros dropped
    public static function bytes($bytes, int $decimals = 1, bool $binary = true): string {
	if ($bytes === null || $bytes === '' || !is_numeric($bytes))
	    return '';
	$bytes = (float)$bytes;
	$base = $binary ? 1024 : 1000;
	$units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
	$i = 0;
	while (abs($bytes) >= $base && $i < count($units) - 1) {
	    $bytes /= $base;
	    $i++;
	}
	//1023.96 KB rounds to 1024 KB: 1 MB
	if ($i > 0 && $i < count($units) - 1 && abs(round($bytes, $decimals)) >= $base) {
	    $bytes /= $base;
	    $i++;
	}
	return ($i === 0 ? (string)(int)$bytes : self::trimZeros(number_format($bytes, $decimals))) . ' ' . $units[$i];
    }

    //"2M" / "512 KB" / "1.5G" / "100" -> bytes (1024 units, as php.ini's); null when not a size
    public static function toBytes($size): ?int {
	if (is_int($size) || is_float($size))
	    return (int)$size;
	if (!is_string($size) || !preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgtp]?)(?:i?b)?\s*$/i', $size, $m))
	    return null;
	$power = strpos('kmgtp', strtolower($m[2]));
	return (int)round((float)$m[1] * ($m[2] === '' ? 1 : 1024 ** ($power + 1)));
    }

    // ------------------------------------------------------------------------------------
    //dates

    //UTCDateTime / DateTimeInterface / ObjectId (its creation time) / number (seconds; milliseconds when larger than 10^11) / text (anything DateTime reads)
    //-> a DateTimeImmutable in the app timezone; null when empty or not a date
    public static function toDateTime($d): ?\DateTimeImmutable {
	if ($d === null || $d === '' || $d === false)
	    return null;
	try {
	    if ($d instanceof \MongoDB\BSON\UTCDateTime)
		$t = \DateTimeImmutable::createFromMutable($d->toDateTime());
	    elseif ($d instanceof \DateTimeInterface)
		$t = \DateTimeImmutable::createFromInterface($d);
	    elseif ($d instanceof \MongoDB\BSON\ObjectId)
		$t = new \DateTimeImmutable('@' . $d->getTimestamp());
	    elseif (is_int($d) || is_float($d) || (is_string($d) && is_numeric($d))) {
		$s = (float)$d;
		if (abs($s) > 1e11)
		    $s /= 1000;
		$t = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $s));
		if ($t === false)
		    return null;
	    } elseif (is_string($d))
		$t = new \DateTimeImmutable($d);
	    else
		return null;
	} catch (\Exception $ex) {
	    return null;
	}
	return $t->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    //the account's date / datetime format (date() tokens): Account `date_format` / `datetime_format`, else app.format.date / app.format.datetime
    public static function dateFormat(): string {
	return self::accountFormat('date_format') ?: ((string)\V::app()->config('app.format.date') ?: 'Y-m-d');
    }

    public static function datetimeFormat(): string {
	return self::accountFormat('datetime_format') ?: ((string)\V::app()->config('app.format.datetime') ?: 'Y-m-d H:i:s');
    }

    //-> "2026-10-24" (the account's date format, or $format)
    public static function date($d, ?string $format = null): string {
	$t = self::toDateTime($d);
	return $t ? $t->format($format ?? self::dateFormat()) : '';
    }

    //-> "2026-10-24 14:05:00" (the account's datetime format, or $format)
    public static function datetime($d, ?string $format = null): string {
	$t = self::toDateTime($d);
	return $t ? $t->format($format ?? self::datetimeFormat()) : '';
    }

    //relative to now (or $now): "just now", "5 minutes ago", "about 1 hour ago", "yesterday", "3 days ago", "2 months ago", "1 year ago"; "in 2 hours", "tomorrow"
    //for the future; older (or later) than $maxDays: the date itself (datetime(), with $format)
    public static function timeago($d, $now = null, ?int $maxDays = null, ?string $format = null): string {
	$t = self::toDateTime($d);
	if (!$t)
	    return '';
	$now = self::toDateTime($now ?? time()) ?? new \DateTimeImmutable();
	$diff = $now->getTimestamp() - $t->getTimestamp();
	$future = $diff < 0;
	$s = abs($diff);
	//calendar days (yesterday: any time of the day before)
	$days = (int)$now->setTime(0, 0)->diff($t->setTime(0, 0))->days;
	if ($maxDays !== null && $days > $maxDays)
	    return self::datetime($t, $format);

	if ($s < 45)
	    return 'just now';
	if ($s < 90)
	    $text = '1 minute';
	elseif ($s < 45 * 60)
	    $text = round($s / 60) . ' minutes';
	elseif ($s < 90 * 60)
	    $text = 'about 1 hour';
	elseif ($s < 24 * 3600 && ($days === 0 || $s < 6 * 3600))
	    $text = min(23, round($s / 3600)) . ' hours';
	elseif ($days <= 1)
	    return $future ? 'tomorrow' : 'yesterday';
	elseif ($days < 30)
	    $text = $days . ' days';
	elseif ($days < 365)
	    $text = self::plural(max(1, min(11, round($days / 30))), 'month');
	else
	    $text = self::plural(intdiv($days, 365), 'year');
	return $future ? "in {$text}" : "{$text} ago";
    }

    //<time datetime="ISO 8601" title="the datetime">timeago()</time>; '' when not a date
    public static function timeagoHtml($d, $now = null): string {
	$t = self::toDateTime($d);
	if (!$t)
	    return '';
	return '<time datetime="' . \V::app()->html->escape($t->format(DATE_ATOM)) . '" title="' . \V::app()->html->escape(self::datetime($t)) . '">'
	    . \V::app()->html->escape(self::timeago($t, $now)) . '</time>';
    }

    //milliseconds -> "850 ms", "12.4 s", "2m 13s", "1h 05m", "3d 4h"
    public static function duration($ms): string {
	if ($ms === null || $ms === '' || !is_numeric($ms))
	    return '';
	$ms = max(0, (int)$ms);
	if ($ms < 1000)
	    return $ms . ' ms';
	if ($ms < 60000)
	    return self::trimZeros(number_format($ms / 1000, 1)) . ' s';
	$s = intdiv($ms, 1000);
	if ($s < 3600)
	    return intdiv($s, 60) . 'm ' . sprintf('%02d', $s % 60) . 's';
	if ($s < 86400)
	    return intdiv($s, 3600) . 'h ' . sprintf('%02d', intdiv($s % 3600, 60)) . 'm';
	return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
    }

    // ------------------------------------------------------------------------------------

    //"1.50" -> "1.5", "2.0" -> "2"
    private static function trimZeros(string $n): string {
	return strpos($n, '.') === false ? $n : rtrim(rtrim($n, '0'), '.');
    }

    //a format of the signed-in user's account (sys\Boot: rt.account); '' without one
    private static function accountFormat(string $key): string {
	$account = \V::app()->config('rt.account');
	return $account ? (string)($account->$key ?? '') : '';
    }
}
