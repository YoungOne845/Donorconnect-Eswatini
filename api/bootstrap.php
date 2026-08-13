<?php

declare(strict_types=1);

use App\Core\Env;
use App\Core\HttpException;
use App\Core\Response;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

Env::load(__DIR__ . '/.env');
date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Africa/Mbabane'));

$allowedOrigins = array_filter(array_map('trim', explode(',', (string) Env::get('FRONTEND_URL', 'http://localhost:5173'))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Permissions-Policy: geolocation=(), camera=(), microphone=()");

$secure = Env::bool('SESSION_SECURE', false);
session_name((string) Env::get('SESSION_NAME', 'donorconnect_session'));
session_set_cookie_params([
    'lifetime' => Env::int('SESSION_LIFETIME_MINUTES', 120) * 60,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => (string) Env::get('SESSION_SAMESITE', 'Lax'),
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_start();

set_exception_handler(static function (Throwable $exception): never {
    $debug = Env::bool('APP_DEBUG', false);
    $logDirectory = __DIR__ . '/storage/logs';
    if (!is_dir($logDirectory)) {
        mkdir($logDirectory, 0775, true);
    }
    error_log(sprintf("[%s] %s in %s:%d\n%s\n", date('c'), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString()), 3, $logDirectory . '/app.log');

    if ($exception instanceof HttpException) {
        Response::error($exception->getMessage(), $exception->status, $exception->errors);
    }

    Response::error(
        $debug ? $exception->getMessage() : 'An unexpected server error occurred.',
        500,
        $debug ? ['exception' => $exception::class, 'file' => $exception->getFile(), 'line' => $exception->getLine()] : null
    );
});
