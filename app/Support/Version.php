<?php

namespace App\Support;

/**
 * Versión del frontend publicado: una huella del public/build/manifest.json (cambia en cada compilación).
 * La página la recibe al cargar (window.APP.version) y la API la manda en cada respuesta (X-App-Version):
 * si no coinciden, el navegador tiene abierta una versión vieja y se le ofrece recargar.
 */
class Version
{
    private static ?string $actual = null;

    public static function actual(): string
    {
        if (self::$actual === null) {
            $manifest = public_path('build/manifest.json');
            self::$actual = is_file($manifest) ? substr(md5_file($manifest), 0, 10) : 'dev';
        }

        return self::$actual;
    }
}
