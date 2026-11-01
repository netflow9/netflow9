<?php
$__base = dirname(__FILE__, 2);
ini_set('error_log', "$__base/logs/php_error.log");
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('log_errors', TRUE);

require("$__base/app/sys/V.php");
require("$__base/app/config.php");
//CLD: 2026-11-01T19:00:00+02:00: app.env in V::configDir() (NFN_CONFIG_DIR, else the app's folder)
$__env = V::configDir() . '/app.env';

if (!is_file($__env)) {
    define('BASE', $__base);
    $app = V::create(get_setup_config());
    $app->run();
    exit;
}

V::parse_env($__env);

$app = V::create(get_config('web'));
$app->run();
