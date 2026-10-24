<?php
/*
    config.mode selects which of the other fields apply:
	interval: every + every_unit
	    every N minutes from midnight (every 15: :00 :15 :30 :45); at :00 of every Nth hour from midnight;
	    at 00:00 of every Nth day (counted from 1970-01-01)
	daily:    time                   HH:MM
	weekly:   weekdays + time        cron day-of-week numbers (0 = Sunday)
	monthly:  day + time             months without the day are skipped
	cron:     cron                   minute hour day-of-month month day-of-week (util\Cron)
*/

namespace models;

class WfSchedule {

    public static function schema(array $defaults = []) {
	$fields = [
	    [
		'key' => 'mode',
		'type' => 'select',
		'label' => 'Run',
		'required' => true,
		'default' => 'daily',
		'choices' => [
		    'interval' => 'At a regular interval',
		    'daily' => 'Every day',
		    'weekly' => 'Every week',
		    'monthly' => 'Every month',
		    'cron' => 'Advanced (cron expression)',
		],
	    ],

	    //interval
	    [
		'key' => 'every',
		'type' => 'number',
		'label' => 'Every',
		'default' => 15,
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'interval' ] ],
		'validate' => [
		    [ 'inRange', 'lower' => 1, 'skipEmpty' => true, 'message' => 'must be 1 or more' ],
		],
		'ui' => [
		    'step' => 1,
		    'width' => 6,
		    'showIf' => [
			'key' => 'mode', 'operator' => 'eq', 'value' => 'interval'
		    ]
		],
	    ],
	    [
		'key' => 'every_unit',
		'type' => 'select',
		'label' => 'Units',
		'default' => 'minutes',
		'choices' => [
		    'minutes' => 'Minutes',
		    'hours' => 'Hours',
		    'days' => 'Days'
		],
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'interval' ] ],
		'ui' => [
		    'width' => 6,
		    'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'interval' ]
		],
	    ],

	    [
		'key' => 'weekdays',
		'type' => 'select',
		'multiple' => true,
		'label' => 'On',
		'default' => [ '1' ],
		'choices' => [
		    '1' => 'Monday',
		    '2' => 'Tuesday',
		    '3' => 'Wednesday',
		    '4' => 'Thursday',
		    '5' => 'Friday',
		    '6' => 'Saturday',
		    '0' => 'Sunday'
		],
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'weekly' ], 'message' => 'Choose the days of the week' ],
		'ui' => [
		    //CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by util\SmartForm::showIf)
		    'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'weekly' ],
		],
	    ],

	    //daily, weekly, monthly; placed before `day` so it stays left-aligned when `day` is hidden
	    [
		'key' => 'time',
		'type' => 'text',
		'label' => 'At',
		'default' => '09:00',
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'daily', 'weekly', 'monthly' ] ],
		'validate' => [
		    [ 'regex', 'pattern' => '^([01]?\d|2[0-3]):[0-5]\d$', 'skipEmpty' => true, 'message' => 'the time must be HH:MM' ],
		],
		'ui' => [
		    'inputType' => 'time',
		    'width' => 6,
		    'showIf' => [ 'key' => 'mode', 'operator' => 'in', 'value' => [ 'daily', 'weekly', 'monthly' ] ]
		],
	    ],

	    //monthly
	    [
		'key' => 'day',
		'type' => 'number',
		'label' => 'On day',
		'default' => 1,
		'help' => 'Months without this day are skipped',
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'monthly' ] ],
		'validate' => [
		    [ 'inRange', 'lower' => 1, 'upper' => 31, 'skipEmpty' => true, 'message' => 'the day of the month must be 1 - 31' ],
		],
		'ui' => [
		    'step' => 1,
		    'width' => 6,
		    'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'monthly' ]
		],
	    ],

	    //advanced
	    [
		'key' => 'cron',
		'type' => 'text',
		'label' => 'Cron expression',
		'help' => 'minute hour day-of-month month day-of-week, e.g. "30 8 * * 1-5" = weekdays at 08:30',
		'requiredIf' => [ 'if' => 'mode', 'in' => [ 'cron' ] ],
		'validate' => [
		    [ 'cron', 'skipEmpty' => true, 'message' => 'Use 5 valid fields: minute hour day-of-month month day-of-week' ],
		],
		'ui' => [
		    'placeholder' => '*/15 * * * *',
		    //CLD: 2026-10-14T20:17:14+03:00: a condition array (app.smartform evaluates it; was JS made by util\SmartForm::showIf)
		    'showIf' => [ 'key' => 'mode', 'operator' => 'eq', 'value' => 'cron' ],
		    'pattern' => '^\s*[0-9A-Za-z*,/-]+(\s+[0-9A-Za-z*,/-]+){4}\s*$',
		    'patternMessage' => 'Use 5 fields: minute hour day-of-month month day-of-week',
		],
	    ],
	];

	foreach($fields as &$f) {
	    if (array_key_exists($f['key'], $defaults))
		$f['default'] = $defaults[$f['key']];
	}
	unset($f);
	return $fields;
    }

    //does the schedule $cfg match the minute of $t (local time; seconds ignored)?
    public static function matches(array $cfg, \DateTimeInterface $t) {
	$minute = (int)$t->format('G') * 60 + (int)$t->format('i');
	$atTime = function() use ($cfg, $t) {
	    return preg_match('/^(\d{1,2}):(\d{2})$/', trim((string)($cfg['time'] ?? '')), $m) === 1
		&& (int)$m[1] === (int)$t->format('G') && (int)$m[2] === (int)$t->format('i');
	};

	switch($cfg['mode'] ?? 'daily') {
	    case 'interval':
		$every = (int)($cfg['every'] ?? 0);
		if ($every < 1)
		    return false;
		switch($cfg['every_unit'] ?? 'minutes') {
		    case 'minutes':
			return $minute % $every === 0;
		    case 'hours':
			return (int)$t->format('i') === 0 && (int)$t->format('G') % $every === 0;
		    case 'days':
			$days = intdiv((new \DateTimeImmutable($t->format('Y-m-d') . ' 00:00:00', new \DateTimeZone('UTC')))->getTimestamp(), 86400);
			return $minute === 0 && $days % $every === 0;
		}
		return false;
	    case 'daily':
		return $atTime();
	    case 'weekly':
		$days = array_map('strval', (array)($cfg['weekdays'] ?? []));
		return in_array($t->format('w'), $days, true) && $atTime();
	    case 'monthly':
		return (int)($cfg['day'] ?? 0) === (int)$t->format('j') && $atTime();
	    case 'cron':
		try {
		    return (new \util\Cron((string)($cfg['cron'] ?? '')))->matches($t);
		} catch (\InvalidArgumentException $e) {
		    return false;
		}
	}
	return false;
    }

    //only what the schema's rules cannot check (they skip lists): the weekly days; null when valid (was: every check of the
    //schedule, now rules of schema())
    public static function validate(array $cfg) {
	if (($cfg['mode'] ?? '') !== 'weekly')
	    return null;
	//CLD: 2026-10-14T14:18:23+03:00: only unknown days (no days: the weekdays field's requiredIf rule)
	$days = array_map('strval', (array)($cfg['weekdays'] ?? []));
	if (array_diff($days, [ '0', '1', '2', '3', '4', '5', '6' ]))
	    return 'unknown day of the week';
	return null;
    }
}
