<?php

/*
    CLD: 2026-11-01T06:00:00+02:00: new trigger: FortiGate event - starts the workflow when a FortiGate automation stitch calls it: the stitch's trigger (an event log ID, an IOC, an HA
    failover, a configuration change, high CPU, ...) chosen on the FortiGate, its webhook action sending the log (%%log%%) to the step's address /fortigate/<token>
    (FortigateController::receive) with `Authorization: Bearer <secret>`. Both are made when the step is added; the step shows the FortiOS CLI that creates the webhook action
    (`setup`, read-only). The variable: the JSON body as sent (the log's fields: logid, type, subtype, level, devname, srcip, ...), plus received_at; a body that is not JSON:
    { raw, received_at }
*/

namespace models\node;

class NodeTriggerFortiGate extends \models\wf\WfNode {
    //static data/structure
    public static $type = 'trigger';
    public static $name = 'fortigate';
    public static $label = 'FortiGate event';
    public static $icon = 'bi-bricks';
    public static $color = 'var(--wf-color-trigger)';
    public static $inputs = 0;
    public static $outputs = 1;

    public static function tokenData(array $node) {
	return [];
    }

    public static function initConfig() {
	$token = \models\TriggerToken::newToken('hex');
	$url = \V::app()->createAbsoluteUrl('/fortigate/' . $token);
	$secret = bin2hex(random_bytes(24));
	return [ 'token' => $token, 'url' => $url, 'secret' => $secret, 'setup' => self::cli($url, $secret, $token) ];
    }

    //the FortiOS CLI that makes the webhook action of $url (protocol, host and port apart: FortiOS takes the URI without its scheme)
    public static function cli($url, $secret, $token) {
	$p = parse_url((string)$url);
	$https = ($p['scheme'] ?? 'https') === 'https';
	$port = (int)($p['port'] ?? ($https ? 443 : 80));
	$name = 'rail22-' . substr((string)$token, 0, 8);
	return "config system automation-action\n"
	    . "    edit \"{$name}\"\n"
	    . "        set action-type webhook\n"
	    . '        set protocol ' . ($https ? 'https' : 'http') . "\n"
	    . "        set method post\n"
	    . '        set uri "' . ($p['host'] ?? '') . ($p['path'] ?? '') . "\"\n"
	    . "        set port {$port}\n"
	    . "        set http-body \"%%log%%\"\n"
	    . "        config http-headers\n"
	    . "            edit 1\n"
	    . "                set key \"Authorization\"\n"
	    . "                set value \"Bearer {$secret}\"\n"
	    . "            next\n"
	    . "        end\n"
	    . "    next\n"
	    . "end\n"
	    . "# then: Security Fabric > Automation > Create New stitch: your trigger, and the action {$name}";
    }

    public function validates($options = []) {
	$valid = parent::validates($options);
	if (!preg_match('/^[a-f0-9]{48}$/', (string)$this->cfg('secret', ''))) {
	    $this->errors('config', 'missing secret (remove the step and add it again)');
	    $valid = false;
	}
	return \models\TriggerToken::checkToken($this, 'hex') && $valid;
    }

    public static function configSchema() {
	return [
	    [
		'key' => 'url',
		'type' => 'text',
		'label' => 'Address',
		'perm:update' => 'nobody',
		'help' => 'Where the FortiGate\'s webhook action sends the events; active once the workflow is published',
		'ui' => [ 'placeholder' => 'Generating…' ],
	    ],
	    [
		'key' => 'setup',
		'type' => 'textarea',
		'label' => 'FortiGate setup (CLI)',
		'perm:update' => 'nobody',
		'help' => 'Paste it in the FortiGate\'s CLI console: it makes the webhook action (with this address and its secret). Then create an automation stitch with your trigger and this action',
		//a plain textarea (the code editor would read the CLI as a script), without spell check; shown read-only (perm:update nobody)
		'ui' => [ 'rows' => 17, 'spellcheck' => false ],
	    ],
	    [
		'key' => 'secret',
		'type' => 'text',
		'label' => 'Secret',
		'perm:update' => 'nobody',
		'help' => 'Sent by the FortiGate as Authorization: Bearer <secret> (in the setup above)',
		'ui' => [ 'type' => 'password', 'advanced' => true ],
	    ],
	    [
		'key' => 'variable',
		'type' => 'text',
		'label' => 'Event variable',
		'required' => true,
		'default' => 'fgt',
		'help' => 'e.g. {{ fgt.logid }}, {{ fgt.srcip }}, {{ fgt.devname }}',
		'validate' => [ [ 'identifier', 'skipEmpty' => true, 'message' => 'Use letters, digits or underscore, not starting with a digit' ] ],
		'ui' => [ 'variableName' => 'write' ],
	    ],
	];
    }

    public static function outputVariables($config) {
	$name = is_array($config) ? (string)($config['variable'] ?? 'fgt') : 'fgt';
	return \util\Validator::isIdentifier($name) ? [ $name => [ 'type' => 'object', 'info' => 'the log the FortiGate sent (%%log%%): its fields, plus received_at', 'fields' => [
	    'logid' => [ 'type' => 'string' ], 'type' => [ 'type' => 'string', 'info' => 'e.g. event, traffic, utm' ], 'subtype' => [ 'type' => 'string' ],
	    'level' => [ 'type' => 'string' ], 'devname' => [ 'type' => 'string', 'info' => 'the FortiGate' ], 'srcip' => [ 'type' => 'string' ], 'dstip' => [ 'type' => 'string' ],
	    'msg' => [ 'type' => 'string' ], 'received_at' => [ 'type' => 'string', 'info' => 'ISO 8601 (added by Rail22)' ],
	] ] ] : [];
    }
}
