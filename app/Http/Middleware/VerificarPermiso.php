<?php

namespace App\Http\Middleware;

use App\Models\Bitacora;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Matriz de acceso: deja pasar solo si el rol del usuario tiene el permiso pedido.
 * Uso en rutas:  ->middleware('permiso:reservas.crear')
 * Varios permisos (basta uno):  ->middleware('permiso:reservas.ver|reservas.ver_todas')
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string $permisos): Response
    {
        $usuario = $request->user();
        $requeridos = explode('|', $permisos);

        foreach ($requeridos as $permiso) {
            if ($usuario && $usuario->tienePermiso($permiso)) {
                return $next($request);
            }
        }

        Bitacora::create([
            'usuario_id' => $usuario?->id,
            'email' => $usuario?->email,
            'evento' => 'DENEGADO',
            'metodo' => $request->method(),
            'recurso' => RegistrarBitacora::recurso($request),
            'ruta' => substr($request->path(), 0, 200),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'codigo_http' => 403,
            'detalle' => 'Permiso requerido: '.$permisos,
        ]);

        return response()->json([
            'message' => 'No tiene permiso para realizar esta acción.',
        ], 403);
    }
}
