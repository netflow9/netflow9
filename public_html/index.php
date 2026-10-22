<?php
$__base = dirname(__FILE__, 2);
ini_set('error_log', "$__base/logs/php_error.log");
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('log_errors', TRUE);

require("$__base/app/sys/V.php");
require("$__base/app/config.php");

if (!is_file("$__base/app.env")) {
    define('BASE', $__base);
    $app = V::create(get_setup_config());
    $app->run();
    exit;
}

V::parse_env("$__base/app.env");

$app = V::create(get_config('web'));
$app->run();
