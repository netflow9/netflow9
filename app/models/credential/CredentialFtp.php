<?php

/*
    an FTP, FTPS or SFTP server (util\FtpClient), for the FTP / SFTP step. Provides `ftp`.
    SFTP signs in with a password or a private key (OpenSSH or PEM, + its passphrase); the server's key is pinned by its MD5 fingerprint: empty, the first connection
	(Test, or the first run) learns it and saves it (trust on first use); a server whose key changed afterwards is refused.
	FTPS checks the server's certificate unless switched off (self-signed servers).
	client() = a connected util\FtpClient (the key file in a private temporary folder until close()); test() lists the start folder
*/

namespace models\credential;

class CredentialFtp extends \models\Credential {
    public static $label = 'FTP / SFTP server';
    public static $icon = 'bi-hdd-network';
    public static $group = 'Files';
    public static $provides = [ 'ftp' ];
    public static $testable = true;

    const PROTOCOLS = [
	'sftp' => 'SFTP (SSH, port 22)',
	'ftps' => 'FTPS explicit (AUTH TLS, port 21)',
	'ftps-implicit' => 'FTPS implicit (port 990)',
	'ftp' => 'FTP (unencrypted, port 21)',
    ];
    const AUTH = [ 'password' => 'Password', 'key' => 'Private key' ];

    //the private key file of the open client
    protected $workDir = null;

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'protocol',
		'type' => 'select',
		'label' => 'Protocol',
		'required' => true,
		'default' => 'sftp',
		'choices' => self::PROTOCOLS,
	    ],
	    [
		'key' => 'host',
		'type' => 'text',
		'label' => 'Server',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'validate' => [ [ 'regex', 'pattern' => '^[A-Za-z0-9.\-:\[\]]+$', 'skipEmpty' => true, 'message' => 'A host name or IP address (no ftp:// and no path)' ] ],
		'ui' => [ 'width' => 8, 'placeholder' => 'sftp.example.com' ],
	    ],
	    [
		'key' => 'port',
		'type' => 'number',
		'label' => 'Port',
		'default' => null,
		'help' => 'Empty: the protocol\'s (22, 21 or 990)',
		'validate' => [ [ 'inRange', 'lower' => 1, 'upper' => 65535, 'skipEmpty' => true, 'message' => 'Between 1 and 65535' ] ],
		'ui' => [ 'width' => 4, 'step' => 1 ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'auth',
		'type' => 'select',
		'label' => 'Sign in with',
		'required' => true,
		'default' => 'password',
		'choices' => self::AUTH,
		'help' => 'A private key: SFTP only',
		'ui' => [ 'width' => 6, 'showIf' => [ 'key' => 'protocol', 'operator' => 'eq', 'value' => 'sftp' ] ],
	    ],
	    [
		'key' => 'password',
		'type' => 'password',
		'label' => 'Password',
		'encrypt' => true,
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'ne', 'value' => 'key' ] ],
	    ],
	    [
		'key' => 'privateKey',
		'type' => 'textarea',
		'label' => 'Private key',
		'encrypt' => true,
		'help' => 'OpenSSH or PEM: -----BEGIN OPENSSH PRIVATE KEY----- ... (the whole text)',
		//CLD: 2026-10-20T14:03:59+03:00: util\Validator key rules (was a prefix check in validates())
		'validate' => [
		    [ 'notPublicKey', 'when' => [ 'protocol' => [ 'sftp' ], 'auth' => [ 'key' ] ], 'skipEmpty' => true, 'last' => true, 'message' => 'this is a public key: signing in needs the private one' ],
		    [ 'sshPrivateKey', 'when' => [ 'protocol' => [ 'sftp' ], 'auth' => [ 'key' ] ], 'skipEmpty' => true, 'message' => 'not a private key (-----BEGIN OPENSSH PRIVATE KEY----- or PEM)' ],
		],
		'ui' => [ 'rows' => 6, 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'key' ] ],
	    ],
	    [
		'key' => 'passphrase',
		'type' => 'password',
		'label' => 'Key passphrase',
		'encrypt' => true,
		'help' => 'Empty when the key has none',
		'ui' => [ 'showIf' => [ 'key' => 'auth', 'operator' => 'eq', 'value' => 'key' ] ],
	    ],
	    [
		'key' => 'hostKey',
		'type' => 'text',
		'label' => 'Server key fingerprint (MD5)',
		'default' => '',
		'help' => 'SFTP. Empty: the first connection (Test) learns it and saves it; later connections to a server with another key are refused',
		'validate' => [ [ 'regex', 'pattern' => '^\s*(?:[Mm][Dd]5:)?(?:[0-9A-Fa-f]{2}:?){16}\s*$', 'skipEmpty' => true, 'message' => '32 hex digits, e.g. 65:86:4b:...' ] ],
		'ui' => [ 'placeholder' => 'aa:bb:cc:...', 'showIf' => [ 'key' => 'protocol', 'operator' => 'eq', 'value' => 'sftp' ] ],
	    ],
	    [
		'key' => 'verifyTls',
		'type' => 'switch',
		'label' => 'Check the server\'s certificate',
		'default' => true,
		'help' => 'Off only for servers with a self-signed certificate',
		'ui' => [ 'showIf' => [ 'key' => 'protocol', 'operator' => 'in', 'value' => [ 'ftps', 'ftps-implicit' ] ] ],
	    ],
	]);
    }

    //the password or the private key, as the sign-in needs
    public function validates($options = []) {
	$valid = parent::validates($options);
	if ($this->protocol === 'sftp' && $this->auth === 'key') {
	    $key = trim((string)$this->privateKey);
	    //CLD: 2026-10-20T14:03:59+03:00: only required here; the format is the field's sshPrivateKey rule
	    if ($key === '') {
		$this->errors('privateKey', 'required');
		$valid = false;
	    }
	} elseif ((string)$this->password === '') {
	    $this->errors('password', 'required');
	    $valid = false;
	}
	return $valid;
    }

    public function usesKey() {
	return $this->protocol === 'sftp' && $this->auth === 'key';
    }

    //a client for the server ($timeout: seconds of each call); close() it when done
    public function client($timeout = 60) {
	$this->close();
	$keyFile = '';
	if ($this->usesKey()) {
	    $this->workDir = \util\FileDecrypt::workDir();
	    //OpenSSH needs the key's last line ended
	    $keyFile = \util\FileDecrypt::secretFile($this->workDir, 'id', str_replace("\r\n", "\n", trim((string)$this->privateKey)) . "\n");
	}
	return new \util\FtpClient((string)$this->protocol, (string)$this->host, (int)$this->port, [
	    'username' => (string)$this->username,
	    'password' => $this->usesKey() ? '' : (string)$this->password,
	    'privateKeyFile' => $keyFile,
	    'passphrase' => $this->usesKey() ? (string)$this->passphrase : '',
	    'hostKey' => (string)$this->hostKey,
	    'verifyTls' => $this->verifyTls !== false,
	    'timeout' => $timeout,
	]);
    }

    //the private key file removed
    public function close() {
	if ($this->workDir)
	    \util\FileDecrypt::removeDir($this->workDir);
	$this->workDir = null;
    }

    //$call($client) on a client; SFTP without a fingerprint: the server's is learned (saved on the credential) and the call made again
    public function withClient(callable $call, $timeout = 60) {
	try {
	    $client = $this->client($timeout);
	    try {
		return $call($client);
	    } catch (\RuntimeException $e) {
		if ($client->hostKeySeen === null || \util\FtpClient::isFingerprint($this->hostKey))
		    throw $e;
		$this->learnHostKey($client->hostKeySeen);
		$client->close();
		$client = $this->client($timeout);
		return $call($client);
	    } finally {
		$client->close();
	    }
	} finally {
	    $this->close();
	}
    }

    //trust on first use: the fingerprint saved (only this field: the object may hold unsaved form data)
    protected function learnHostKey($fingerprint) {
	$this->hostKey = \util\FtpClient::fingerprint($fingerprint, true);
	if ($this->exists())
	    \V::app()->om->updateMany($this->getObjectName(), [ '_id' => $this->_id ], [ '$set' => [ 'hostKey' => $this->hostKey ] ]);
	\models\AuditLog::record('credential.hostkey', $this, 'server key learned: ' . $this->hostKey);
    }

    //signs in and lists the start folder
    public function test() {
	$known = \util\FtpClient::isFingerprint($this->hostKey);
	$entries = $this->withClient(fn($client) => $client->list(''), 20);
	$names = array_map(fn($e) => $e['name'] . ($e['type'] === 'dir' ? '/' : ''), array_slice($entries, 0, 10));
	return 'connected to ' . $this->host . ': ' . count($entries) . ' entr' . (count($entries) == 1 ? 'y' : 'ies') . ($names ? ' (' . implode(', ', $names) . (count($entries) > count($names) ? ', ...' : '') . ')' : '')
	    . (!$known && $this->protocol === 'sftp' ? '; server key saved: ' . $this->hostKey : '');
    }
}
