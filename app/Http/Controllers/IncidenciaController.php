<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\Incidencia;
use App\Services\Notificador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU6 - Incidencias de equipos.
 * Al reportar, el TRIGGER tg_incidencia_equipo pone el equipo EN_REPARACION;
 * al resolver la última incidencia abierta, lo devuelve a OPERATIVO.
 *
 * Flujo: PENDIENTE → EN_ATENCION → RESUELTA (no se retrocede; resolver exige escribir la solución).
 * Avisos: al reportar se notifica a quien gestiona incidencias (Encargado);
 *         cuando pasa a EN_ATENCION o RESUELTA se notifica a quien la reportó.
 * Un equipo no puede tener dos incidencias abiertas a la vez (se evita que varios reporten la misma falla).
 */
class IncidenciaController extends CrudController
{
    protected string $modelo = Incidencia::class;
    protected array $buscarEn = ['descripcion', 'estado', 'equipo.codigo', 'reportante.nombre'];
    protected array $con = ['equipo.laboratorio', 'reportante'];
    protected array $filtros = ['estado', 'equipo_id'];
    protected string $ordenarPor = 'created_at';

    private ?string $estadoAnterior = null;

    protected function consulta(Builder $query, Request $request): Builder
    {
        if (! $request->user()->tienePermiso('incidencias.gestionar')) {
            $query->where('reportado_por', $request->user()->id);
        }

        return $query;
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        if (! $registro) { // reportar
            return [
                'equipo_id' => ['required', 'integer', 'exists:equipos,id'],
                'descripcion' => ['required', 'string', 'min:10', 'max:300'],
            ];
        }

        return [ // gestionar (Encargado)
            'estado' => ['required', Rule::in(['PENDIENTE', 'EN_ATENCION', 'RESUELTA'])],
            'solucion' => ['required_if:estado,RESUELTA', 'nullable', 'string', 'min:5', 'max:300'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        if (! $registro) {
            $equipo = Equipo::findOrFail($datos['equipo_id']);
            abort_if($equipo->estado === 'BAJA', 422, 'El equipo está dado de baja.');
            $abierta = $equipo->incidencias()->where('estado', '<>', 'RESUELTA')->first();
            abort_if($abierta, 422, "El equipo {$equipo->codigo} ya tiene la incidencia #{$abierta?->id} abierta ({$abierta?->estado}): «{$abierta?->descripcion}».");
            $datos['reportado_por'] = $request->user()->id;
            $datos['estado'] = 'PENDIENTE';

            return $datos;
        }

        abort_unless($request->user()->tienePermiso('incidencias.gestionar'), 403, 'No tiene permiso para gestionar incidencias.');
        abort_if($registro->estado === 'RESUELTA', 422, 'La incidencia ya está resuelta.');
        abort_if($registro->estado === 'EN_ATENCION' && $datos['estado'] === 'PENDIENTE', 422, 'Una incidencia en atención no puede volver a PENDIENTE.');
        $this->estadoAnterior = $registro->estado;
        $datos['fecha_solucion'] = $datos['estado'] === 'RESUELTA' ? now() : null;

        return $datos;
    }

    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        $registro->load(['equipo.laboratorio', 'reportante']);
        $equipo = "{$registro->equipo->codigo} ({$registro->equipo->laboratorio?->codigo})";

        if ($nuevo) { // Regla 5 del CU6: avisar al Encargado
            Notificador::aQuienesPuedan('incidencias.gestionar', "Nueva incidencia #{$registro->id} en $equipo",
                "{$registro->reportante->nombre_completo} reportó: {$registro->descripcion}. El equipo pasó a EN REPARACIÓN.",
                '/incidencias', null, $request->user()->id);
            $this->mensaje = 'Incidencia reportada. El equipo pasó a «en reparación» y se avisó al Encargado.';

            return;
        }

        if ($registro->estado !== $this->estadoAnterior && $registro->reportado_por !== $request->user()->id) {
            $texto = $registro->estado === 'RESUELTA'
                ? "La falla que reportó en $equipo fue resuelta. Solución: {$registro->solucion}"
                : "La falla que reportó en $equipo está siendo atendida.";
            Notificador::enviar($registro->reportado_por, "Incidencia #{$registro->id}: ".str_replace('_', ' ', $registro->estado), $texto, '/incidencias');
        }
    }
}
