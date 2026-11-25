<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones del usuario (campanita del encabezado). Cada uno ve solo las suyas.
 */
class NotificacionController extends Controller
{
    /** Últimas 30 + cantidad de no leídas */
    public function index(Request $request): JsonResponse
    {
        $id = $request->user()->id;

        return response()->json([
            'no_leidas' => DB::table('notificaciones')->where('usuario_id', $id)->whereNull('leida_en')->count(),
            'data' => DB::table('notificaciones')->where('usuario_id', $id)->orderByDesc('id')->limit(30)
                ->get(['id', 'titulo', 'mensaje', 'url', 'leida_en', 'created_at']),
        ]);
    }

    public function leer(Request $request, int $id): JsonResponse
    {
        $n = DB::table('notificaciones')->where('id', $id)->where('usuario_id', $request->user()->id)->update(['leida_en' => now()]);
        abort_unless($n, 404, 'Notificación no encontrada.');

        return response()->json(['message' => 'Leída.']);
    }

    public function leerTodas(Request $request): JsonResponse
    {
        DB::table('notificaciones')->where('usuario_id', $request->user()->id)->whereNull('leida_en')->update(['leida_en' => now()]);

        return response()->json(['message' => 'Todas marcadas como leídas.']);
    }
}
