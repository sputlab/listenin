<?php
/**
 * ListenIn — bootstrap comune a web e CLI.
 */
declare(strict_types=1);

define('LISTENIN_ROOT', dirname(__DIR__));
define('LISTENIN_VERSION', trim((string) @file_get_contents(LISTENIN_ROOT . '/VERSION')) ?: '0.0.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'ListenIn\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$configFile = LISTENIN_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configurazione mancante: copiare config/config.example.php in config/config.php');
}
\ListenIn\Core\Config::load(require $configFile);

date_default_timezone_set((string) \ListenIn\Core\Config::get('app.timezone', 'Europe/Rome'));
mb_internal_encoding('UTF-8');

$isDev = \ListenIn\Core\Config::get('app.env') === 'development';
error_reporting(E_ALL);
ini_set('display_errors', $isDev && PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', LISTENIN_ROOT . '/storage/logs/php-error.log');
