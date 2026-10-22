#!/usr/bin/php -d display_errors -d session.save_path='/tmp' -f
<?php
error_reporting(E_ALL & ~E_DEPRECATED);

$__base = dirname(__FILE__, 2);
require("$__base/app/sys/V.php");
require("$__base/app/config.php");

//load .env
V::parse_env("$__base/app.env");

$app = V::create(get_config('console'));
$app->run();
