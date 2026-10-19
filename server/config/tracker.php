<?php

return [
    'storage' => env('TRACKER_STORAGE', 'local'),
    'ftps_url' => env('TRACKER_FTPS_URL'),
    'ftps_username' => env('TRACKER_FTPS_USERNAME'),
    'ftps_password' => env('TRACKER_FTPS_PASSWORD'),
    'curl_binary' => env('TRACKER_CURL_BINARY', PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl'),
    'token_days' => 30,
];
