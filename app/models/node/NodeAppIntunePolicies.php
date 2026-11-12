<?php

/*
    CLD: 2026-11-12T22:00:00+02:00: Intune Policies - the compliance policies (deviceCompliancePolicies) and configuration profiles (deviceConfigurations); changes nothing.
    Operations (setting `operation`), for each kind:
	compliancePolicies / configurations             the list: { id, name, type, description, created, modified, version }
	compliancePolicy / configuration                one, with its assignments (the groups it targets)
	complianceStatuses / configurationStatuses      its devices' statuses (deviceStatuses), of a status or all: { device, user, userName, status, reported, gracePeriodEnds }
	complianceSummary / configurationSummary        its counts (deviceStatusOverview): success, failed, error, conflict, pending, not applicable
    A policy: its id, or its name (exactly one). The settings catalog's policies (configurationPolicies) are Graph beta: the Intune API step reads them
*/

namespace models\node;

class NodeAppIntunePolicies extends NodeAppIntune {
    public static $name = 'intune-policies';
    public static $label = 'Intune Policies';
    public static $icon = 'bi-shield-check';
    public static $defaultOperation = 'compliancePolicies';
    public static $defaultVariable = 'policies';

    const OPERATIONS = [
	'compliancePolicies' => 'List compliance policies',
	'compliancePolicy' => 'Get a compliance policy',
	'complianceStatuses' => 'List a compliance policy\'s device statuses',
	'complianceSummary' => 'Summarize a compliance policy',
	'configurations' => 'List configuration profiles',
	'configuration' => 'Get a configuration profile',
	'configurationStatuses' => 'List a profile\'s device statuses',
	'configurationSummary' => 'Summarize a configuration profile',
    ];
    const WRITES = [];
    const LISTS = [ 'compliancePolicies', 'complianceStatuses', 'configurations', 'configurationStatuses' ];
    const STATUSES = [ '' => 'Any', 'compliant' => 'Compliant / succeeded', 'nonCompliant' => 'Not compliant / failed', 'error' => 'Error', 'conflict' => 'Conflict',
	'notApplicable' => 'Not applicable', 'unknown' => 'Unknown' ];

    protected static function operationFields() {
	$one = [ 'compliancePolicy', 'complianceStatuses', 'complianceSummary', 'configuration', 'configurationStatuses', 'configurationSummary' ];
	return [
	    self::field('search', 'Name contains', [ 'compliancePolicies', 'configurations' ], [ 'ui' => [ 'expression' => 'text', 'width' => 6 ] ]),
	    self::field('policy', 'Policy', $one, [ 'required' => true, 'help' => 'Its id, a policy of a list, or its name', 'ui' => [ 'expression' => true, 'width' => 6 ] ]),
	    self::field('status', 'Status', [ 'complianceStatuses', 'configurationStatuses' ], [ 'type' => 'select', 'choices' => self::STATUSES, 'ui' => [ 'width' => 6 ] ]),
	];
    }

    //a policy of an answer: { id, name, type (#microsoft.graph.windows10CompliancePolicy: windows10CompliancePolicy), description, created, modified, version }
    public static function policyOf(array $p) {
	return [ 'id' => $p['id'] ?? null, 'name' => $p['displayName'] ?? null, 'type' => isset($p['@odata.type']) ? preg_replace('/^#?microsoft\.graph\./', '', (string)$p['@odata.type']) : null,
	    'description' => $p['description'] ?? null, 'created' => $p['createdDateTime'] ?? null, 'modified' => $p['lastModifiedDateTime'] ?? null, 'version' => $p['version'] ?? null ];
    }

    //a policy's id: its id, a policy ({ id }), or its name (exactly one)
    protected function policyId($base, $value) {
	if (is_array($value) && isset($value['id']))
	    return \util\Intune::id($value['id'], 'Policy');
	$t = trim(\util\Expression::toText($value));
	if (preg_match(\util\Intune::GUID, $t))
	    return $t;
	[ $all ] = $this->intune->pages($base, [ '$select' => 'id,displayName' ], self::MAX_ITEMS);
	$found = array_values(array_filter($all, fn($p) => strcasecmp((string)($p['displayName'] ?? ''), $t) === 0));
	if (count($found) !== 1)
	    throw new \RuntimeException('Policy: ' . ($found ? count($found) . " policies are named `{$t}`: give the id" : "no policy named `{$t}`"));
	return (string)$found[0]['id'];
    }

    protected function runOperation($operation, \Closure $text, \Closure $value) {
	$G = $this->intune;
	$kind = str_starts_with($operation, 'compliance') ? 'compliance' : 'configuration';
	$base = $kind === 'compliance' ? '/deviceManagement/deviceCompliancePolicies' : '/deviceManagement/deviceConfigurations';
	$what = $kind === 'compliance' ? 'compliance policy' : 'configuration profile';
	if (in_array($operation, [ 'compliancePolicies', 'configurations' ], true)) {
	    [ $all ] = $G->pages($base, [], self::MAX_ITEMS);
	    $s = $text('search');
	    $list = array_values(array_filter(array_map(fn($p) => self::policyOf((array)$p), $all), fn($p) => $s === '' || stripos((string)$p['name'], $s) !== false));
	    return $this->listResult($list, $what);
	}
	$id = $this->policyId($base, $value('policy'));
	$path = "{$base}/" . rawurlencode($id);
	switch(preg_replace('/^(compliance|configuration)/', '', $operation)) {
	    case 'Policy':
	    case '':
		$p = $G->request('GET', $path, [ '$expand' => 'assignments' ]);
		$r = self::policyOf($p) + [ 'assignments' => array_map(fn($a) => [ 'id' => $a['id'] ?? null,
		    'target' => preg_replace('/^#?microsoft\.graph\./', '', (string)($a['target']['@odata.type'] ?? '')), 'groupId' => $a['target']['groupId'] ?? null ], (array)($p['assignments'] ?? [])),
		    'fields' => array_diff_key($p, [ 'assignments' => 1, '@odata.context' => 1 ]) ];
		return [ $r, "{$r['name']}: " . \util\Format::plural(count($r['assignments']), 'assignment') ];
	    case 'Statuses':
		$status = (string)$this->cfg('status', '');
		[ $all, $truncated ] = $G->pages("{$path}/deviceStatuses", [ '$filter' => isset(self::STATUSES[$status]) && $status !== '' ? "status eq '{$status}'" : '' ], $this->maxItems());
		$items = array_map(fn($s) => [ 'id' => $s['id'] ?? null, 'device' => $s['deviceDisplayName'] ?? null, 'user' => $s['userPrincipalName'] ?? null, 'userName' => $s['userName'] ?? null,
		    'status' => $s['status'] ?? null, 'reported' => $s['lastReportedDateTime'] ?? null, 'gracePeriodEnds' => $s['complianceGracePeriodExpirationDateTime'] ?? null ], $all);
		return [ [ 'items' => $items, 'count' => count($items), 'truncated' => $truncated ], \util\Format::plural(count($items), 'device status') . ($truncated ? ', more left out' : '') ];
	    case 'Summary':
		$o = $G->request('GET', "{$path}/deviceStatusOverview");
		$r = [ 'id' => $id, 'success' => (int)($o['successCount'] ?? 0), 'failed' => (int)($o['failedCount'] ?? 0), 'error' => (int)($o['errorCount'] ?? 0),
		    'conflict' => (int)($o['conflictCount'] ?? 0), 'pending' => (int)($o['pendingCount'] ?? 0), 'notApplicable' => (int)($o['notApplicableCount'] ?? 0),
		    'updated' => $o['lastUpdateDateTime'] ?? null ];
		return [ $r, "{$r['success']} succeeded, {$r['failed']} failed, {$r['error']} errors" ];
	}
	throw new \RuntimeException("unknown operation `{$operation}`");
    }
}
