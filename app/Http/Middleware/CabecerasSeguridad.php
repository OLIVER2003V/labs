<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras HTTP de seguridad en todas las respuestas:
 *  - X-Frame-Options: impide que el sitio se cargue dentro de un iframe ajeno (clickjacking)
 *  - X-Content-Type-Options: el navegador no "adivina" tipos de archivo
 *  - Referrer-Policy y Permissions-Policy: menos datos expuestos a terceros
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);
        $respuesta->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $respuesta->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $respuesta;
    }
}
