<?php

namespace App\Http\Middleware;

use App\Models\Bitacora;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Se ejecuta en cada petición autenticada a la API:
 *  1. Rechaza a usuarios dados de baja (estado INACTIVO).
 *  2. Indica a PostgreSQL quién es el usuario (app.usuario_id) para que los triggers de auditoría lo guarden.
 *  3. Registra el acceso en la bitácora (recurso, método, IP, código HTTP) → "tráfico" y "recursos más accedidos".
 */
class RegistrarBitacora
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario && $usuario->estado !== 'ACTIVO') {
            $usuario->currentAccessToken()?->delete();

            return response()->json(['message' => 'Su cuenta está inactiva. Contacte al administrador.'], 403);
        }

        if ($usuario) {
            DB::select("SELECT set_config('app.usuario_id', ?, false)", [(string) $usuario->id]);
        }

        $response = $next($request);

        if ($usuario && $response->getStatusCode() !== 403) { // los 403 ya los registra VerificarPermiso
            Bitacora::create([
                'usuario_id' => $usuario->id,
                'email' => $usuario->email,
                'evento' => 'ACCESO',
                'metodo' => $request->method(),
                'recurso' => self::recurso($request),
                'ruta' => substr($request->path(), 0, 200),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'codigo_http' => $response->getStatusCode(),
            ]);
        }

        return $response;
    }

    /** api/reservas/5/aprobar  →  reservas */
    public static function recurso(Request $request): string
    {
        $partes = explode('/', $request->path());
        $i = array_search('api', $partes, true);

        return $partes[$i === false ? 0 : $i + 1] ?? 'inicio';
    }
}
