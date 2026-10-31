<?php

/*
    the Email (inbound) trigger, mail to <prefix>-<code>@MAIL_INBOUND_DOMAIN starts a workflow.
    Haraka receives the mail (haraka/plugins/nfn.js: checks the address, stores the message, submits the job models/NodeTriggerEmail::deliver). Enabled in app/plugins.php,
    active only when Haraka is found (harakaFound(): the Haraka install with NetFlow9's plugin enabled); inactive, there is no Email (inbound) step and no settings link.
      models/NodeTriggerEmail.php        the step (was models\node\NodeTriggerEmail), its execution:end hook (was registered by sys\Boot)
      models/InboundMailSettings.php     the account's prefix (was the account field mail_prefix), read by the step and by Haraka
      controllers/InboundController      /InboundMail/inbound/settings (views/inbound/settings.php), linked from the Settings menu (hook layout:settings-menu)
*/

namespace plugins\InboundMail;

class Plugin {

    public function __construct() {
	if (!self::harakaFound())
	    return;

	\V::app()->om->registerObjects([
	    //the step (the same object name as in the core: the designer's data and the token index keep working)
	    'NodeTriggerEmail' => [
		'class' => __NAMESPACE__ . '\\models\\NodeTriggerEmail',
		'wfNode' => true,
	    ],
	    'InboundMailSettings' => [
		'class' => __NAMESPACE__ . '\\models\\InboundMailSettings',
	    ],
	]);

	//a completed Email inbound run deletes its raw message (in every mode: the daemon runs them)
	\V::app()->hooks->addHook('execution:end', [ models\NodeTriggerEmail::class, 'afterExecutionEnd' ]);

	//CLD: 2026-10-22T23:00:00+03:00: Haraka, a service of the administration (the Admin plugin): its unit (scripts/4_services-install.sh), its PID file before, SMTP answering on port 25
	//CLD: 2026-10-31T11:05:39+02:00: hook app:services (was admin:services): the core's Services page
	\V::app()->hooks->addHook('app:services', function(\ArrayObject $services) {
	    //CLD: 2026-10-31T18:00:00+02:00: not when the server's Haraka is another installation's (e.g. the live one's, seen from development)
	    if (!\util\Services::ownUnit('nfn-haraka.service'))
		return;
	    $services['haraka'] = [
		'label' => 'Inbound mail (Haraka)',
		'description' => 'Receives the mail of the Email (inbound) trigger, on port 25',
		'unit' => 'nfn-haraka.service',
		'pidfile' => BASE . '/run/haraka.pid',
		'health' => function() {
		    $s = @stream_socket_client('tcp://127.0.0.1:25', $errno, $errstr, 2);
		    if (!$s)
			return [ false, 'port 25 does not answer' . ($errstr ? ": {$errstr}" : '') ];
		    stream_set_timeout($s, 3);
		    $banner = trim((string)fgets($s, 512));
		    @fwrite($s, "QUIT\r\n");
		    fclose($s);
		    return str_starts_with($banner, '220') ? [ true, 'SMTP answers on port 25' ] : [ false, 'port 25: ' . ($banner !== '' ? mb_substr($banner, 0, 80) : 'no greeting') ];
		},
	    ];
	});

	if (\V::app()->config('app.mode') != 'web')
	    return;

	\V::app()->hooks->addHook('layout:settings-menu', function() {
	    if (\V::app()->user->can('Account::update'))
		echo '<a href="' . \V::app()->createUrl('/InboundMail/inbound/settings') . '" class="nav-link"><i class="bi bi-envelope-arrow-down"></i> Inbound mail</a>';
	});
    }

    //where Haraka is installed: app.env HARAKA_DIR, else <app>/haraka (as scripts/haraka.sh)
    public static function harakaDir() {
	return defined('HARAKA_DIR') && HARAKA_DIR !== '' ? rtrim(HARAKA_DIR, '/') : BASE . '/haraka';
    }

    //CLD: 2026-10-21T19:15:00+03:00: the Haraka plugin is `nfn` (plugins/nfn.js, config/nfn.ini); was looked for as `qsm`, so the plugin stayed inactive after the rename
    const HARAKA_PLUGIN = 'nfn';

    //Haraka is found: installed there (node_modules/haraka) with NetFlow9's plugin (plugins/nfn.js) listed in its config/plugins
    public static function harakaFound() {
	static $found = null;
	if ($found !== null)
	    return $found;
	$dir = self::harakaDir();
	if (!is_file($dir . '/node_modules/haraka/bin/haraka') || !is_file($dir . '/plugins/' . self::HARAKA_PLUGIN . '.js'))
	    return $found = false;
	$list = @file($dir . '/config/plugins', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
	return $found = in_array(self::HARAKA_PLUGIN, array_map('trim', $list), true);
    }

}
