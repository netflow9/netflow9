<?php

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('log_errors', TRUE);
//CLD: 2026-10-21T22:00:00+03:00: error log outside the web root (nginx served it as a static file)
ini_set('error_log', '/home/app/logs/mm_php_error.log');
set_time_limit(0);

define('__ROOT__', dirname(__FILE__));

require(__ROOT__ . '/classes.php');
require(__ROOT__ . '/app.php');
require(__ROOT__ . '/functions.php');

//--------------------------------------------------------------------------------------------------------
//start the stuff
//CLD: 2026-10-21T22:00:00+03:00: the config is loaded before the IP check (it ran on an undefined $config, so it let everyone in);
//the address is REMOTE_ADDR only (nginx is the first hop; X-Forwarded-For is sent by the client and can be faked); refused with 403
$config = require(__ROOT__ . '/config.php');

$user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (empty($config['restrict_ip']) || !Utils::checkIp($user_ip, $config['restrict_ip'])) {
    http_response_code(403);
    echo 'Forbidden';
    die();
}

if (!extension_loaded('mongodb')) {
    echo 'MongoDB extension not loaded';
    die();
}

$rock = new R($config);
$rock->start();
