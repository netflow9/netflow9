#!/usr/bin/php -d display_errors -d session.save_path='/tmp' -f
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

$__base = dirname(__FILE__, 2);
require("$__base/app/sys/V.php");
require("$__base/app/config.php");
//app.env in V::configDir() (NFN_CONFIG_DIR, else the app's folder)
$__env = V::configDir() . '/app.env';

//no app.env yet: the setup's console (get_setup_console_config), as public_html/index.php does for the browser; ../v sys::install sets the app up
if (!is_file($__env)) {
    //only the setup's commands: the others need the app's settings
    //sys::checks / validate / install / indexes
    if (!in_array((string)($argv[1] ?? ''), [ 'sys::checks', 'sys::validate', 'sys::install', 'sys::indexes' ], true)) {
	fwrite(STDERR, "the app is not set up yet (no app.env): sudo $__base/install.sh, or open the site in a browser\n");
	exit(1);
    }
    define('BASE', $__base);
    $app = V::create(get_setup_console_config());
    $app->run();
    exit;
}

//load .env
V::parse_env($__env);

$app = V::create(get_config('console'));
$app->run();
