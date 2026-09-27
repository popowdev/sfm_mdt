<?php

if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    http_response_code(403); exit;
}

if (!defined('BOT_API_KEY')) {
    $key = null;
    $envPath = __DIR__ . '/../bot/.env';
    if (is_readable($envPath)) {
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                if (strpos($line, '=') === false) continue;
                list($k, $v) = explode('=', $line, 2);
                if (trim($k) === 'API_KEY') { $key = trim($v); break; }
            }
        }
    }
    if (!$key) {
        error_log('[bot_config] API_KEY introuvable dans bot/.env, fallback en lecture seule');
        $key = '';
    }
    define('BOT_API_KEY', $key);
}

if (!defined('BOT_API_URL')) {
    define('BOT_API_URL', 'http://127.0.0.1:3000');
}
