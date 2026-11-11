<?php

/*
    Veeam Backups - the backups and restore points of a Veeam Backup & Replication server. Operations (setting `operation`):
	backups   the backups (GET /api/v1/backups; all pages up to At most)
	points    the restore points (GET /api/v1/restorePoints; newest first): of machines whose name matches, created since
	latest    a machine's newest restore point: { found, restorePoint, ageHours, recent } (recent: not older than Max age); found false is not an error
*/

namespace models\node;

class NodeAppVeeamBackups extends NodeAppVeeam {
    public static $name = 'veeam-backups';
    public static $label = 'Veeam Backups';
    public static $icon = 'bi-clock-history';
    public static $defaultOperation = 'latest';
    public static $defaultVariable = 'backup';

    const OPERATIONS = [
	'backups' => 'List backups',
	'points' => 'List restore points',
	'latest' => 'Check a machine\'s latest restore point',
    ];
    //the operations that change something; the others only read (an AI step's tools: the read ones ticked by default, util\AgentTools)
    const WRITES = [];
    const LISTS = [ 'backups', 'points' ];

    protected static function operationFields() {
	return [
	    self::field('machine', 'Machine', [ 'points', 'latest' ], [ 'help' => 'Its name; * at the start and / or end: any text. Empty: every machine (List restore points)',
		'requiredIf' => [ 'if' => 'operation', 'in' => [ 'latest' ] ], 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('since', 'Created since', [ 'points' ], [ 'help' => 'A date / time, or e.g. -7 days; empty: any time', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('maxAgeHours', 'Max age (hours)', [ 'latest' ], [ 'type' => 'number', 'default' => 24, 'help' => 'Newer: recent is true',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 100000, 'skipEmpty' => true, 'message' => 'from 1' ] ], 'ui' => [ 'width' => 6, 'step' => 1 ] ]),
	];
    }

    public static function outputVariables($config) {
	$op = is_array($config) ? (string)($config['operation'] ?? static::$defaultOperation) : static::$defaultOperation;
	$name = is_array($config) ? (string)($config['variable'] ?? static::$defaultVariable) : static::$defaultVariable;
	if ($op === 'latest' && \util\Validator::isIdentifier($name))
	    return [ $name => [ 'type' => 'object', 'info' => '{ found, restorePoint, ageHours, recent }', 'fields' => [
		'found' => [ 'type' => 'boolean', 'info' => 'the machine has a restore point' ], 'restorePoint' => [ 'type' => 'object', 'info' => 'the newest, as Veeam gives it' ],
		'ageHours' => [ 'type' => 'number' ], 'recent' => [ 'type' => 'boolean', 'info' => 'not older than Max age' ] ] ] ];
	return parent::outputVariables($config);
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'backups':
		return $this->listOf('/api/v1/backups', [], 'backup');
	    case 'points':
		$q = [ 'orderColumn' => 'CreationTime', 'orderAsc' => false ];
		if ($text('machine') !== '')
		    $q['nameFilter'] = $text('machine');
		if (($since = self::when($value('since'), 'Created since')) !== null)
		    $q['createdAfterFilter'] = $since;
		return $this->listOf('/api/v1/restorePoints', $q, 'restore point');
	    case 'latest':
		$machine = $text('machine');
		if ($machine === '')
		    throw new \RuntimeException('Machine: empty');
		$r = $this->get('/api/v1/restorePoints', [ 'nameFilter' => $machine, 'orderColumn' => 'CreationTime', 'orderAsc' => false, 'limit' => 1 ]);
		$point = is_array($r['data'][0] ?? null) ? $r['data'][0] : null;
		if (!$point)
		    return [ [ 'found' => false, 'restorePoint' => null, 'ageHours' => null, 'recent' => false ], "{$machine}: no restore point" ];
		$t = strtotime((string)($point['creationTime'] ?? ''));
		$age = $t ? round((time() - $t) / 3600, 1) : null;
		$recent = $age !== null && $age <= max(1, (int)$this->cfg('maxAgeHours', 24));
		return [ [ 'found' => true, 'restorePoint' => $point, 'ageHours' => $age, 'recent' => $recent ], "{$machine}: newest " . ($age !== null ? "{$age} h old" : 'of unknown age') . ($recent ? '' : ' (not recent)') ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
