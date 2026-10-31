<?php

/*
    CLD: 2026-11-01T00:30:00+02:00: new trigger: GravityZone event - starts the workflow when Bitdefender GravityZone's Event Push Service sends events (JSON-RPC: params.events).
    The step's address /gravityzone/<token> (GravityzoneController::receive) and a secret GravityZone sends as `Authorization: Bearer <secret>` (made when the step is added).
    Connect (on): publishing the workflow sets the push service of the credential's key to this address (push.setPushEventSettings: jsonRPC, the event types chosen, all when none),
    unpublishing turns it off (when it still points here): onPublish / onUnpublish (WorkflowController::publish / unpublish). A GravityZone key has one push service: connecting
    replaces what it had. Runs: one per event (the variable: the event as GravityZone sends it, with its `module`) or one per batch ({ events, count }); events of other types
    are left out (answered 200: GravityZone counts any other answer as an error)
*/

namespace models\node;

class NodeTriggerGravityZone extends \models\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'gravityzone';
    public static $label = 'GravityZone event';
    public static $icon = 'bi-shield-exclamation';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    const RUNS = [ 'each' => 'One run per event', 'batch' => 'One run per batch of events' ];

    //GravityZone's event types (push service: subscribeToEventTypes)
    const EVENT_TYPES = [
	'av' => 'Antimalware',
	'avc' => 'Advanced Threat Control',
	'hd' => 'HyperDetect',
	'antiexploit' => 'Advanced Anti-Exploit',
	'ransomware-mitigation' => 'Ransomware activity',
	'network-sandboxing' => 'Sandbox Analyzer detection',
	'network-monitor' => 'Network Attack Defense',
	'fw' => 'Firewall',
	'aph' => 'Antiphishing',
	'uc' => 'User / Content Control',
	'dp' => 'Data Protection',
	'exchange-malware' => 'Exchange malware',
	'exchange-organization-info' => 'Exchange license usage limit',
	'exchange-user-credentials' => 'Exchange user credentials',
	'new-incident' => 'New incident',
	'new-extended-incident' => 'New extended incident',
	'incident-status-changed' => 'Incident status changed',
	'incident-assignee-changed' => 'Incident assignee changed',
	'incident-priority-changed' => 'Incident priority changed',
	'incident-note-created' => 'Incident note created',
	'incident-note-updated' => 'Incident note updated',
	'incident-note-deleted' => 'Incident note deleted',
	'task-status' => 'Task status',
	'modules' => 'Product modules status',
	'install' => 'Agent installed',
	'uninstall' => 'Agent uninstalled',
	'registration' => 'Product registration',
	'hwid-change' => 'Hardware ID change',
	'endpoint-moved-in' => 'Endpoint moved in',
	'endpoint-moved-out' => 'Endpoint moved out',
	'troubleshooting-activity' => 'Troubleshooting activity',
	'sva' => 'Security Server status',
	'sva-load' => 'Security Server overloaded',
	'supa-update-status' => 'Update server outdated',
	'adcloud' => 'Cloud AD integration',
	'partner-changed' => 'Partner changed',
	'integrations-hub-status' => 'Integrations hub status',
    ];

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'gz') : 'gz';
	if (!\util\Validator::isIdentifier($name))
	    return [];
	if (is_array($config) && ($config['runs'] ?? 'each') === 'batch')
	    return [ $name => [ 'type' => 'object', 'info' => '{ events, count, received_at }', 'fields' => [
		'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'info' => 'an event, as GravityZone sends it' ] ],
		'count' => [ 'type' => 'number' ], 'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601' ] ] ] ];
	return [ $name => [ 'type' => 'object', 'info' => 'the event, as GravityZone sends it', 'fields' => [
	    'module' => [ 'type' => 'string', 'info' => 'the event type, e.g. av, new-incident' ],
	    'companyId' => [ 'type' => 'string' ], 'computer_name' => [ 'type' => 'string', 'info' => 'endpoint events' ],
	    'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (added by NetFlow9)' ] ] ] ];
    }

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	return [ 'token' => $token, 'url' => \V::app()->createAbsoluteUrl('/gravityzone/' . $token), 'secret' => bin2hex(random_bytes(24)) ];
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (!preg_match('/^[a-f0-9]{48}$/', (string)$this->cfg('secret', ''))) {
	    $this->errors('config', 'missing secret (remove the step and add it again)');
	    $valid = false;
	}
	$cred = (string)$this->cfg('credential', '');
	if ($cred !== '' && (!$this->workflow || empty(\models\Credential::findFor($this->workflow, $cred, 'gravityzone')))) {
	    $this->errors('config', 'GravityZone event: choose a Bitdefender GravityZone credential of this project');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function configSchema() {
	$choices = [];
	if (\V::app()->config('app.mode') == 'web' && !\V::app()->user->isGuest && !empty(\V::app()->user->crtProject))
	    foreach(\models\Credential::choicesFor('gravityzone', \V::app()->config('rt.account')->_id ?? null, \V::app()->user->crtProject) as $c)
		$choices[$c['value']] = $c['label'];
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		'help' => 'Where GravityZone sends the events; set up for you when the workflow is published (Connect)',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'credential',
		'type' => 'select',
		'label' => 'GravityZone credential',
		'choices' => $choices,
		'help' => 'The console whose events start the workflow',
		'required' => true,
		'ui' => [ 'width' => 8, 'newRow' => true ],
	    ],
	    [
		'key' => 'connect',
		'type' => 'switch',
		'label' => 'Connect',
		'default' => true,
		'help' => 'Set GravityZone\'s push service to this address when published, off when unpublished. It replaces the key\'s current push settings',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'events',
		'type' => 'select',
		'label' => 'Event types',
		'multiple' => true,
		'default' => [],
		'choices' => self::EVENT_TYPES,
		'help' => 'None chosen: all',
		'ui' => [ 'size' => 8 ],
	    ],
	    [
		'key' => 'runs',
		'type' => 'select',
		'label' => 'Runs',
		'required' => true,
		'default' => 'each',
		'choices' => self::RUNS,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'text',
		'label' => 'Secret',
		'perm:update' => 'nobody',
		'help' => 'GravityZone sends it as Authorization: Bearer <secret> (set by Connect)',
		'ui' => [ 'type' => 'password', 'advanced' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'gz',
		'help' => 'e.g. {{ gz.module }}, {{ gz.computer_name }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'width' => 6, 'variableName' => 'write' ],
	    ],
	];
    }

    //the event types chosen (known ones), [] = all
    public static function chosenTypes(array $config) {
	return array_values(array_intersect(array_map('strval', (array)($config['events'] ?? [])), array_keys(self::EVENT_TYPES)));
    }

    // ------------------------------------------------------------------------------------
    //publishing (WorkflowController::publish / unpublish): GravityZone's push service

    //the step's credential of $wf, or null
    protected static function credentialOf($wf, array $node) {
	return \models\Credential::findFor($wf, (string)($node['config']['credential'] ?? ''), 'gravityzone');
    }

    //the push service set to this step (when Connect is on): a message for the user, or null
    public static function onPublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']))
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return 'GravityZone event: no credential, the push service was not set';
	$types = self::chosenTypes($cfg) ?: array_keys(self::EVENT_TYPES);
	try {
	    $cred->client($wf->aid, 30)->call('push', 'setPushEventSettings', [
		'status' => 1,
		'serviceType' => 'jsonRPC',
		'serviceSettings' => [ 'url' => (string)($cfg['url'] ?? ''), 'authorization' => 'Bearer ' . (string)($cfg['secret'] ?? ''), 'requireValidSslCertificate' => true ],
		'subscribeToEventTypes' => (object)array_fill_keys($types, true),
	    ]);
	} catch (\RuntimeException $e) {
	    return 'GravityZone event: the push service was not set: ' . $e->getMessage();
	}
	return 'GravityZone sends its events to this workflow (' . \util\Format::plural(count($types), 'event type') . ')';
    }

    //the push service off, when it still sends to this step: a message, or null
    public static function onUnpublish($wf, array $node) {
	$cfg = (array)($node['config'] ?? []);
	if (empty($cfg['connect']))
	    return null;
	$cred = self::credentialOf($wf, $node);
	if (!$cred)
	    return null;
	try {
	    $gz = $cred->client($wf->aid, 30);
	    $now = $gz->call('push', 'getPushEventSettings');
	    if ((string)($now['serviceSettings']['url'] ?? '') !== (string)($cfg['url'] ?? '') || empty($now['status']))
		return null;
	    $gz->call('push', 'setPushEventSettings', [ 'status' => 0 ] + array_intersect_key($now, array_flip([ 'serviceType', 'serviceSettings', 'subscribeToEventTypes' ])));
	} catch (\RuntimeException $e) {
	    return 'GravityZone event: the push service was not turned off: ' . $e->getMessage();
	}
	return 'GravityZone no longer sends its events here';
    }
}
