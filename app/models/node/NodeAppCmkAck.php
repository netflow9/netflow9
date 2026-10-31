<?php

/*
    CLD: 2026-11-01T08:00:00+02:00: new step: Checkmk Acknowledge & Downtime - acknowledgements of problems and scheduled downtimes. Operations (setting `operation`):
	ackHost         a host's problem acknowledged (POST domain-types/acknowledge/collections/host)
	ackService      services' problems acknowledged (POST .../acknowledge/collections/service, one per service)
	downHost        a downtime of a host (POST domain-types/downtime/collections/host): from now (or Start) for Minutes
	downService     a downtime of services (POST .../downtime/collections/service: service_descriptions)
	downtimes       the downtimes (GET domain-types/downtime/collections/all; of a host)
	deleteDowntime  a downtime removed (POST domain-types/downtime/actions/delete/invoke: by id, on the credential's site)
    Services: one per line (their names may hold commas), or a list (of names, or of objects with `description` / `service`)
*/

namespace models\node;

class NodeAppCmkAck extends NodeAppCheckmk {
    public static $name = 'cmk-ack';
    public static $label = 'Checkmk Acknowledge & Downtime';
    public static $icon = 'bi-bell-slash';
    public static $defaultOperation = 'ackService';
    public static $defaultVariable = 'ack';

    const OPERATIONS = [
	'ackHost' => 'Acknowledge a host problem',
	'ackService' => 'Acknowledge service problems',
	'downHost' => 'Schedule a host downtime',
	'downService' => 'Schedule a service downtime',
	'downtimes' => 'List downtimes',
	'deleteDowntime' => 'Remove a downtime',
    ];
    const LISTS = [ 'downtimes' ];
    const MAX_MINUTES = 60 * 24 * 366;

    protected static function operationFields() {
	$acks = [ 'ackHost', 'ackService' ];
	$downs = [ 'downHost', 'downService' ];
	return [
	    self::field('host', 'Host', array_merge($acks, $downs, [ 'downtimes' ]), [ 'help' => 'Its name (List downtimes: empty: all)',
		'requiredIf' => [ 'if' => 'operation', 'in' => array_merge($acks, $downs) ], 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('services', 'Services', [ 'ackService', 'downService' ], [ 'type' => 'textarea', 'required' => true, 'help' => 'Their names, one per line, or a list',
		'ui' => [ 'rows' => 2, 'expression' => true ] ]),
	    self::field('comment', 'Comment', array_merge($acks, $downs), [ 'required' => true, 'help' => 'Shown in Checkmk', 'ui' => [ 'expression' => 'text' ] ]),
	    self::field('sticky', 'Sticky', $acks, [ 'type' => 'switch', 'default' => true, 'help' => 'Kept until the state is OK again (else removed at any state change)', 'ui' => [ 'width' => 4 ] ]),
	    self::field('notify', 'Notify', $acks, [ 'type' => 'switch', 'default' => false, 'help' => 'Send the acknowledgement notification', 'ui' => [ 'width' => 4 ] ]),
	    self::field('persistent', 'Persistent comment', $acks, [ 'type' => 'switch', 'default' => false, 'ui' => [ 'width' => 4 ] ]),
	    self::field('start', 'Start', $downs, [ 'help' => 'A date / time, or e.g. +1 hour, tomorrow 22:00; empty: now', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('minutes', 'Minutes', $downs, [ 'type' => 'number', 'default' => 60, 'required' => true,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_MINUTES, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_MINUTES ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	    self::field('downtimeId', 'Downtime', [ 'deleteDowntime' ], [ 'required' => true, 'help' => 'Its id (List downtimes)', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$comment = function() use ($text) {
	    $c = mb_substr($text('comment'), 0, 1000);
	    if ($c === '')
		throw new \RuntimeException('Comment: empty');
	    return $c;
	};
	$ack = fn() => [ 'sticky' => (bool)$this->cfg('sticky', true), 'notify' => (bool)$this->cfg('notify', false), 'persistent' => (bool)$this->cfg('persistent', false) ];
	$services = function() use ($value) {
	    $list = self::lines($value('services'));
	    if (!$list)
		throw new \RuntimeException('Services: empty');
	    return $list;
	};
	$window = function() use ($text) {
	    $start = $text('start') === '' ? time() : strtotime($text('start'));
	    if ($start === false)
		throw new \RuntimeException('Start: not a date / time: `' . mb_substr($text('start'), 0, 64) . '`');
	    $minutes = max(1, min(self::MAX_MINUTES, (int)$this->cfg('minutes', 60)));
	    return [ gmdate('Y-m-d\TH:i:s\Z', $start), gmdate('Y-m-d\TH:i:s\Z', $start + $minutes * 60), $minutes ];
	};
	switch($operation) {
	    case 'ackHost':
		$h = self::host($value('host'));
		$r = $this->change('POST', '/domain-types/acknowledge/collections/host', [ 'acknowledge_type' => 'host', 'host_name' => $h, 'comment' => $comment() ] + $ack());
		return [ $r, "{$h} acknowledged" ];
	    case 'ackService':
		$h = self::host($value('host'));
		$list = $services();
		foreach($list as $s)
		    $this->change('POST', '/domain-types/acknowledge/collections/service', [ 'acknowledge_type' => 'service', 'host_name' => $h, 'service_description' => $s, 'comment' => $comment() ] + $ack());
		return [ [ 'host_name' => $h, 'services' => $list, 'count' => count($list) ], \util\Format::plural(count($list), 'service') . " of {$h} acknowledged" ];
	    case 'downHost':
		$h = self::host($value('host'));
		[ $from, $to, $minutes ] = $window();
		$r = $this->change('POST', '/domain-types/downtime/collections/host', [ 'downtime_type' => 'host', 'host_name' => $h, 'start_time' => $from, 'end_time' => $to, 'comment' => $comment() ]);
		return [ [ 'host_name' => $h, 'start' => $from, 'end' => $to, 'answer' => $r ], "{$h}: a downtime of {$minutes} min from {$from}" ];
	    case 'downService':
		$h = self::host($value('host'));
		$list = $services();
		[ $from, $to, $minutes ] = $window();
		$r = $this->change('POST', '/domain-types/downtime/collections/service', [ 'downtime_type' => 'service', 'host_name' => $h, 'service_descriptions' => $list,
		    'start_time' => $from, 'end_time' => $to, 'comment' => $comment() ]);
		return [ [ 'host_name' => $h, 'services' => $list, 'start' => $from, 'end' => $to, 'answer' => $r ],
		    \util\Format::plural(count($list), 'service') . " of {$h}: a downtime of {$minutes} min" ];
	    case 'downtimes':
		return $this->collection('/domain-types/downtime/collections/all', $text('host') !== '' ? [ 'host_name' => self::host($value('host')) ] : [], 'downtime');
	    case 'deleteDowntime':
		$id = $text('downtimeId');
		if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id))
		    throw new \RuntimeException("Downtime: not an id: `" . mb_substr($id, 0, 64) . '`');
		return [ $this->change('POST', '/domain-types/downtime/actions/delete/invoke', [ 'delete_type' => 'by_id', 'downtime_id' => $id, 'site_id' => trim((string)$this->cmkSite()) ]),
		    "downtime {$id} removed" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the credential's site (a downtime is removed on it)
    protected function cmkSite() {
	return \models\Credential::findFor($this->exec, (string)$this->cfg('credential', ''), 'checkmk')?->site;
    }
}
