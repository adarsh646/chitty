<?php
// Copy this file's values to match your MySQL setup, or set them as environment
// variables (DB_HOST, DB_NAME, DB_USER, DB_PASS) in your web server config.

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'chitty',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: 'Admin@iiiTk',
        'charset' => 'utf8mb4',
    ],
    // Change this before going live. Used only to namespace PHP session cookies.
    'session_name' => 'chitty_session',
];
