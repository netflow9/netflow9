<?php

/*
    CLD: 2026-10-22T00:41:28+03:00: new step: Outlook Calendar - lists, creates, changes and deletes the events of an Outlook / Microsoft 365 calendar over Microsoft Graph, through
    an Outlook Calendar (Microsoft Graph) credential of the project (CredentialMicrosoftCalendar, provides `mscalendar`). As the Google Calendar step.
    Operations (setting `operation`):
	list       the events between two times (default: from now, 7 days), searched by text; recurring events as their single occurrences, by start time
	get        one event
	create     an event: title, description, location, start / end (or a duration), all day, time zone, attendees (invited), a Teams meeting, shown as (busy, free, ...)
	update     the settings given (empty ones are left as they are) of an event; attendees added
	delete     an event (attendees get a cancellation when you are the organizer)
	freeBusy   the busy periods of people / rooms between two times (getSchedule)
	calendars  the calendars of the account
    Times are ISO 8601 (2026-10-21T14:00:00+03:00) or "2026-10-21 14:00", read in the step's time zone (default: the app's, config app.timezone); all-day events take dates
    (2026-10-21). The times of the answers are in the step's time zone. The answer goes into one variable (default `calendar`).
    On error (Graph errors, e.g. not found, no access, a bad time) - fail the step, or continue on a second output `error`
*/

//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\MicrosoftIntegration\models): the Microsoft credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppOutlookCalendar extends NodeAppMicrosoft {
    //static data/structure
    public static $name = 'outlook-calendar';
    public static $label = 'Outlook Calendar';
    public static $icon = 'bi-calendar-event';
    public static $capability = 'mscalendar';
    public static $credentialLabel = 'Outlook Calendar credential';
    public static $credentialKinds = 'Outlook Calendar';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'calendar';

    const OPERATIONS = [
	'list' => 'List events',
	'get' => 'Get an event',
	'create' => 'Create an event',
	'update' => 'Update an event',
	'delete' => 'Delete an event',
	'freeBusy' => 'Find busy times',
	'calendars' => 'List the calendars',
    ];

    //the operations on an existing event, the ones writing an event's settings, the ones on a period
    const ON_EVENT = [ 'get', 'update', 'delete' ];
    const EDIT = [ 'create', 'update' ];
    const PERIOD = [ 'list', 'freeBusy' ];

    const SHOW_AS = [ '' => 'Leave as it is (new: busy)', 'busy' => 'Busy', 'tentative' => 'Tentative', 'free' => 'Free', 'oof' => 'Away (out of office)', 'workingElsewhere' => 'Working elsewhere' ];
    const BODY_TYPES = [ 'text' => 'Text', 'html' => 'HTML' ];

    const MAX_RESULTS = 500;
    const MAX_SCHEDULES = 20;

    const SELECT = 'id,subject,body,bodyPreview,location,start,end,isAllDay,showAs,isCancelled,isOrganizer,webLink,onlineMeeting,isOnlineMeeting,organizer,attendees,'
	. 'seriesMasterId,type,createdDateTime,lastModifiedDateTime,categories';

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line; shorter help (was: Empty: the account's calendar; or a calendar id (List the calendars gives them))
		'key' => 'calendarId',
		'type' => 'text',
		'label' => 'Calendar',
		'default' => '',
		'help' => 'Empty: the account\'s calendar; or a calendar id (List the calendars)',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'list', 'create' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: The event id, e.g. {{ calendar.id }} of a create, {{ calendar[0].id }} of a list)
		'key' => 'eventId',
		'type' => 'text',
		'label' => 'Event',
		'default' => '',
		'help' => 'The event id, e.g. {{ calendar.id }}, {{ calendar[0].id }}',
		'requiredIf' => [ 'if' => 'operation', 'in' => self::ON_EVENT ],
		'ui' => [ 'width' => 6, 'placeholder' => '{{ event.id }}', 'expression' => true, 'showIf' => $op(self::ON_EVENT) ],
	    ],

	    //list / freeBusy
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		'key' => 'timeMin',
		'type' => 'text',
		'label' => 'From',
		'default' => '',
		'help' => 'Empty: now; ISO 8601 or 2026-10-21 14:00',
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => '2026-10-21 00:00', 'expression' => true, 'showIf' => $op(self::PERIOD) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'timeMax',
		'type' => 'text',
		'label' => 'Until',
		'default' => '',
		'help' => 'Empty: 7 days after From',
		'ui' => [ 'width' => 3, 'placeholder' => '2026-10-28 00:00', 'expression' => true, 'showIf' => $op(self::PERIOD) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide; shorter help (was: Words in the title, location or the start of the description; empty: all)
		'key' => 'search',
		'type' => 'text',
		'label' => 'Search',
		'default' => '',
		'help' => 'Words in the title, location or description; empty: all',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: a limit (the last line)
		'key' => 'maxResults',
		'type' => 'number',
		'label' => 'Largest number of events',
		'required' => true,
		'default' => 50,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_RESULTS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_RESULTS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'people',
		'type' => 'text',
		'label' => 'People / rooms',
		'default' => '',
		'help' => 'Addresses separated by commas; empty: the account',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op([ 'freeBusy' ]) ],
	    ],

	    //create / update
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide, on a new line
		'key' => 'subject',
		'type' => 'text',
		'label' => 'Title',
		'default' => '',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 6 wide
		'key' => 'location',
		'type' => 'text',
		'label' => 'Location',
		'default' => '',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: ISO 8601 or 2026-10-21 14:00 (all day: 2026-10-21))
		'key' => 'start',
		'type' => 'text',
		'label' => 'Start',
		'default' => '',
		'help' => 'ISO 8601 or 2026-10-21 14:00',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => '2026-10-21 14:00', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6); shorter help (was: Empty: Start + Duration (all day: the same day))
		'key' => 'end',
		'type' => 'text',
		'label' => 'End',
		'default' => '',
		'help' => 'Empty: Start + Duration',
		'ui' => [ 'width' => 3, 'placeholder' => '2026-10-21 15:00', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'duration',
		'type' => 'number',
		'label' => 'Duration (minutes)',
		'required' => true,
		'default' => 60,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'from 1 minute' ] ],
		'ui' => [ 'width' => 3, 'step' => 5, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'allDay',
		'type' => 'switch',
		'label' => 'All day',
		'default' => false,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6), on a new line
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'showAs',
		'type' => 'select',
		'label' => 'Show as',
		'default' => '',
		'choices' => self::SHOW_AS,
		'ui' => [ 'advanced' => true, 'width' => 3, 'newRow' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'bodyType',
		'type' => 'select',
		'label' => 'Description as',
		'required' => true,
		'default' => 'text',
		'choices' => self::BODY_TYPES,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		'key' => 'teams',
		'type' => 'switch',
		'label' => 'Add a Teams meeting',
		'default' => false,
		'help' => 'Work / school accounts',
		'ui' => [ 'width' => 3, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: layout: 3 wide (was 6)
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'timeZone',
		'type' => 'text',
		'label' => 'Time zone',
		'default' => '',
		'help' => 'e.g. Europe/Bucharest; empty: the app\'s',
		'ui' => [ 'advanced' => true, 'width' => 3, 'placeholder' => 'Europe/Bucharest', 'expression' => true, 'hideIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'calendars' ] ],
	    ],
	    [
		'key' => 'description',
		'type' => 'textarea',
		'label' => 'Description',
		'default' => '',
		'ui' => [ 'rows' => 3, 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:29+03:00: shorter help (was: Addresses separated by commas (they get an invitation); update: added to the attendees)
		'key' => 'attendees',
		'type' => 'text',
		'label' => 'Attendees',
		'default' => '',
		'help' => 'Addresses separated by commas (invited); update: added',
		'ui' => [ 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],

	    static::timeoutField(60),
	    static::variableField(),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Outlook, e.g. not found, no access, a time that cannot be read)
	    static::onErrorField('Outlook errors, times not read'),
	];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	$fail = function($message) use (&$valid) {
	    $this->errors('config', $message);
	    $valid = false;
	};
	$tz = trim((string)$this->cfg('timeZone', ''));
	if ($tz !== '' && !str_contains($tz, '{{') && !in_array($tz, \DateTimeZone::listIdentifiers(), true))
	    $fail("Time zone: unknown `{$tz}` (e.g. Europe/Bucharest, UTC)");

	if ($this->cfg('operation', 'list') === 'update') {
	    $changed = false;
	    foreach([ 'subject', 'start', 'end', 'location', 'description', 'attendees', 'showAs' ] as $key)
		$changed = $changed || trim((string)$this->cfg($key, '')) !== '';
	    if (!$changed && !$this->cfg('teams', false))
		$fail('Update: give at least one setting to change (title, start, end, location, description, attendees, show as, Teams)');
	}
	return $valid;
    }

    //the type of an event object (eventInfo())
    public static function eventType() {
	return [ 'type' => 'object', 'info' => 'an event: { id, subject, description, location, start, end, allDay, timeZone, showAs, cancelled, link, teamsLink, organizer, attendees, '
	    . 'isOrganizer, seriesId, categories, created, updated }', 'fields' => [
	    'id' => [ 'type' => 'string' ], 'subject' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string', 'info' => 'as text' ], 'location' => [ 'type' => 'string' ],
	    'start' => [ 'type' => 'string', 'info' => 'ISO 8601 (all day: a date)' ], 'end' => [ 'type' => 'string', 'info' => 'ISO 8601 (all day: the last day)' ],
	    'allDay' => [ 'type' => 'boolean' ], 'timeZone' => [ 'type' => 'string' ], 'showAs' => [ 'type' => 'string' ], 'cancelled' => [ 'type' => 'boolean' ],
	    'link' => [ 'type' => 'string', 'info' => 'opens the event in Outlook on the web' ], 'teamsLink' => [ 'type' => 'string', 'info' => 'joins the Teams meeting' ],
	    'organizer' => [ 'type' => 'string' ], 'attendees' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'email' => [ 'type' => 'string' ],
		'name' => [ 'type' => 'string' ], 'response' => [ 'type' => 'string', 'info' => 'none, accepted, declined, tentativelyAccepted, ...' ], 'type' => [ 'type' => 'string' ] ] ] ],
	    'isOrganizer' => [ 'type' => 'boolean' ], 'seriesId' => [ 'type' => 'string', 'info' => 'the recurring series of an occurrence' ],
	    'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ], 'created' => [ 'type' => 'string' ], 'updated' => [ 'type' => 'string' ] ] ];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	return [ $name => match($config['operation'] ?? 'list') {
	    'list' => [ 'type' => 'array', 'items' => self::eventType(), 'info' => 'the events, by start time' ],
	    'delete' => [ 'type' => 'object', 'info' => '{ id, deleted }', 'fields' => [ 'id' => [ 'type' => 'string' ], 'deleted' => [ 'type' => 'boolean' ] ] ],
	    'freeBusy' => [ 'type' => 'object', 'info' => '{ from, until, people: { <address>: [ { start, end, status } ] } }', 'fields' => [ 'from' => [ 'type' => 'string' ],
		'until' => [ 'type' => 'string' ], 'people' => [ 'type' => 'object' ] ] ],
	    'calendars' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'id' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ],
		'owner' => [ 'type' => 'string' ], 'canEdit' => [ 'type' => 'boolean' ], 'isDefault' => [ 'type' => 'boolean' ] ] ], 'info' => 'the calendars of the account' ],
	    default => self::eventType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    //the step's time zone (asked once per run)
    protected $tz = null;

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$this->tz = $this->timeZone($text('timeZone'));
	return match($operation) {
	    'list' => $this->opList($text),
	    'get' => $this->opGet($text),
	    'create' => $this->opSave($text, $value, false),
	    'update' => $this->opSave($text, $value, true),
	    'delete' => $this->opDelete($text),
	    'freeBusy' => $this->opFreeBusy($text, $value),
	    'calendars' => $this->opCalendars(),
	};
    }

    //the answers' times in the step's time zone
    protected function tzHeader() {
	return [ 'Prefer: outlook.timezone="' . $this->tz->getName() . '"' ];
    }

    protected function calendarPath($text) {
	$id = $text('calendarId');
	return $id === '' ? '/me/calendar' : '/me/calendars/' . self::graphId($id, 'calendar');
    }

    protected function opList(\Closure $text) {
	[ $from, $until ] = self::period($text('timeMin'), $text('timeMax'), $this->tz);
	$max = max(1, min(self::MAX_RESULTS, (int)$this->cfg('maxResults', 50)));
	$search = mb_strtolower($text('search'));
	[ $items ] = $this->pages($this->calendarPath($text) . '/calendarView', [ 'startDateTime' => $from->format(DATE_RFC3339), 'endDateTime' => $until->format(DATE_RFC3339),
	    '$select' => self::SELECT, '$orderby' => 'start/dateTime', '$top' => min(100, $search !== '' ? 100 : $max) ], $search !== '' ? self::MAX_RESULTS : $max, $this->tzHeader());
	$events = [];
	foreach($items as $ev) {
	    $info = $this->eventInfo($ev);
	    if ($search !== '' && !str_contains(mb_strtolower($info['subject'] . ' ' . $info['location'] . ' ' . ($ev['bodyPreview'] ?? '') . ' ' . implode(' ', array_column($info['attendees'], 'email'))), $search))
		continue;
	    $events[] = $info;
	    if (count($events) >= $max)
		break;
	}
	return [ $events, count($events) . ' event(s) from ' . $from->format('Y-m-d H:i') . ' until ' . $until->format('Y-m-d H:i') ];
    }

    protected function opGet(\Closure $text) {
	$id = self::graphId($text('eventId'), 'event');
	$event = $this->eventInfo($this->api('GET', "/me/events/{$id}", [ '$select' => self::SELECT ], null, $this->tzHeader()));
	return [ $event, "`{$event['subject']}` on {$event['start']}" ];
    }

    protected function opSave(\Closure $text, \Closure $value, $update) {
	$body = [];
	$current = null;
	if ($update) {
	    $id = self::graphId($text('eventId'), 'event');
	    $current = $this->api('GET', "/me/events/{$id}", [ '$select' => 'attendees,isAllDay' ], null, $this->tzHeader());
	}
	$subject = $text('subject');
	if ($subject !== '')
	    $body['subject'] = $subject;
	elseif (!$update)
	    throw new \ErrorException('Outlook Calendar: create: the event needs a title');

	$start = $text('start');
	$allDay = (bool)$this->cfg('allDay', false);
	if ($start !== '') {
	    [ $body['start'], $body['end'] ] = self::times($start, $text('end'), $allDay, (int)$this->cfg('duration', 60), $this->tz);
	    $body['isAllDay'] = $allDay;
	} elseif (!$update) {
	    throw new \ErrorException('Outlook Calendar: create: the event needs a start');
	} elseif ($text('end') !== '') {
	    throw new \ErrorException('Outlook Calendar: update: an end needs a start too');
	}
	if (($location = $text('location')) !== '')
	    $body['location'] = [ 'displayName' => $location ];
	$description = \util\Expression::toText($value('description'));
	if (trim($description) !== '')
	    $body['body'] = [ 'contentType' => $this->cfg('bodyType', 'text') === 'html' ? 'html' : 'text', 'content' => $description ];
	$showAs = (string)$this->cfg('showAs', '');
	if ($showAs !== '' && isset(self::SHOW_AS[$showAs]))
	    $body['showAs'] = $showAs;
	$new = self::addresses($value('attendees'));
	if ($new) {
	    $attendees = (array)($current['attendees'] ?? []);
	    $have = array_map('strtolower', self::addressesOf($attendees));
	    foreach($new as $a) {
		if (!in_array(strtolower($a), $have, true))
		    $attendees[] = [ 'emailAddress' => [ 'address' => $a ], 'type' => 'required' ];
	    }
	    $body['attendees'] = array_values($attendees);
	}
	if ($this->cfg('teams', false)) {
	    $body['isOnlineMeeting'] = true;
	    $body['onlineMeetingProvider'] = 'teamsForBusiness';
	}

	if ($update) {
	    $event = $this->eventInfo($this->api('PATCH', "/me/events/{$id}", [], $body, $this->tzHeader()));
	    return [ $event, "`{$event['subject']}` updated: " . implode(', ', array_keys($body)) ];
	}
	$event = $this->eventInfo($this->api('POST', $this->calendarPath($text) . '/events', [], $body, $this->tzHeader()));
	return [ $event, "`{$event['subject']}` created on {$event['start']}" . ($event['attendees'] ? ', ' . count($event['attendees']) . ' attendee(s) invited' : '') ];
    }

    protected function opDelete(\Closure $text) {
	$id = self::graphId($text('eventId'), 'event');
	$this->api('DELETE', "/me/events/{$id}");
	return [ [ 'id' => rawurldecode($id), 'deleted' => true ], 'event deleted' ];
    }

    protected function opFreeBusy(\Closure $text, \Closure $value) {
	[ $from, $until ] = self::period($text('timeMin'), $text('timeMax'), $this->tz);
	$people = self::addresses($value('people'));
	if (!$people) {
	    $me = $this->api('GET', '/me', [ '$select' => 'mail,userPrincipalName' ]);
	    $people = [ (string)($me['mail'] ?? $me['userPrincipalName'] ?? '') ];
	}
	if (count($people) > self::MAX_SCHEDULES)
	    throw new \RuntimeException('more than ' . self::MAX_SCHEDULES . ' people / rooms');
	$tz = $this->tz->getName();
	$answer = $this->api('POST', '/me/calendar/getSchedule', [], [ 'schedules' => $people, 'availabilityViewInterval' => 30,
	    'startTime' => [ 'dateTime' => $from->format('Y-m-d\TH:i:s'), 'timeZone' => $tz ], 'endTime' => [ 'dateTime' => $until->format('Y-m-d\TH:i:s'), 'timeZone' => $tz ] ],
	    $this->tzHeader());
	$result = [ 'from' => $from->format(DATE_RFC3339), 'until' => $until->format(DATE_RFC3339), 'people' => [] ];
	$busy = 0;
	foreach((array)($answer['value'] ?? []) as $s) {
	    $who = (string)($s['scheduleId'] ?? '');
	    if (isset($s['error']['message']))
		throw new \RuntimeException("{$who}: " . $s['error']['message']);
	    $result['people'][$who] = array_map(fn($i) => [ 'start' => self::isoOf($i['start'] ?? [], $this->tz), 'end' => self::isoOf($i['end'] ?? [], $this->tz),
		'status' => (string)($i['status'] ?? 'busy') ], array_values(array_filter((array)($s['scheduleItems'] ?? []), fn($i) => ($i['status'] ?? 'busy') !== 'free')));
	    $busy += count($result['people'][$who]);
	}
	return [ $result, "{$busy} busy period(s) of " . count($result['people']) . ' people / rooms' ];
    }

    protected function opCalendars() {
	[ $items ] = $this->pages('/me/calendars', [ '$top' => 100 ], 500);
	$calendars = array_map(fn($c) => [ 'id' => (string)($c['id'] ?? ''), 'name' => (string)($c['name'] ?? ''), 'owner' => (string)($c['owner']['address'] ?? ''),
	    'canEdit' => !empty($c['canEdit']), 'isDefault' => !empty($c['isDefaultCalendar']) ], $items);
	return [ $calendars, count($calendars) . ' calendar(s)' ];
    }

    //the step's time zone, else the app's
    protected function timeZone($name) {
	$name = $name !== '' ? $name : (\V::app()->config('app.timezone') ?: date_default_timezone_get());
	try {
	    return new \DateTimeZone($name);
	} catch (\Exception $e) {
	    throw new \RuntimeException("unknown time zone `" . mb_substr($name, 0, 60) . '`');
	}
    }

    // ------------------------------------------------------------------------------------
    //times and events

    //a time from text (ISO 8601, "2026-10-21 14:00", a date) in time zone $tz when it has no offset; \RuntimeException when it cannot be read
    public static function parseTime($text, \DateTimeZone $tz) {
	$text = trim((string)$text);
	try {
	    if ($text === '' || !preg_match('/\d/', $text))
		throw new \Exception('empty');
	    return (new \DateTimeImmutable($text, $tz))->setTimezone($tz);
	} catch (\Exception $e) {
	    throw new \RuntimeException('not a time: `' . mb_substr($text, 0, 60) . '` (e.g. 2026-10-21 14:00 or 2026-10-21T14:00:00+03:00)');
	}
    }

    //from / until of a period: empty from = now, empty until = from + 7 days; until after from
    public static function period($from, $until, \DateTimeZone $tz) {
	$a = trim((string)$from) === '' ? new \DateTimeImmutable('now', $tz) : self::parseTime($from, $tz);
	$b = trim((string)$until) === '' ? $a->modify('+7 days') : self::parseTime($until, $tz);
	if ($b <= $a)
	    throw new \RuntimeException('Until is not after From');
	return [ $a, $b ];
    }

    //the start / end of an event as Graph takes them ({ dateTime (no offset), timeZone }): all day: midnights, the end day included (Graph's end is the next midnight); else
    //times, an empty end = start + $minutes; \RuntimeException when the end is not after the start
    public static function times($start, $end, $allDay, $minutes, \DateTimeZone $tz) {
	$s = self::parseTime($start, $tz);
	$fmt = fn(\DateTimeImmutable $d) => [ 'dateTime' => $d->format('Y-m-d\TH:i:s'), 'timeZone' => $tz->getName() ];
	if ($allDay) {
	    $s = $s->setTime(0, 0);
	    $e = trim((string)$end) === '' ? $s : self::parseTime($end, $tz)->setTime(0, 0);
	    if ($e < $s)
		throw new \RuntimeException('End is before Start');
	    return [ $fmt($s), $fmt($e->modify('+1 day')) ];
	}
	$e = trim((string)$end) === '' ? $s->modify('+' . max(1, (int)$minutes) . ' minutes') : self::parseTime($end, $tz);
	if ($e <= $s)
	    throw new \RuntimeException('End is not after Start');
	return [ $fmt($s), $fmt($e) ];
    }

    //ISO 8601 of Graph's { dateTime, timeZone } (the time zone as given, else $tz)
    public static function isoOf($t, \DateTimeZone $tz) {
	$t = is_array($t) ? $t : [];
	$dt = (string)($t['dateTime'] ?? '');
	if ($dt === '')
	    return '';
	try {
	    $zone = !empty($t['timeZone']) ? new \DateTimeZone($t['timeZone'] === 'UTC' ? 'UTC' : (string)$t['timeZone']) : $tz;
	} catch (\Exception $e) {
	    $zone = $tz;
	}
	try {
	    return (new \DateTimeImmutable(preg_replace('/\.\d+$/', '', $dt), $zone))->setTimezone($tz)->format(DATE_RFC3339);
	} catch (\Exception $e) {
	    return $dt;
	}
    }

    //an event object of the step from Graph's event
    protected function eventInfo($ev) {
	return self::eventOf($ev, $this->tz);
    }

    public static function eventOf($ev, \DateTimeZone $tz) {
	$ev = is_array($ev) ? $ev : [];
	$allDay = !empty($ev['isAllDay']);
	$start = self::isoOf($ev['start'] ?? [], $tz);
	$end = self::isoOf($ev['end'] ?? [], $tz);
	if ($allDay) {
	    //dates; the end is the last day (Graph's is the next midnight)
	    $start = substr($start, 0, 10);
	    $end = $end !== '' ? (new \DateTimeImmutable(substr($end, 0, 10)))->modify('-1 day')->format('Y-m-d') : '';
	}
	$isHtml = strtolower((string)($ev['body']['contentType'] ?? 'text')) === 'html';
	$description = (string)($ev['body']['content'] ?? '');
	return [
	    'id' => (string)($ev['id'] ?? ''),
	    'subject' => (string)($ev['subject'] ?? ''),
	    'description' => $isHtml ? NodeAppOutlookMail::htmlToText($description) : $description,
	    'location' => (string)($ev['location']['displayName'] ?? ''),
	    'start' => $start,
	    'end' => $end,
	    'allDay' => $allDay,
	    'timeZone' => $tz->getName(),
	    'showAs' => (string)($ev['showAs'] ?? ''),
	    'cancelled' => !empty($ev['isCancelled']),
	    'link' => (string)($ev['webLink'] ?? ''),
	    'teamsLink' => $ev['onlineMeeting']['joinUrl'] ?? null,
	    'organizer' => $ev['organizer']['emailAddress']['address'] ?? null,
	    'attendees' => array_map(fn($a) => [ 'email' => (string)($a['emailAddress']['address'] ?? ''), 'name' => (string)($a['emailAddress']['name'] ?? ''),
		'response' => (string)($a['status']['response'] ?? ''), 'type' => (string)($a['type'] ?? '') ], array_values((array)($ev['attendees'] ?? []))),
	    'isOrganizer' => !empty($ev['isOrganizer']),
	    'seriesId' => $ev['seriesMasterId'] ?? null,
	    'categories' => array_values((array)($ev['categories'] ?? [])),
	    'created' => $ev['createdDateTime'] ?? null,
	    'updated' => $ev['lastModifiedDateTime'] ?? null,
	];
    }
}
