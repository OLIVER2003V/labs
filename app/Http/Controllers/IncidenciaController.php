<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\Incidencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU6 - Incidencias de equipos.
 * Al reportar, el TRIGGER tg_incidencia_equipo pone el equipo EN_REPARACION;
 * al resolver la última incidencia abierta, lo devuelve a OPERATIVO.
 */
class IncidenciaController extends CrudController
{
    protected string $modelo = Incidencia::class;
    protected array $buscarEn = ['descripcion', 'estado', 'equipo.codigo', 'reportante.nombre'];
    protected array $con = ['equipo.laboratorio', 'reportante'];
    protected array $filtros = ['estado', 'equipo_id'];
    protected string $ordenarPor = 'created_at';

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
            $datos['reportado_por'] = $request->user()->id;
            $datos['estado'] = 'PENDIENTE';

            return $datos;
        }

        abort_unless($request->user()->tienePermiso('incidencias.gestionar'), 403, 'No tiene permiso para gestionar incidencias.');
        abort_if($registro->estado === 'RESUELTA', 422, 'La incidencia ya está resuelta.');
        $datos['fecha_solucion'] = $datos['estado'] === 'RESUELTA' ? now() : null;

        return $datos;
    }
}
