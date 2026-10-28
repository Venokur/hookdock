<?php

declare(strict_types=1);

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/autoload.php';

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('[%s] %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    $debug = App\Config::get('APP_DEBUG', '0') === '1';
    App\Http\Response::error($debug ? $e->getMessage() : 'Внутренняя ошибка сервера', 500);
});
