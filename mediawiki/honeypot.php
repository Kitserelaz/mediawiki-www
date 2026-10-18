<?php
$logFile = '/var/log/nginx/honeypot.log';
$time = date('c');
$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'UNKNOWN';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
$url = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';

$logEntry = "[$time] IP (X-Forwarded-For): $ip, URL: $url, User-Agent: $userAgent\n";
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// Kill connection abruptly, no headers or body
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request(); // finish PHP process but keep connection open to webserver
}
ignore_user_abort(true); // keep running even if client disconnects

// Close socket abruptly
if (isset($_SERVER['REMOTE_ADDR'])) {
    // This won't actually close the TCP socket from PHP side, but you can try this:
    header("Connection: close");
    header("Content-Length: 0");
}
exit();