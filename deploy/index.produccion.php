<?php

/*
 * index.php de PRODUCCIÓN (carpeta pública del servidor).
 * El código de Laravel vive FUERA de la carpeta web (más seguro: nadie puede descargar .env).
 * deploy/instalar.sh reemplaza __APP_DIR__ por la ruta real, ej: /home/grupo08sa/proyecto2_app
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$appDir = '__APP_DIR__';

if (file_exists($maintenance = $appDir.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appDir.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appDir.'/bootstrap/app.php';

// La carpeta pública es ESTA carpeta (donde están build/, favicon, .htaccess)
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
