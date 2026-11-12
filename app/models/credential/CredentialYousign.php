<?php

/*
    CLD: 2026-11-12T23:30:00+02:00: a Yousign organization's API access, for the Yousign steps and event trigger (util\Yousign): an API key (Yousign's app: Developers > API keys;
    encrypted; a workspace key acts in its workspace only), the sandbox or production (each its own key), the workspace new signature requests go to (empty: the key's
    default). Test: a signature request listed. Provides `yousign`
*/

namespace models\credential;

class CredentialYousign extends \models\Credential {
    public static $label = 'Yousign';
    public static $icon = 'bi-pen';
    public static $group = 'E-signature';
    public static $provides = [ 'yousign' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'apiKey',
		'type' => 'password',
		'label' => 'API key',
		'required' => true,
		'encrypt' => true,
		'help' => 'Yousign\'s app: Developers > API keys (a plan with API access: Plus, Pro or Scale)',
		'ui' => [ 'width' => 8 ],
	    ],
	    [
		'key' => 'sandbox',
		'type' => 'switch',
		'label' => 'Sandbox',
		'default' => false,
		'help' => 'api-sandbox.yousign.app, with a sandbox key: signatures without legal value, for trying workflows',
		'ui' => [ 'width' => 4 ],
	    ],
	    [
		'key' => 'workspaceId',
		'type' => 'text',
		'label' => 'Workspace ID',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Where new signature requests go; empty: the default workspace (the Yousign Account step lists them)',
		'validate' => [ [ 'regex', 'pattern' => '^[0-9a-fA-F-]{36}$', 'skipEmpty' => true, 'message' => 'a workspace\'s ID (a UUID)' ] ],
		'ui' => [ 'width' => 8, 'newRow' => true ],
	    ],
	]);
    }

    public function client($timeout = 60) {
	return new \util\Yousign($this, $timeout);
    }

    public function test() {
	try {
	    $d = $this->client(20)->request('GET', '/signature_requests', [ 'limit' => 1 ]);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	$n = count((array)($d['data'] ?? []));
	return 'connected to Yousign' . ($this->sandbox ? ' (sandbox)' : '') . ($n ? '' : ': no signature request yet');
    }
}
