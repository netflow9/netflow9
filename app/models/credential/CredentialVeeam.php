<?php

/*
    CLD: 2026-11-01T05:00:00+02:00: VBR 12 too (12.0 to 12.3): the Veeam version chosen sets the REST API version sent (util\Veeam::VERSIONS)
    CLD: 2026-11-01T04:00:00+02:00: new: a Veeam Backup & Replication server (v13 REST API), for the Veeam steps and the Veeam event trigger (util\Veeam): the server's address (port 9419),
    a user of a built-in Veeam role (custom roles cannot use the REST API) and its password (encrypted), the API version (x-api-version), the server's self-signed certificate
    trusted or not. A server on a private address needs the account's "Integrations may connect to private network addresses". Test: the server's name and version. Provides `veeam`
*/

namespace models\credential;

class CredentialVeeam extends \models\Credential {
    public static $label = 'Veeam Backup & Replication';
    public static $icon = 'bi-hdd-stack';
    public static $group = 'Backup';
    public static $provides = [ 'veeam' ];
    public static $testable = true;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'The backup server\'s REST API: https://<server>:9419',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?/?$', 'skipEmpty' => true, 'message' => 'https://<server>:9419, without a path' ] ],
		'ui' => [ 'placeholder' => 'https://vbr.example.local:9419' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'help' => 'A user with a built-in Veeam role (e.g. Backup Operator, Restore Operator, Backup Administrator); DOMAIN\user for a domain user',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'required' => true,
		'encrypt' => true,
		'ui' => [ 'width' => 6 ],
	    ],
	    //CLD: 2026-11-01T05:00:00+02:00: a choice of the releases (VBR 13 and 12.0 to 12.3: util\Veeam::VERSIONS)
	    [
		'key' => 'apiVersion',
		'type' => 'select',
		'label' => 'Veeam version',
		'required' => true,
		'default' => \util\Veeam::DEFAULT_VERSION,
		'choices' => array_map(fn($v) => $v['label'], \util\Veeam::VERSIONS),
		'help' => 'The backup server\'s release (Test tells the right one); its REST API version is sent with every request',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the server\'s certificate',
		'default' => false,
		'help' => 'For the backup server\'s own self-signed certificate',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\Veeam($this, $aid, $timeout);
    }

    //CLD: 2026-11-01T05:00:00+02:00: after a sign-in refused with $message: when it is about the API version (x-api-version), the first other version (newest first) the server signs in with; else null
    protected function versionTaken($message) {
	if (!preg_match('/version/i', (string)$message))
	    return null;
	foreach(array_keys(\util\Veeam::VERSIONS) as $version) {
	    if ($version === (string)$this->apiVersion)
		continue;
	    $other = clone $this;
	    $other->apiVersion = $version;
	    try {
		$other->client($this->aid, 10)->request('GET', '/api/v1/serverInfo');
		return $version;
	    } catch (\RuntimeException $e) {
	    }
	}
	return null;
    }

    //signs in, reads the server's information
    public function test() {
	try {
	    $info = $this->client($this->aid, 20)->request('GET', '/api/v1/serverInfo');
	} catch (\RuntimeException $e) {
	    //CLD: 2026-11-01T05:00:00+02:00: refused for the API version (another release): the version the server takes, named
	    $fits = $this->versionTaken($e->getMessage());
	    throw new \Exception($e->getMessage() . ($fits !== null ? '; this server takes Veeam version "' . \util\Veeam::VERSIONS[$fits]['label'] . '": choose it' : ''));
	}
	$name = (string)($info['name'] ?? parse_url((string)$this->serverUrl, PHP_URL_HOST));
	$build = (string)($info['buildVersion'] ?? '');
	//CLD: 2026-11-01T05:00:00+02:00: the version chosen against the server's build: the right one named when it differs
	$right = \util\Veeam::versionForBuild($build);
	$chosen = (string)$this->apiVersion !== '' ? (string)$this->apiVersion : \util\Veeam::DEFAULT_VERSION;
	$advice = $right !== null && $right !== $chosen ? '; choose Veeam version "' . \util\Veeam::VERSIONS[$right]['label'] . '" for this server' : '';
	return "connected to {$name}" . ($build !== '' ? ", Veeam Backup & Replication {$build}" : '') . $advice;
    }
}
