<?php

/*
    a Hyper-V host (Windows Server 2016 and later), for the Hyper-V steps and the Hyper-V VM state trigger (util\WinRM: PowerShell over WinRM HTTPS):
    the host's WinRM HTTPS address (port 5986), a user of the host's Hyper-V Administrators and Remote Management Users groups (or an administrator) and its password (encrypted),
    NTLM or Basic, the certificate trusted or not. A host on a private address needs the account's "Integrations may connect to private network addresses". Test: the host's
    name, Windows version and its VMs. Provides `hyperv`
*/

namespace models\credential;

class CredentialHyperV extends \models\Credential {
    public static $label = 'Hyper-V';
    public static $icon = 'bi-windows';
    public static $group = 'Virtualization';
    public static $provides = [ 'hyperv' ];
    public static $testable = true;

    const AUTH = [ 'ntlm' => 'NTLM (domain or local account)', 'basic' => 'Basic (local account)' ];

    public function getSchema() {
	return array_merge(parent::getSchema(), [
	    [
		'key' => 'serverUrl',
		'type' => 'text',
		'label' => 'Host',
		'required' => true,
		'default' => '',
		'transform' => 'trim',
		'help' => 'Its WinRM HTTPS address: https://<host>:5986',
		'validate' => [ [ 'regex', 'pattern' => '^https://[^\s?#/]+(:\d+)?(/wsman)?/?$', 'skipEmpty' => true, 'message' => 'https://<host>:5986, without a path' ] ],
		'ui' => [ 'placeholder' => 'https://hv01.example.local:5986' ],
	    ],
	    [
		'key' => 'username',
		'type' => 'text',
		'label' => 'Username',
		'required' => true,
		'default' => '',
		'help' => 'DOMAIN\\user, user@domain, or a local user; a Hyper-V administrator',
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
	    [
		'key' => 'authMethod',
		'type' => 'select',
		'label' => 'Authentication',
		'required' => true,
		'default' => 'ntlm',
		'choices' => self::AUTH,
		'ui' => [ 'width' => 6 ],
	    ],
	    [
		'key' => 'trustCertificate',
		'type' => 'switch',
		'label' => 'Trust the host\'s certificate',
		'default' => false,
		'help' => 'For the listener\'s self-signed certificate',
		'ui' => [ 'width' => 6 ],
	    ],
	]);
    }

    public function client($aid, $timeout = 60) {
	return new \util\WinRM($this, $aid, $timeout);
    }

    //signs in, runs PowerShell: the host's name, its Windows, its VMs (Hyper-V's module there)
    public function test() {
	try {
	    $r = (array)$this->client($this->aid, 30)->ps('test', <<<'PS'
$os = Get-CimInstance Win32_OperatingSystem
if (-not (Get-Command Get-VMHost -ErrorAction SilentlyContinue)) { throw "the Hyper-V PowerShell module is not installed on $($env:COMPUTERNAME)" }
$h = Get-VMHost
[ordered]@{ name = $h.ComputerName; os = $os.Caption; build = $os.Version; vms = @(Get-VM).Count }
PS);
	} catch (\RuntimeException $e) {
	    throw new \Exception($e->getMessage());
	}
	return 'connected to ' . ($r['name'] ?? '?') . ', ' . trim(($r['os'] ?? 'Windows') . (isset($r['build']) ? " ({$r['build']})" : '')) . ', ' . \util\Format::plural((int)($r['vms'] ?? 0), 'VM');
    }
}
