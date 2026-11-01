<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new step: GravityZone Incidents - the EDR / XDR incidents of a GravityZone console (Incidents API) and endpoint isolation. Operations:
	list            the incidents (getIncidentsList; all pages up to At most)
	get             one incident (getIncident)
	status          an incident's status (changeIncidentStatus)
	priority        an incident's priority (changeIncidentPriority)
	assign          an incident assigned to a user of the console (assignIncident)
	note            an incident's note (updateIncidentNote)
	isolate         an endpoint isolated from the network (createIsolateEndpointTask)
	restore         an isolated endpoint restored (createRestoreEndpointFromIsolationTask)
    Kind: incidents (endpoint) or extended incidents (XDR). The status / priority values are GravityZone's codes (TO VERIFY against a console); Other parameters adds the others
*/

namespace models\node;

class NodeAppGzIncidents extends NodeAppGravityZone {
    public static $name = 'gz-incidents';
    public static $label = 'GravityZone Incidents';
    public static $icon = 'bi-exclamation-octagon';
    public static $defaultOperation = 'list';
    public static $defaultVariable = 'incidents';

    const OPERATIONS = [
	'list' => 'List incidents',
	'get' => 'Get an incident',
	'status' => 'Change status',
	'priority' => 'Change priority',
	'assign' => 'Assign',
	'note' => 'Set the note',
	'isolate' => 'Isolate an endpoint',
	'restore' => 'Restore an endpoint from isolation',
    ];
    //CLD: 2026-11-01T21:50:00+02:00: the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [ 'status', 'priority', 'assign', 'note', 'isolate', 'restore' ];
    const LISTS = [ 'list' ];
    const KINDS = [ 'incidents' => 'Incidents', 'extendedIncidents' => 'Extended incidents (XDR)' ];
    const STATUSES = [ '1' => 'Open', '2' => 'Investigating', '3' => 'False positive', '4' => 'Closed' ];
    const PRIORITIES = [ '1' => 'Low', '2' => 'Medium', '3' => 'High', '4' => 'Critical' ];
    const ON_INCIDENT = [ 'get', 'status', 'priority', 'assign', 'note' ];

    protected static function operationFields() {
	return [
	    self::field('kind', 'Kind', array_merge([ 'list' ], self::ON_INCIDENT), [ 'type' => 'select', 'default' => 'incidents', 'choices' => self::KINDS, 'ui' => [ 'width' => 4 ] ]),
	    self::field('incidentId', 'Incident', self::ON_INCIDENT, [ 'required' => true, 'help' => 'The incident\'s id', 'ui' => [ 'expression' => true, 'width' => 8 ] ]),
	    self::field('status', 'Status', [ 'status' ], [ 'type' => 'select', 'default' => '2', 'choices' => self::STATUSES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('priority', 'Priority', [ 'priority' ], [ 'type' => 'select', 'default' => '2', 'choices' => self::PRIORITIES, 'ui' => [ 'width' => 4 ] ]),
	    self::field('assignee', 'Assignee', [ 'assign' ], [ 'required' => true, 'help' => 'The id of a user of the console', 'ui' => [ 'expression' => true ] ]),
	    self::field('note', 'Note', [ 'note' ], [ 'type' => 'textarea', 'help' => 'Replaces the incident\'s note', 'ui' => [ 'rows' => 3, 'expression' => 'text' ] ]),
	    self::field('endpointId', 'Endpoint', [ 'isolate', 'restore' ], [ 'required' => true, 'help' => 'The endpoint\'s id', 'ui' => [ 'expression' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$kind = isset(self::KINDS[(string)$this->cfg('kind', 'incidents')]) ? (string)$this->cfg('kind', 'incidents') : 'incidents';
	$incident = fn() => [ 'type' => $kind, 'incidentId' => self::id($value('incidentId'), 'Incident') ];
	switch($operation) {
	    case 'list':
		return $this->listOf('incidents', 'getIncidentsList', [ 'type' => $kind ], 'incident');
	    case 'get':
		return self::done($this->rpc('incidents', 'getIncident', $incident()), 'incident ' . $text('incidentId'));
	    case 'status':
		$s = (string)$this->cfg('status', '2');
		return self::done($this->rpc('incidents', 'changeIncidentStatus', $incident() + [ 'status' => (int)$s ]), 'incident ' . $text('incidentId') . ': ' . (self::STATUSES[$s] ?? $s));
	    case 'priority':
		$p = (string)$this->cfg('priority', '2');
		return self::done($this->rpc('incidents', 'changeIncidentPriority', $incident() + [ 'priority' => (int)$p ]), 'incident ' . $text('incidentId') . ': ' . (self::PRIORITIES[$p] ?? $p));
	    case 'assign':
		return self::done($this->rpc('incidents', 'assignIncident', $incident() + [ 'assignee' => self::id($value('assignee'), 'Assignee') ]), 'incident ' . $text('incidentId') . ' assigned');
	    case 'note':
		return self::done($this->rpc('incidents', 'updateIncidentNote', $incident() + [ 'note' => mb_substr(\util\Expression::toText($value('note')), 0, 10000) ]), 'note set');
	    case 'isolate':
		return self::done($this->rpc('incidents', 'createIsolateEndpointTask', [ 'endpointId' => self::id($value('endpointId'), 'Endpoint') ]), 'endpoint ' . $text('endpointId') . ' isolated');
	    case 'restore':
		return self::done($this->rpc('incidents', 'createRestoreEndpointFromIsolationTask', [ 'endpointId' => self::id($value('endpointId'), 'Endpoint') ]), 'endpoint ' . $text('endpointId') . ' restored');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
