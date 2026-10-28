#!/usr/bin/php -d display_errors -d session.save_path='/tmp' -f
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

$__base = dirname(__FILE__, 2);
require("$__base/app/sys/V.php");
require("$__base/app/config.php");

//CLD: 2026-10-29T02:00:00+02:00: no app.env yet: the setup's console (get_setup_console_config), as public_html/index.php does for the browser; ../v setup::install sets the app up
if (!is_file("$__base/app.env")) {
    //only the setup's commands: the others need the app's settings
    if (strpos((string)($argv[1] ?? ''), 'setup::') !== 0) {
	fwrite(STDERR, "the app is not set up yet (no app.env): sudo scripts/install.sh, or open the site in a browser\n");
	exit(1);
    }
    define('BASE', $__base);
    $app = V::create(get_setup_console_config());
    $app->run();
    exit;
}

//load .env
V::parse_env("$__base/app.env");

$app = V::create(get_config('console'));
$app->run();
