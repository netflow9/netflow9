<?php

/*
    CLD: 2026-11-01T16:11:37+02:00: new: inbound mail, the Email (inbound) trigger's: mail to <prefix>-<code>@MAIL_INBOUND_DOMAIN starts a workflow.
    Haraka receives it (haraka/plugins/nfn.js: checks the address, stores the message, submits the job models\node\NodeTriggerEmail::deliver, wakes the daemon on DAEMON_PORT).
    Without Haraka (found()) the step is not available (NodeTriggerEmail::available: not in the palette, a workflow with it cannot run) and the account settings have no Inbound mail tab.
	InboundMail::found()      Haraka is installed here with Rail22's plugin listed in its config/plugins
	InboundMail::status()     the parts found() checks: [ dir, installed, plugin, listed, domain, found ]
	InboundMail::service()    Haraka on the Services page (hook app:services, sys\Boot)
    The account's setting (its address prefix, per account, with or without the MultiTenant plugin) is models\InboundMailSettings, on the Inbound mail tab of the account's
    settings (AccountController::settings).
*/

namespace util;

class InboundMail {

    //the Haraka plugin of Rail22 (plugins/nfn.js, config/nfn.ini)
    const HARAKA_PLUGIN = 'nfn';

    //where Haraka is installed: app.env HARAKA_DIR, else <app>/haraka (as scripts/haraka.sh installs it)
    public static function dir() {
	return defined('HARAKA_DIR') && HARAKA_DIR !== '' ? rtrim(HARAKA_DIR, '/') : BASE . '/haraka';
    }

    //the domain of the inbound addresses (MAIL_INBOUND_DOMAIN of app.env), '' when not set
    public static function domain() {
	return (string)\V::app()->config('mail.inbound_domain');
    }

    //Haraka is found: installed (node_modules/haraka) with Rail22's plugin (plugins/nfn.js) listed in its config/plugins; once per process
    private static $found = null;
    public static function found() {
	if (self::$found === null)
	    self::$found = self::status()['found'];
	return self::$found;
    }

    //CLD: 2026-11-01T19:00:00+02:00: where Haraka listens: HARAKA_HOST (app.env or the environment: Docker, its own container, e.g. haraka), else this machine
    public static function host() {
	return (string)\V::env('HARAKA_HOST', '') ?: '127.0.0.1';
    }

    //the parts found() checks, for the settings pages
    //CLD: 2026-11-01T19:00:00+02:00: in Docker (util\Services::docker) Haraka is another container: found when HARAKA_HOST is set (compose.yaml, with the mail profile), no files to check
    public static function status() {
	if (Services::docker()) {
	    $set = (string)\V::env('HARAKA_HOST', '') !== '';
	    return [ 'dir' => '', 'installed' => $set, 'plugin' => $set, 'listed' => $set, 'domain' => self::domain(), 'found' => $set ];
	}
	$dir = self::dir();
	$installed = is_file($dir . '/node_modules/haraka/bin/haraka');
	$plugin = is_file($dir . '/plugins/' . self::HARAKA_PLUGIN . '.js');
	$list = @file($dir . '/config/plugins', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
	$listed = in_array(self::HARAKA_PLUGIN, array_map('trim', $list), true);
	return [ 'dir' => $dir, 'installed' => $installed, 'plugin' => $plugin, 'listed' => $listed, 'domain' => self::domain(), 'found' => $installed && $plugin && $listed ];
    }

    //Haraka's service for the Services page (hook app:services, sys\Boot): when found and its unit is this installation's (not another installation's on this server)
    public static function service() {
	//CLD: 2026-11-01T19:00:00+02:00: in Docker no unit to own (the haraka container)
	if (!self::found() || (!Services::docker() && !Services::ownUnit('nfn-haraka.service')))
	    return null;
	return [
	    'label' => 'Inbound mail (Haraka)',
	    'description' => 'Receives the mail of the Email (inbound) trigger, on port 25',
	    'unit' => 'nfn-haraka.service',
	    'pidfile' => BASE . '/run/haraka.pid',
	    'health' => function() {
		//CLD: 2026-11-01T19:00:00+02:00: on HARAKA_HOST
		$s = @stream_socket_client('tcp://' . self::host() . ':25', $errno, $errstr, 2);
		if (!$s)
		    return [ false, 'port 25 does not answer' . ($errstr ? ": {$errstr}" : '') ];
		stream_set_timeout($s, 3);
		$banner = trim((string)fgets($s, 512));
		@fwrite($s, "QUIT\r\n");
		fclose($s);
		return str_starts_with($banner, '220') ? [ true, 'SMTP answers on port 25' ] : [ false, 'port 25: ' . ($banner !== '' ? mb_substr($banner, 0, 80) : 'no greeting') ];
	    },
	];
    }
}
