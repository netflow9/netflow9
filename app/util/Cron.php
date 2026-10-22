<?php

//CLD: 2026-10-08T22:47:34+03:00: new utility: 5-field cron expressions (minute hour day-of-month month day-of-week), used by the Schedule
//trigger's advanced mode. Each field: *, a value, a-b, */n, a-b/n, lists (1,15,30); months jan..dec, weekdays sun..sat (0 and 7 = Sunday).
//As in cron, when both day-of-month and day-of-week are restricted, a day matching either one matches.
//
//  $cron = new \util\Cron('*/15 8-18 * * mon-fri');
//  $cron->matches(new \DateTimeImmutable());     //true on 08:00, 08:15, ... 18:45, Monday to Friday
//  \util\Cron::isValid('61 * * * *');            //false

namespace util;

class Cron {
    const FIELDS = [
	//name => [ min, max ]
	'minute' => [ 0, 59 ],
	'hour' => [ 0, 23 ],
	'day' => [ 1, 31 ],
	'month' => [ 1, 12 ],
	'weekday' => [ 0, 7 ],
    ];

    const NAMES = [
	'month' => [ 'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12 ],
	'weekday' => [ 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6 ],
    ];

    //field => list of allowed values (as keys)
    protected $sets = [];
    //day-of-month / day-of-week were given (not *)
    protected $dayRestricted = false;
    protected $weekdayRestricted = false;

    public function __construct($expression) {
	$parts = preg_split('/\s+/', trim((string)$expression));
	if (count($parts) != 5)
	    throw new \InvalidArgumentException('a cron expression has 5 fields: minute hour day-of-month month day-of-week');

	$i = 0;
	foreach(self::FIELDS as $field => [ $min, $max ]) {
	    $this->sets[$field] = $this->parseField($field, strtolower($parts[$i++]), $min, $max);
	}
	//7 is Sunday too
	if (isset($this->sets['weekday'][7]))
	    $this->sets['weekday'][0] = true;

	$this->dayRestricted = $parts[2] !== '*';
	$this->weekdayRestricted = $parts[4] !== '*';
    }

    public static function isValid($expression) {
	try {
	    new self($expression);
	    return true;
	} catch (\InvalidArgumentException $e) {
	    return false;
	}
    }

    //does the minute of $t match (seconds are ignored)
    public function matches(\DateTimeInterface $t) {
	if (!isset($this->sets['minute'][(int)$t->format('i')]) || !isset($this->sets['hour'][(int)$t->format('G')]) || !isset($this->sets['month'][(int)$t->format('n')]))
	    return false;

	$day = isset($this->sets['day'][(int)$t->format('j')]);
	$weekday = isset($this->sets['weekday'][(int)$t->format('w')]);
	if ($this->dayRestricted && $this->weekdayRestricted)
	    return $day || $weekday;
	return $day && $weekday;
    }

    protected function parseField($field, $text, $min, $max) {
	$set = [];
	foreach(explode(',', $text) as $item) {
	    if ($item === '')
		throw new \InvalidArgumentException("empty item in the {$field} field");

	    $step = 1;
	    if (strpos($item, '/') !== false) {
		[ $item, $stepText ] = explode('/', $item, 2);
		if (!ctype_digit($stepText) || (int)$stepText < 1)
		    throw new \InvalidArgumentException("invalid step `{$stepText}` in the {$field} field");
		$step = (int)$stepText;
	    }

	    if ($item === '*') {
		[ $from, $to ] = [ $min, $max ];
	    } elseif (strpos($item, '-') !== false) {
		[ $a, $b ] = explode('-', $item, 2);
		[ $from, $to ] = [ $this->value($field, $a, $min, $max), $this->value($field, $b, $min, $max) ];
		if ($from > $to)
		    throw new \InvalidArgumentException("invalid range `{$item}` in the {$field} field");
	    } else {
		$from = $this->value($field, $item, $min, $max);
		//a/n: from a to the end
		$to = $step > 1 ? $max : $from;
	    }

	    for ($v = $from; $v <= $to; $v += $step)
		$set[$v] = true;
	}
	return $set;
    }

    protected function value($field, $text, $min, $max) {
	if (isset(self::NAMES[$field][$text]))
	    return self::NAMES[$field][$text];
	if (!ctype_digit($text) || (int)$text < $min || (int)$text > $max)
	    throw new \InvalidArgumentException("invalid value `{$text}` in the {$field} field ({$min}-{$max})");
	return (int)$text;
    }
}
