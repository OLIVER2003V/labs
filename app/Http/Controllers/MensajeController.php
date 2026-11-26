<?php

namespace App\Http\Controllers;

use App\Models\Mensaje;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CU5 - Chat interno (MSG / VERMSG del proyecto 1): mensajes privados entre usuarios con el permiso mensajes.usar
 * (auxiliares y Encargado) para coordinar relevos y permutas. La pantalla consulta cada pocos segundos (polling).
 */
class MensajeController extends Controller
{
    /** GET /api/mensajes → mis conversaciones: con quién, último mensaje y cuántos no leí */
    public function conversaciones(Request $request): JsonResponse
    {
        $yo = $request->user()->id;
        $mensajes = Mensaje::where('remitente_id', $yo)->orWhere('destinatario_id', $yo)->orderByDesc('id')->limit(1000)->get();

        $porContacto = $mensajes->groupBy(fn ($m) => $m->remitente_id === $yo ? $m->destinatario_id : $m->remitente_id);
        $usuarios = User::with('rol')->whereIn('id', $porContacto->keys())->get()->keyBy('id');

        $lista = $porContacto->map(fn ($grupo, $id) => [
            'usuario' => $this->contacto($usuarios[$id] ?? null, $id),
            'ultimo' => ['contenido' => $grupo->first()->contenido, 'mio' => $grupo->first()->remitente_id === $yo, 'created_at' => $grupo->first()->created_at],
            'no_leidos' => $grupo->where('destinatario_id', $yo)->whereNull('leido_en')->count(),
        ])->values();

        return response()->json(['data' => $lista, 'no_leidos' => $lista->sum('no_leidos')]);
    }

    /** GET /api/mensajes/contactos → con quién puedo iniciar un chat */
    public function contactos(Request $request): JsonResponse
    {
        return response()->json($this->consultaContactos($request->user()->id)->orderBy('nombre')->get()->map(fn ($u) => $this->contacto($u, $u->id)));
    }

    /** GET /api/mensajes/{usuario} → la conversación con ese usuario (y la marca como leída) */
    public function conversacion(Request $request, int $usuario): JsonResponse
    {
        $yo = $request->user()->id;
        $otro = User::with('rol')->findOrFail($usuario);

        Mensaje::where('remitente_id', $otro->id)->where('destinatario_id', $yo)->whereNull('leido_en')->update(['leido_en' => now()]);

        $mensajes = Mensaje::where(fn ($q) => $q->where('remitente_id', $yo)->where('destinatario_id', $otro->id))
            ->orWhere(fn ($q) => $q->where('remitente_id', $otro->id)->where('destinatario_id', $yo))
            ->orderByDesc('id')->limit(200)->get()->reverse()->values()
            ->map(fn ($m) => ['id' => $m->id, 'contenido' => $m->contenido, 'mio' => $m->remitente_id === $yo,
                'created_at' => $m->created_at, 'leido_en' => $m->leido_en]);

        return response()->json(['usuario' => $this->contacto($otro, $otro->id), 'mensajes' => $mensajes]);
    }

    /** POST /api/mensajes  { destinatario_id, contenido } */
    public function enviar(Request $request): JsonResponse
    {
        $yo = $request->user();
        $d = $request->validate([
            'destinatario_id' => ['required', 'integer'],
            'contenido' => ['required', 'string', 'max:1000'],
        ], ['contenido.required' => 'Escriba un mensaje.', 'contenido.max' => 'El mensaje no puede pasar de 1000 caracteres.']);

        $contenido = trim($d['contenido']);
        abort_if($contenido === '', 422, 'Escriba un mensaje.');
        abort_if((int) $d['destinatario_id'] === $yo->id, 422, 'No puede enviarse mensajes a sí mismo.');
        abort_unless($this->consultaContactos($yo->id)->where('usuarios.id', $d['destinatario_id'])->exists(), 422,
            'Ese usuario no puede recibir mensajes (no está activo o no usa el chat).');

        $m = Mensaje::create(['remitente_id' => $yo->id, 'destinatario_id' => $d['destinatario_id'], 'contenido' => $contenido]);

        return response()->json(['message' => 'Mensaje enviado.', 'data' => [
            'id' => $m->id, 'contenido' => $m->contenido, 'mio' => true, 'created_at' => $m->created_at, 'leido_en' => null,
        ]], 201);
    }

    /** Usuarios activos (menos yo) cuyo rol tiene el permiso de usar el chat */
    private function consultaContactos(int $excepto)
    {
        return User::with('rol')->where('usuarios.estado', 'ACTIVO')->where('usuarios.id', '<>', $excepto)
            ->whereIn('usuarios.rol_id', DB::table('rol_permiso')->join('permisos', 'permisos.id', '=', 'rol_permiso.permiso_id')
                ->where('permisos.codigo', 'mensajes.usar')->select('rol_permiso.rol_id'));
    }

    /** Minutos sin actividad después de los cuales ya no se muestra "en línea" */
    const MINUTOS_EN_LINEA = 2;

    private function contacto(?User $u, int $id): array
    {
        // Presencia: el middleware RegistrarBitacora guarda en "presencia" la última vez que cada usuario usó el sistema
        $visto = DB::table('presencia')->where('usuario_id', $id)->value('visto_en');
        $visto = $visto ? \Carbon\Carbon::parse($visto) : null;

        return ['id' => $id, 'nombre' => $u?->nombre_completo ?? 'Usuario eliminado', 'rol' => $u?->rol?->nombre ?? '',
            'en_linea' => $visto !== null && $visto->gte(now()->subMinutes(self::MINUTOS_EN_LINEA)),
            'ultima_vez' => $visto?->toIso8601String()];
    }
}
