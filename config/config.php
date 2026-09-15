<?php

declare(strict_types=1);

use App\Core\Env;

Env::load(__DIR__ . '/../.env');

$debug = Env::get('APP_DEBUG', '0') === '1';

error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Rome') ?: 'Europe/Rome');
