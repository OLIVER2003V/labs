<?php

namespace App\Http\Controllers;

use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Services\Notificador;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** CU2 - Laboratorios */
class LaboratorioController extends CrudController
{
    protected string $modelo = Laboratorio::class;
    protected array $buscarEn = ['codigo', 'nombre', 'ubicacion', 'estado'];
    protected array $filtros = ['estado'];
    protected string $ordenarPor = 'codigo';
    protected string $direccion = 'asc';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9-]{2,10}$/', Rule::unique('laboratorios', 'codigo')->ignore($registro?->id)],
            'nombre' => ['required', 'string', 'min:3', 'max:60'],
            'capacidad' => ['required', 'integer', 'between:1,200'],
            'ubicacion' => ['nullable', 'string', 'max:100'],
            'estado' => ['required', Rule::in(['DISPONIBLE', 'MANTENIMIENTO', 'INHABILITADO'])],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['codigo'] = strtoupper($datos['codigo']);

        // Si el laboratorio deja de estar DISPONIBLE, sus reservas futuras ya no se pueden cumplir
        if ($registro && $registro->estado === 'DISPONIBLE' && $datos['estado'] !== 'DISPONIBLE') {
            $afectadas = Reserva::with(['laboratorio', 'solicitante'])->where('laboratorio_id', $registro->id)
                ->whereIn('estado', ['PENDIENTE', 'APROBADA'])->whereDate('fecha', '>=', now()->toDateString())
                ->orderBy('fecha')->orderBy('hora_inicio')->get();
            if ($afectadas->isNotEmpty() && ! $request->boolean('confirmar')) {
                abort(response()->json([
                    'message' => "Pasar {$registro->codigo} a {$datos['estado']} afecta {$afectadas->count()} reserva(s) futura(s): se cancelarán y se notificará a sus solicitantes.",
                    'requiere_confirmacion' => true,
                    'detalle' => $afectadas->map(fn ($r) => Notificador::describir($r))->values(),
                ], 409));
            }
            Notificador::cancelarReservasAfectadas($afectadas, "el laboratorio {$registro->codigo} pasó a estado {$datos['estado']}", $request->user()->id);
        }

        return $datos;
    }
}
