<?php
declare(strict_types=1);
// Set environment variables on hosting, never hardcode credentials.
define('MONITORING_API_KEY', getenv('AQM_API_KEY') ?: '');
define('TELEGRAM_BOT_TOKEN', getenv('AQM_TELEGRAM_BOT_TOKEN') ?: '');
const DEVICE_ACTIVE_VALUE = 'online';
const NOTIFY_ON_FIRST_READING = false;
const TELEGRAM_CONNECT_TIMEOUT = 8;
const TELEGRAM_REQUEST_TIMEOUT = 15;
