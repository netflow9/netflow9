<?php

/*
    Wazuh Scans - file integrity (syscheck), rootcheck and security configuration assessment of an agent (the Server API). Operations:
	fim         the files syscheck knows of an agent (GET /syscheck/<id>): of a path, a hash
	fimLast     when its last FIM scan started and ended (GET /syscheck/<id>/last_scan)
	fimRun      FIM scans started (PUT /syscheck)
	rootcheck   what rootcheck found (GET /rootcheck/<id>)
	rootcheckRun rootcheck scans started (PUT /rootcheck)
	scaPolicies the SCA policies of an agent, with their scores (GET /sca/<id>)
	scaChecks   a policy's checks (GET /sca/<id>/checks/<policy>): passed / failed
*/

namespace models\node;

class NodeAppWzScans extends NodeAppWazuh {
    public static $name = 'wazuh-scans';
    public static $label = 'Wazuh Scans';
    public static $icon = 'bi-clipboard-check';
    public static $defaultOperation = 'scaPolicies';
    public static $defaultVariable = 'scan';

    const OPERATIONS = [
	'scaPolicies' => 'SCA: list policies',
	'scaChecks' => 'SCA: list a policy\'s checks',
	'fim' => 'FIM: list files',
	'fimLast' => 'FIM: last scan',
	'fimRun' => 'FIM: run a scan',
	'rootcheck' => 'Rootcheck: list findings',
	'rootcheckRun' => 'Rootcheck: run a scan',
    ];
    const WRITES = [ 'fimRun', 'rootcheckRun' ];
    const LISTS = [ 'scaPolicies', 'scaChecks', 'fim', 'rootcheck' ];
    const RESULTS = [ 'failed' => 'Failed', 'passed' => 'Passed', 'not applicable' => 'Not applicable' ];

    protected static function operationFields() {
	return [
	    self::field('agent', 'Agent', [ 'scaPolicies', 'scaChecks', 'fim', 'fimLast', 'rootcheck' ], [ 'required' => true, 'help' => 'Its id; an agent or an alert works too', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('agents', 'Agents', [ 'fimRun', 'rootcheckRun' ], [ 'required' => true, 'help' => 'Their ids, separated by commas, or a list of agents or alerts. Or all, for every agent', 'ui' => [ 'expression' => true ] ]),
	    self::field('policy', 'Policy', [ 'scaChecks' ], [ 'required' => true, 'help' => 'Its policy_id (SCA: list policies)', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('result', 'Result', [ 'scaChecks' ], [ 'type' => 'select', 'default' => '', 'choices' => [ '' => 'Any' ] + self::RESULTS, 'ui' => [ 'width' => 6 ] ]),
	    self::field('file', 'File', [ 'fim' ], [ 'help' => 'Its full path; empty: every file', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('hash', 'Hash', [ 'fim' ], [ 'help' => 'MD5, SHA-1 or SHA-256 of the file; empty: any', 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('search', 'Containing', [ 'scaPolicies', 'scaChecks', 'fim', 'rootcheck' ], [ 'help' => 'A text in any field; empty: all', 'ui' => [ 'expression' => 'text', 'width' => 6, 'newRow' => true ] ]),
	];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	switch($operation) {
	    case 'scaPolicies':
		return $this->listOf('/sca/' . $this->agentSetting(), $this->searchFilter(), 'policy');
	    case 'scaChecks':
		$policy = $text('policy');
		if (!preg_match('/^[\w.-]{1,128}$/', $policy))
		    throw new \RuntimeException('Policy: not a policy_id: `' . mb_substr($policy, 0, 64) . '`');
		$result = (string)$this->cfg('result', '');
		return $this->listOf('/sca/' . $this->agentSetting() . "/checks/{$policy}", $this->searchFilter() + (isset(self::RESULTS[$result]) ? [ 'result' => $result ] : []), 'check');
	    case 'fim':
		return $this->listOf('/syscheck/' . $this->agentSetting(), $this->searchFilter() + [ 'file' => $text('file'), 'hash' => $text('hash') ], 'file');
	    case 'fimLast':
		$id = $this->agentSetting();
		return [ $this->one("/syscheck/{$id}/last_scan", "last FIM scan of agent {$id}"), "agent {$id}" ];
	    case 'fimRun':
	    case 'rootcheckRun':
		$ids = self::agentList($value('agents'));
		return $this->change('PUT', $operation === 'fimRun' ? '/syscheck' : '/rootcheck', $ids === null ? [] : [ 'agents_list' => $ids ], null, 'scan started on agents');
	    case 'rootcheck':
		return $this->listOf('/rootcheck/' . $this->agentSetting(), $this->searchFilter(), 'finding');
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }

    //the setting Agent (an agent id)
    private function agentSetting() {
	return self::agentId($this->settingOf('agent'));
    }

    //the API's filter of the setting Search
    private function searchFilter() {
	return [ 'search' => $this->settingText('search') ];
    }
}
