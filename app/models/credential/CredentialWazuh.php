<?php

/*
    a Wazuh installation (4.8 and later), for the Wazuh steps and alert trigger (util\Wazuh): the Server API's address (port 55000) and an API
    user; the indexer's address (port 9200) and its user, for the alerts and the vulnerabilities, which only the indexer has (optional: without it the other steps still work);
    the certificates trusted or not (a default installation's are self-signed). Passwords encrypted. A server on a private address needs the account's "Integrations may connect
    to private network addresses". Test: the Wazuh version, and that the indexer's alerts can be read. Provides `wazuh`
*/

namespace models\credential;

class CredentialWazuh extends \models\Credential {
    public static $label = 'Wazuh';
    public static $icon = 'bi-shield-shaded';
    public static $group = 'Security';
    public static $provides = [ 'wazuh' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Server API',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The manager\'s address, with the port (55000 unless changed)',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<manager>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://wazuh.example.local:55000' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'API user',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'A Wazuh API user, whose roles allow what the workflows do',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'API password',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'indexerUrl',
		'type' => 'text',
		'label' => 'Indexer',
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its address, with the port (9200 unless changed). For the alerts and the vulnerabilities; empty: the steps that read them cannot run',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<indexer>[:port], without a path' ] ],
		'ui' => [ 'placeholder' => 'https://wazuh.example.local:9200' ],
	    ],
	    [
		'key' => 'indexerUsername',
		'type' => 'text',
		'label' => 'Indexer user',
		'default' => '',
		'transform' => 'trim',
		'help' => 'A user that may read wazuh-alerts-* and wazuh-states-vulnerabilities-*',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'indexerPassword',
		'type' => 'password',
		'label' => 'Indexer password',
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the servers\' certificates',
		'default' => false,
		'help' => 'For a default installation\'s self-signed certificates',
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Wazuh($this, $aid, $timeout);
    }

    public function test() {
	$w = $this->client($this->aid, 20);
	try {
	    $about = (array)($w->request('GET', '/')['data'] ?? []);
	    $text = 'connected to Wazuh ' . ltrim((string)($about['api_version'] ?? ''), 'v') . (!empty($about['hostname']) ? " on {$about['hostname']}" : '');
	    if (!$w->hasIndexer())
		return "{$text}; no indexer: the alerts and the vulnerabilities cannot be read";
	    [ , $total ] = $w->search(\util\Wazuh::ALERTS, [ 'size' => 0, 'track_total_hits' => true ]);
	    return "{$text}; indexer: " . \util\Format::plural($total, 'alert');
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
    }
}
