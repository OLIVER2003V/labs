<?php

use App\Http\Middleware\RegistrarBitacora;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'bitacora' => RegistrarBitacora::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Todas las respuestas de error de la API en JSON y en español
        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $r->is('api/*') || $r->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $r) {
            if ($r->is('api/*')) {
                return response()->json(['message' => 'Sesión no válida o expirada. Inicie sesión nuevamente.'], 401);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $r) {
            if ($r->is('api/*')) {
                $msg = $e->getPrevious() instanceof ModelNotFoundException
                    ? 'El registro solicitado no existe o fue eliminado.'
                    : 'Recurso no encontrado.';

                return response()->json(['message' => $msg], 404);
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $r) {
            return response()->json(['message' => 'Demasiados intentos. Espere un minuto e intente de nuevo.'], 429);
        });

        // Errores de la base de datos traducidos a mensajes entendibles
        $exceptions->render(function (QueryException $e, Request $r) {
            if (! $r->is('api/*')) {
                return null;
            }
            $estado = $e->errorInfo[0] ?? '';
            $texto = $e->getMessage();

            if (str_contains($texto, 'CHOQUE:')) {
                preg_match('/CHOQUE: ([^\n(]+)/', $texto, $m);

                return response()->json(['message' => 'Conflicto de horario: '.trim($m[1] ?? 'existe un choque.')], 422);
            }

            return match ($estado) {
                '23505' => response()->json(['message' => 'Ya existe un registro con esos datos (valor duplicado).'], 422),
                '23503' => response()->json(['message' => 'No se puede completar la operación porque el registro está relacionado con otros datos.'], 409),
                '23514' => response()->json(['message' => 'Los datos no cumplen las reglas de la base de datos (valor no permitido).'], 422),
                '22P02', '22007', '22008' => response()->json(['message' => 'Formato de dato inválido.'], 422),
                default => response()->json(['message' => 'Error de base de datos. Intente nuevamente.'], 500),
            };
        });
    })->create();
