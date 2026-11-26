<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CU6 - Imagen de un objeto encontrado. Dos orígenes:
 *  1. FOTO SUBIDA por el auxiliar → storage/app/private/objetos (se sirve en /media/objetos/{archivo}, ver routes/web.php).
 *  2. IMAGEN DE REFERENCIA según el tipo de objeto (public/img/objetos/*.jpg, fotos libres de Wikimedia Commons,
 *     créditos en public/img/objetos/CREDITOS.md). Se elige sola por palabras de la descripción.
 * En la base se guarda la ruta relativa: "img/objetos/mouse.jpg" o "media/objetos/AbC123.jpg".
 */
class ImagenObjeto
{
    const CARPETA_SUBIDAS = 'objetos';
    const PREFIJO_SUBIDA = 'media/objetos/';
    const PREFIJO_REFERENCIA = 'img/objetos/';

    /** Palabras de la descripción → imagen de referencia (el orden importa: lo más específico primero) */
    const REGLAS = [
        '/billetera/i' => 'billetera',
        '/carnet|credencial/i' => 'carnet',
        '/^(celular|tel[eé]fono)/i' => 'celular', // "Celular Infinix y un cargador": manda el celular
        '/mouse ?pad/i' => 'mousepad',
        '/mouse|rat[oó]n/i' => 'mouse',
        '/guante/i' => 'guantes',
        '/(estuche|funda|cajita).*(lente|gafa)|(lente|gafa).*(estuche|funda)/i' => 'estuche_lentes',
        '/lente|gafa/i' => 'lentes',
        '/cargador.*(laptop|compu|notebook|port[aá]til|dell|asus|chicony|45w|65w)|cargador (hp|lenovo)/i' => 'cargador_laptop',
        '/cargador|cabezal/i' => 'cargador_celular',
        '/auricular|aud[ií]fono|buds/i' => '__audifonos',
        '/celular|tel[eé]fono|smartphone/i' => 'celular',
        '/laptop|port[aá]til|notebook/i' => 'laptop',
        '/cuaderno|libreta/i' => 'cuaderno',
        '/mochila/i' => 'mochila',
        '/chompa|chamarra|chaqueta|su[eé]ter|polera|buzo/i' => 'chompa',
        '/bolsa|ropa/i' => 'ropa',
        '/vaso|jarra|taza/i' => 'vaso',
        '/termo|stanley/i' => 'termo',
        '/tomatodo|botella/i' => 'tomatodo',
        '/^cable/i' => 'cable',
        '/flash|usb|pendrive|memoria/i' => 'usb',
        '/gorra|gorro|sombrero/i' => 'gorra',
        '/estuche|cartuchera/i' => 'estuche',
        '/chalina|bufanda/i' => 'chalina',
        '/traper|carpeta|archivador/i' => 'carpeta',
        '/raqueta/i' => 'raqueta',
        '/borrar pizarra|borrador|almohadilla/i' => 'borrador',
        '/brillo|labial|maquillaje/i' => 'brillo',
        '/hoja|documento|folder|formulario|papel/i' => 'documentos',
    ];

    /** Imagen de referencia para una descripción, o null si no se reconoce el objeto */
    public static function sugerir(string $descripcion): ?string
    {
        foreach (self::REGLAS as $patron => $imagen) {
            if (preg_match($patron, $descripcion)) {
                if ($imagen === '__audifonos') { // inalámbricos (con estuche de carga) o con cable
                    $imagen = preg_match('/inal[aá]mbric|buds|caja|estuche/i', $descripcion) ? 'audifonos_inalambricos' : 'audifonos_cable';
                }

                return self::PREFIJO_REFERENCIA.$imagen.'.jpg';
            }
        }

        return null;
    }

    public static function esSubida(?string $imagen): bool
    {
        return $imagen !== null && str_starts_with($imagen, self::PREFIJO_SUBIDA);
    }

    /** Guarda la foto subida y devuelve la ruta para la base de datos */
    public static function guardar(UploadedFile $archivo): string
    {
        $nombre = Str::random(32).'.'.strtolower($archivo->extension() === 'jpeg' ? 'jpg' : $archivo->extension());
        Storage::disk('local')->putFileAs(self::CARPETA_SUBIDAS, $archivo, $nombre);

        return self::PREFIJO_SUBIDA.$nombre;
    }

    /** Borra el archivo de una foto subida (las de referencia no se tocan) */
    public static function borrar(?string $imagen): void
    {
        if (self::esSubida($imagen)) {
            Storage::disk('local')->delete(self::CARPETA_SUBIDAS.'/'.basename($imagen));
        }
    }
}
