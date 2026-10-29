<?php

/*
    CLD: 2026-10-20T13:00:00+03:00: new step: Google Calendar - lists, creates, changes and deletes the events of a Google calendar (Calendar API v3), through a Google Calendar (OAuth)
    credential of the project (\models\credential\CredentialGoogleCalendar, provides `gcalendar`). Inspired by sim.ai's Google Calendar block. Operations (setting `operation`):
	list       the events between two times (default: from now, 7 days), searched by text; recurring events as their single occurrences, by start time
	get        one event
	create     an event: title, description, location, start / end (or a duration), all day, time zone, guests (invitations sent or not), a Google Meet link, a recurrence (RRULE)
	update     the settings given (empty ones are left as they are) of an event; guests added
	delete     an event (guests told or not)
	quickAdd   an event from a sentence ("Lunch with Ana tomorrow at 1pm")
	freeBusy   the busy periods of calendars between two times
	calendars  the calendars of the account
    Times are ISO 8601 (2026-10-21T14:00:00+03:00) or "2026-10-21 14:00", read in the step's time zone (default: the app's, config app.timezone); all-day events take dates
    (2026-10-21). The answer goes into one variable (default `calendar`).
    On error (Calendar API errors, e.g. not found, no access, a bad time) - fail the step, or continue on a second output `error` (as HTTP Request: $outPorts)
*/

//CLD: 2026-10-22T00:35:40+03:00: moved to the GoogleIntegration plugin (was models\node\NodeAppGoogleCalendar); core classes fully qualified
//CLD: 2026-10-26T23:39:49+02:00: moved back to the core (was plugins\GoogleIntegration\models): the Google credentials and steps are core, for every account (no plan feature)
namespace models\node;

class NodeAppGoogleCalendar extends NodeAppGoogle {
    //static data/structure
    //CLD: 2026-10-20T14:00:00+03:00: the shape, the credential, the operation run and the API calls are NodeAppGoogle's
    public static $name = 'google-calendar';
    public static $label = 'Google Calendar';
    public static $icon = 'bi-calendar-event';
    public static $capability = 'gcalendar';
    public static $credentialLabel = 'Google Calendar credential';
    public static $credentialKinds = 'Google Calendar';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'calendar';

    const OPERATIONS = [
	'list' => 'List events',
	'get' => 'Get an event',
	'create' => 'Create an event',
	'update' => 'Update an event',
	'delete' => 'Delete an event',
	'quickAdd' => 'Quick add (from a sentence)',
	'freeBusy' => 'Find busy times',
	'calendars' => 'List the calendars',
    ];

    //the operations on an existing event, the ones writing an event's settings, the ones on a period
    const ON_EVENT = [ 'get', 'update', 'delete' ];
    const EDIT = [ 'create', 'update' ];
    const PERIOD = [ 'list', 'freeBusy' ];

    const SEND_UPDATES = [ 'all' => 'Send invitations / updates to the guests', 'externalOnly' => 'Only to guests outside the organization', 'none' => 'Do not send' ];

    const API = 'https://www.googleapis.com/calendar/v3';

    const MAX_RESULTS = 2500;
    const MAX_DURATION = 60 * 24 * 31;

    public static function configSchema() {
	//CLD: 2026-10-24T12:47:49+03:00: the help texts without their "; {{ ... }} for expressions" ending (the expression inputs suggest as one types; less height in the wide dialog)
	$op = fn($ops) => [ 'key' => 'operation', 'operator' => 'in', 'value' => $ops ];

	return [
	    static::credentialField(),
	    static::operationField(),
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: primary (the account's), or a calendar id (e.g. team@group.calendar.google.com; List the calendars gives them); freeBusy: several, separated by commas)
		'key' => 'calendarId',
		'type' => 'text',
		'label' => 'Calendar',
		'default' => 'primary',
		'help' => 'primary, or a calendar id (List the calendars); freeBusy: several',
		'ui' => [ 'width' => 6, 'newRow' => true, 'expression' => true, 'hideIf' => [ 'key' => 'operation', 'operator' => 'eq', 'value' => 'calendars' ] ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: The event id, e.g. {{ calendar.id }} of a create, {{ calendar[0].id }} of a list)
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
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: Empty: now; ISO 8601 or 2026-10-21 14:00)
		'key' => 'timeMin',
		'type' => 'text',
		'label' => 'From',
		'default' => '',
		'help' => 'Empty: now; ISO 8601 or 2026-10-21 14:00',
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => '2026-10-21 00:00', 'expression' => true, 'showIf' => $op(self::PERIOD) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'timeMax',
		'type' => 'text',
		'label' => 'Until',
		'default' => '',
		'help' => 'Empty: 7 days after From',
		'ui' => [ 'width' => 3, 'placeholder' => '2026-10-28 00:00', 'expression' => true, 'showIf' => $op(self::PERIOD) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: Words in the title, description, location or guests; empty: all)
		'key' => 'search',
		'type' => 'text',
		'label' => 'Search',
		'default' => '',
		'help' => 'Words in the title, description, place or guests; empty: all',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op([ 'list' ]) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: a limit (the last line)
		'key' => 'maxResults',
		'type' => 'number',
		'label' => 'Largest number of events',
		'required' => true,
		'default' => 50,
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_RESULTS, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_RESULTS ] ],
		'ui' => [ 'limit' => true, 'step' => 1, 'width' => 6, 'showIf' => $op([ 'list' ]) ],
	    ],

	    //create / update
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line
		'key' => 'summary',
		'type' => 'text',
		'label' => 'Title',
		'default' => '',
		'help' => 'Update: empty leaves it as it is',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'Meeting with {{ mail.from_name }}', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide
		'key' => 'location',
		'type' => 'text',
		'label' => 'Location',
		'default' => '',
		'ui' => [ 'width' => 6, 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: ISO 8601 or 2026-10-21 14:00 (all day: 2026-10-21))
		'key' => 'start',
		'type' => 'text',
		'label' => 'Start',
		'default' => '',
		'help' => 'ISO 8601 or 2026-10-21 14:00',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'create' ] ],
		'ui' => [ 'width' => 3, 'newRow' => true, 'placeholder' => '2026-10-21 14:00', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6); shorter help (was: Empty: Start + the duration (all day: the same day))
		'key' => 'end',
		'type' => 'text',
		'label' => 'End',
		'default' => '',
		'help' => 'Empty: Start + the duration',
		'ui' => [ 'width' => 3, 'placeholder' => '2026-10-21 15:00', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'duration',
		'type' => 'number',
		'label' => 'Duration (minutes)',
		'required' => true,
		'default' => 60,
		'help' => 'When End is empty',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => self::MAX_DURATION, 'skipEmpty' => true, 'message' => 'from 1 to ' . self::MAX_DURATION ] ],
		'ui' => [ 'width' => 3, 'step' => 5, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'allDay',
		'type' => 'switch',
		'label' => 'All day',
		'default' => false,
		'help' => 'The end day is included',
		'ui' => [ 'width' => 3, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6), on a new line; shorter help (was: Of the times without an offset, e.g. Europe/Bucharest; empty: ' . (\V::app()->config('app.timezone') ?: date_default_timezone_get()))
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'timeZone',
		'type' => 'text',
		'label' => 'Time zone',
		'default' => '',
		//CLD: 2026-10-29T12:00:00+02:00: placeholder: what an empty value means (util timeZone(): the app's, config app.timezone; was the example, now in the help)
		'help' => 'Of times without an offset, e.g. Europe/Bucharest',
		'ui' => [ 'advanced' => true, 'width' => 3, 'newRow' => true, 'placeholder' => 'App\'s time zone', 'expression' => true, 'showIf' => $op(array_merge(self::EDIT, self::PERIOD)) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide; shorter help (was: Optional, e.g. RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10)
		'key' => 'recurrence',
		'type' => 'text',
		'label' => 'Repeat (RRULE)',
		'default' => '',
		'help' => 'Optional, e.g. RRULE:FREQ=WEEKLY;BYDAY=MO',
		'validate' => [ [ 'regex', 'pattern' => '^\s*(\{\{.*\}\}|(RRULE|EXRULE|RDATE|EXDATE)[:;].+)\s*$', 'skipEmpty' => true, 'message' => 'An RRULE, e.g. RRULE:FREQ=WEEKLY;COUNT=10' ] ],
		'ui' => [ 'width' => 6, 'placeholder' => 'RRULE:FREQ=WEEKLY;BYDAY=MO;COUNT=10', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 3 wide (was 6)
		'key' => 'meet',
		'type' => 'switch',
		'label' => 'Add a Google Meet link',
		'default' => false,
		'ui' => [ 'width' => 3, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		'key' => 'description',
		'type' => 'textarea',
		'label' => 'Description',
		'default' => '',
		'help' => 'Text or simple HTML',
		'ui' => [ 'rows' => 4, 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide, on a new line; shorter help (was: Addresses separated by commas, or {{ ... }} giving a list; update: added to the event's guests)
		'key' => 'attendees',
		'type' => 'text',
		'label' => 'Guests',
		'default' => '',
		'help' => 'Addresses separated by commas, or a list; update: added',
		'ui' => [ 'width' => 6, 'newRow' => true, 'placeholder' => 'ana@example.com, {{ mail.from }}', 'expression' => true, 'showIf' => $op(self::EDIT) ],
	    ],
	    [
		//CLD: 2026-10-24T12:49:07+03:00: layout: 6 wide
		//CLD: 2026-10-29T10:30:00+02:00: under "Advanced…" (the designer's closed section of the technical settings)
		'key' => 'sendUpdates',
		'type' => 'select',
		'label' => 'Notify guests',
		'required' => true,
		'default' => 'all',
		'choices' => self::SEND_UPDATES,
		'ui' => [ 'advanced' => true, 'width' => 6, 'showIf' => $op([ 'create', 'update', 'delete', 'quickAdd' ]) ],
	    ],

	    //quickAdd
	    [
		'key' => 'text',
		'type' => 'text',
		'label' => 'Sentence',
		'default' => '',
		'help' => 'What and when, in English, e.g. Lunch with Ana tomorrow at 1pm',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'quickAdd' ] ],
		'ui' => [ 'placeholder' => 'Call with {{ name }} on Friday 10am-11am', 'expression' => true, 'showIf' => $op([ 'quickAdd' ]) ],
	    ],

	    static::timeoutField(60),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: List: a list of events; freeBusy: { calendars, busy }; calendars: a list; else the event)
	    static::variableField('Events, free / busy, calendars, or the event'),
	    //CLD: 2026-10-24T12:46:54+03:00: shorter help (the narrow columns of the wide dialog; was: An error from Google Calendar (e.g. not found, no access), a time that cannot be read, an end before the start)
	    static::onErrorField('Calendar errors, times not read'),
	];
    }

    //the credential of the project; a time zone that exists; an update that changes something
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
	    foreach([ 'summary', 'start', 'end', 'location', 'description', 'attendees', 'recurrence' ] as $key)
		$changed = $changed || trim((string)$this->cfg($key, '')) !== '';
	    if (!$changed && !$this->cfg('meet', false))
		$fail('Update: give at least one setting to change (title, start, end, location, description, guests, Meet, repeat)');
	}
	return $valid;
    }

    //the type of an event object (eventInfo())
    public static function eventType() {
	return [
	    'type' => 'object',
	    'info' => 'an event: { id, summary, description, location, start, end, allDay, timeZone, status, link, meetLink, organizer, attendees, recurrence, recurringEventId, created, updated }',
	    'fields' => [
		'id' => [ 'type' => 'string' ],
		'summary' => [ 'type' => 'string', 'info' => 'the title' ],
		'description' => [ 'type' => 'string' ],
		'location' => [ 'type' => 'string' ],
		'start' => [ 'type' => 'string', 'info' => 'ISO 8601 (all day: the date)' ],
		'end' => [ 'type' => 'string', 'info' => 'ISO 8601 (all day: the day after the last one)' ],
		'allDay' => [ 'type' => 'boolean' ],
		'timeZone' => [ 'type' => 'string' ],
		'status' => [ 'type' => 'string', 'info' => 'confirmed, tentative or cancelled' ],
		'link' => [ 'type' => 'string', 'info' => 'the link that opens it in Google Calendar' ],
		'meetLink' => [ 'type' => 'string', 'info' => 'the Google Meet link (null when none)' ],
		'organizer' => [ 'type' => 'string', 'info' => 'the organizer\'s address' ],
		'attendees' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'fields' => [ 'email' => [ 'type' => 'string' ], 'name' => [ 'type' => 'string' ],
		    'response' => [ 'type' => 'string', 'info' => 'needsAction, accepted, declined or tentative' ], 'organizer' => [ 'type' => 'boolean' ] ] ] ],
		'recurrence' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'info' => 'RRULE lines' ],
		'recurringEventId' => [ 'type' => 'string', 'info' => 'an occurrence: the recurring event' ],
		'created' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
		'updated' => [ 'type' => 'string', 'info' => 'ISO 8601' ],
	    ],
	];
    }

    //the variable, by operation
    public static function outputVariables($config) {
	$config = is_array($config) ? $config : [];
	$name = static::variableName($config);
	if ($name === null)
	    return [];
	return [ $name => match($config['operation'] ?? 'list') {
	    'list' => [ 'type' => 'array', 'info' => 'the events, by start time (empty when none)', 'items' => self::eventType() ],
	    'delete' => [ 'type' => 'object', 'info' => 'the deleted event: { id }', 'fields' => [ 'id' => [ 'type' => 'string' ] ] ],
	    'freeBusy' => [ 'type' => 'object', 'info' => 'the busy periods: { from, until, busy: [ { calendar, start, end } ], calendars: { <calendar>: [ { start, end } ] } }', 'fields' => [
		'from' => [ 'type' => 'string' ], 'until' => [ 'type' => 'string' ],
		'busy' => [ 'type' => 'array', 'info' => 'all calendars, by start', 'items' => [ 'type' => 'object', 'fields' => [ 'calendar' => [ 'type' => 'string' ], 'start' => [ 'type' => 'string' ],
		    'end' => [ 'type' => 'string' ] ] ] ],
		'calendars' => [ 'type' => 'object', 'info' => 'calendar id => [ { start, end } ]' ] ] ],
	    'calendars' => [ 'type' => 'array', 'info' => 'the calendars: [ { id, summary, timeZone, primary, accessRole } ]', 'items' => [ 'type' => 'object', 'fields' => [
		'id' => [ 'type' => 'string' ], 'summary' => [ 'type' => 'string' ], 'timeZone' => [ 'type' => 'string' ], 'primary' => [ 'type' => 'boolean' ],
		'accessRole' => [ 'type' => 'string', 'info' => 'owner, writer, reader or freeBusyReader' ] ] ] ],
	    default => self::eventType(),
	} ];
    }

    // ------------------------------------------------------------------------------------
    //running

    //CLD: 2026-10-20T14:00:00+03:00: the operation (NodeAppGoogle::execute runs it: the variable, the log, On error)
    protected function runOperation($operation, \Closure $text, \Closure $value) {
	return match($operation) {
	    'list' => $this->opList($text),
	    'get' => $this->opGet($text),
	    'create' => $this->opSave($text, $value, false),
	    'update' => $this->opSave($text, $value, true),
	    'delete' => $this->opDelete($text),
	    'quickAdd' => $this->opQuickAdd($text),
	    'freeBusy' => $this->opFreeBusy($text),
	    'calendars' => $this->opCalendars(),
	};
    }

    protected function opList(\Closure $text) {
	$tz = $this->timeZone($text('timeZone'));
	[ $from, $until ] = self::period($text('timeMin'), $text('timeMax'), $tz);
	$max = max(1, min(self::MAX_RESULTS, (int)$this->cfg('maxResults', 50)));
	$query = [ 'timeMin' => $from->format(DATE_RFC3339), 'timeMax' => $until->format(DATE_RFC3339), 'singleEvents' => 'true', 'orderBy' => 'startTime', 'timeZone' => $tz->getName() ];
	$search = $text('search');
	if ($search !== '')
	    $query['q'] = $search;
	$events = [];
	$token = null;
	do {
	    $page = $this->api('GET', $this->calendarPath() . '/events', $query + [ 'maxResults' => min(2500, $max - count($events)) ] + ($token ? [ 'pageToken' => $token ] : []));
	    foreach((array)($page['items'] ?? []) as $ev)
		$events[] = self::eventInfo($ev);
	    $token = $page['nextPageToken'] ?? null;
	} while ($token && count($events) < $max);
	$events = array_slice($events, 0, $max);
	return [ $events, count($events) . ' event(s) from ' . $from->format('Y-m-d H:i') . ' until ' . $until->format('Y-m-d H:i') . ($search !== '' ? " for `{$search}`" : '') ];
    }

    protected function opGet(\Closure $text) {
	$event = self::eventInfo($this->api('GET', $this->calendarPath() . '/events/' . $this->idOf($text('eventId'))));
	return [ $event, "`{$event['summary']}` at {$event['start']}" ];
    }

    //create: a new event; update: the settings given patched (guests added to the event's)
    protected function opSave(\Closure $text, \Closure $value, $update) {
	$tz = $this->timeZone($text('timeZone'));
	$body = [];
	foreach([ 'summary', 'location', 'description' ] as $key) {
	    $v = $key === 'description' ? trim(\util\Expression::toText($value($key))) : $text($key);
	    if ($v !== '' || !$update)
		$body[$key] = $v;
	}
	if (!$update && ($body['summary'] ?? '') === '')
	    throw new \ErrorException('Google Calendar: create: the event needs a title');

	$id = $update ? $this->idOf($text('eventId')) : null;
	$current = $update ? $this->api('GET', $this->calendarPath() . "/events/{$id}") : [];

	$startText = $text('start');
	$endText = $text('end');
	if ($startText !== '' || $endText !== '') {
	    $allDay = (bool)$this->cfg('allDay', false);
	    if ($startText === '') {
		//only the end changed: from the event's start
		$startText = (string)($current['start']['dateTime'] ?? $current['start']['date'] ?? '');
		$allDay = isset($current['start']['date']) && !isset($current['start']['dateTime']);
	    }
	    [ $body['start'], $body['end'] ] = self::times($startText, $endText, $allDay, (int)$this->cfg('duration', 60), $tz);
	} elseif (!$update) {
	    throw new \ErrorException('Google Calendar: create: the event needs a start');
	}

	$guests = self::addresses($value('attendees'));
	if ($guests) {
	    $attendees = array_values((array)($current['attendees'] ?? []));
	    $known = array_map(fn($a) => strtolower((string)($a['email'] ?? '')), $attendees);
	    foreach($guests as $email) {
		if (!in_array(strtolower($email), $known, true))
		    $attendees[] = [ 'email' => $email ];
	    }
	    $body['attendees'] = $attendees;
	}
	$recurrence = $text('recurrence');
	if ($recurrence !== '')
	    $body['recurrence'] = array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', $recurrence)), fn($l) => $l !== ''));

	$query = [ 'sendUpdates' => isset(self::SEND_UPDATES[$this->cfg('sendUpdates')]) ? (string)$this->cfg('sendUpdates') : 'all' ];
	if ($this->cfg('meet', false) && empty($current['conferenceData'])) {
	    $body['conferenceData'] = [ 'createRequest' => [ 'requestId' => bin2hex(random_bytes(8)), 'conferenceSolutionKey' => [ 'type' => 'hangoutsMeet' ] ] ];
	    $query['conferenceDataVersion'] = 1;
	}

	$event = self::eventInfo($update ? $this->api('PATCH', $this->calendarPath() . "/events/{$id}", $query, $body)
	    : $this->api('POST', $this->calendarPath() . '/events', $query, $body));
	return [ $event, "`{$event['summary']}` " . ($update ? 'updated' : 'created') . " ({$event['start']} - {$event['end']}" . ($guests ? ', ' . count($guests) . ' guest(s)' : '') . ')' ];
    }

    protected function opDelete(\Closure $text) {
	$id = $this->idOf($text('eventId'));
	$this->api('DELETE', $this->calendarPath() . "/events/{$id}", [ 'sendUpdates' => isset(self::SEND_UPDATES[$this->cfg('sendUpdates')]) ? (string)$this->cfg('sendUpdates') : 'all' ]);
	return [ [ 'id' => $id ], "{$id} deleted" ];
    }

    protected function opQuickAdd(\Closure $text) {
	$sentence = $text('text');
	if ($sentence === '')
	    throw new \ErrorException('Google Calendar: quick add: no sentence');
	$event = self::eventInfo($this->api('POST', $this->calendarPath() . '/events/quickAdd', [ 'text' => $sentence,
	    'sendUpdates' => isset(self::SEND_UPDATES[$this->cfg('sendUpdates')]) ? (string)$this->cfg('sendUpdates') : 'all' ]));
	return [ $event, "`{$event['summary']}` created ({$event['start']})" ];
    }

    protected function opFreeBusy(\Closure $text) {
	$tz = $this->timeZone($text('timeZone'));
	[ $from, $until ] = self::period($text('timeMin'), $text('timeMax'), $tz);
	$ids = self::names($text('calendarId')) ?: [ 'primary' ];
	$data = $this->api('POST', '/freeBusy', [], [ 'timeMin' => $from->format(DATE_RFC3339), 'timeMax' => $until->format(DATE_RFC3339), 'timeZone' => $tz->getName(),
	    'items' => array_map(fn($id) => [ 'id' => $id ], $ids) ]);
	$calendars = [];
	$busy = [];
	foreach((array)($data['calendars'] ?? []) as $cal => $info) {
	    if (!empty($info['errors']))
		throw new \RuntimeException("calendar {$cal}: " . ($info['errors'][0]['reason'] ?? 'not available'));
	    $calendars[$cal] = array_map(fn($b) => [ 'start' => (string)$b['start'], 'end' => (string)$b['end'] ], array_values((array)($info['busy'] ?? [])));
	    foreach($calendars[$cal] as $b)
		$busy[] = [ 'calendar' => (string)$cal ] + $b;
	}
	usort($busy, fn($a, $b) => strcmp($a['start'], $b['start']));
	return [ [ 'from' => $from->format(DATE_RFC3339), 'until' => $until->format(DATE_RFC3339), 'busy' => $busy, 'calendars' => $calendars ],
	    count($busy) . ' busy period(s) in ' . count($calendars) . ' calendar(s)' ];
    }

    protected function opCalendars() {
	$list = [];
	$token = null;
	do {
	    $page = $this->api('GET', '/users/me/calendarList', [ 'maxResults' => 250 ] + ($token ? [ 'pageToken' => $token ] : []));
	    foreach((array)($page['items'] ?? []) as $c)
		$list[] = [ 'id' => (string)($c['id'] ?? ''), 'summary' => (string)($c['summaryOverride'] ?? $c['summary'] ?? ''), 'timeZone' => (string)($c['timeZone'] ?? ''),
		    'primary' => !empty($c['primary']), 'accessRole' => (string)($c['accessRole'] ?? '') ];
	    $token = $page['nextPageToken'] ?? null;
	} while ($token && count($list) < 1000);
	return [ $list, count($list) . ' calendar(s)' ];
    }

    // ------------------------------------------------------------------------------------
    //the Calendar API

    //the calendar of the step in a path: /calendars/<id>
    protected function calendarPath() {
	$id = trim(\util\Expression::toText(\util\Expression::render((string)$this->cfg('calendarId', 'primary'), $this->exec->expressionVariables())));
	return '/calendars/' . rawurlencode($id !== '' ? $id : 'primary');
    }

    protected function idOf($id) {
	$id = trim((string)$id);
	if (!preg_match('/^[A-Za-z0-9_@.-]{1,1024}$/', $id))
	    throw new \RuntimeException($id === '' ? 'no event given' : 'not an event id: `' . mb_substr($id, 0, 100) . '`');
	return $id;
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

    //the start / end of an event (the API's { dateTime, timeZone } or { date }): all day: dates, the end day included (the API's end is the day after); else times, an empty end
    //= start + $minutes; \RuntimeException when the end is not after the start
    public static function times($start, $end, $allDay, $minutes, \DateTimeZone $tz) {
	$s = self::parseTime($start, $tz);
	if ($allDay) {
	    $e = trim((string)$end) === '' ? $s : self::parseTime($end, $tz);
	    if ($e < $s->setTime(0, 0))
		throw new \RuntimeException('End is before Start');
	    return [ [ 'date' => $s->format('Y-m-d') ], [ 'date' => $e->modify('+1 day')->format('Y-m-d') ] ];
	}
	$e = trim((string)$end) === '' ? $s->modify('+' . max(1, (int)$minutes) . ' minutes') : self::parseTime($end, $tz);
	if ($e <= $s)
	    throw new \RuntimeException('End is not after Start');
	return [ [ 'dateTime' => $s->format(DATE_RFC3339), 'timeZone' => $tz->getName() ], [ 'dateTime' => $e->format(DATE_RFC3339), 'timeZone' => $tz->getName() ] ];
    }

    //an event object of the step from the API's event
    public static function eventInfo($ev) {
	$ev = is_array($ev) ? $ev : [];
	$allDay = isset($ev['start']['date']) && !isset($ev['start']['dateTime']);
	$meet = null;
	foreach((array)($ev['conferenceData']['entryPoints'] ?? []) as $p) {
	    if (($p['entryPointType'] ?? '') === 'video')
		$meet = (string)$p['uri'];
	}
	return [
	    'id' => (string)($ev['id'] ?? ''),
	    'summary' => (string)($ev['summary'] ?? ''),
	    'description' => (string)($ev['description'] ?? ''),
	    'location' => (string)($ev['location'] ?? ''),
	    'start' => (string)($ev['start']['dateTime'] ?? $ev['start']['date'] ?? ''),
	    'end' => (string)($ev['end']['dateTime'] ?? $ev['end']['date'] ?? ''),
	    'allDay' => $allDay,
	    'timeZone' => (string)($ev['start']['timeZone'] ?? ''),
	    'status' => (string)($ev['status'] ?? ''),
	    'link' => $ev['htmlLink'] ?? null,
	    'meetLink' => $meet ?? ($ev['hangoutLink'] ?? null),
	    'organizer' => $ev['organizer']['email'] ?? null,
	    'attendees' => array_map(fn($a) => [ 'email' => (string)($a['email'] ?? ''), 'name' => (string)($a['displayName'] ?? ''), 'response' => (string)($a['responseStatus'] ?? ''),
		'organizer' => !empty($a['organizer']) ], array_values((array)($ev['attendees'] ?? []))),
	    'recurrence' => array_values((array)($ev['recurrence'] ?? [])),
	    'recurringEventId' => $ev['recurringEventId'] ?? null,
	    'created' => $ev['created'] ?? null,
	    'updated' => $ev['updated'] ?? null,
	];
    }
}
