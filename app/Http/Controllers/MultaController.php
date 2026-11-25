<?php

namespace App\Http\Controllers;

use App\Models\ConceptoPago;
use App\Models\Multa;
use App\Services\Multas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU9 - Multas: cada usuario ve y paga las suyas; el Encargado ve todas y puede anularlas.
 * Se generan solas (ver App\Services\Multas): atraso y falta del auxiliar (CU7) y reserva no utilizada (CU4).
 * Se pagan con tarjeta en Stripe (POST /api/pagos/stripe { multa_id }).
 */
class MultaController extends CrudController
{
    protected string $modelo = Multa::class;
    protected array $buscarEn = ['motivo', 'tipo', 'estado', 'usuario.nombre', 'usuario.apellido'];
    protected array $con = ['usuario', 'anulador'];
    protected array $filtros = ['estado', 'tipo', 'usuario_id'];
    protected string $ordenarPor = 'fecha';

    /** Las multas no se crean ni editan a mano (no hay rutas para eso): las genera el sistema */
    protected function reglas(Request $request, ?\Illuminate\Database\Eloquent\Model $registro): array
    {
        return [];
    }

    protected function consulta(Builder $query, Request $request): Builder
    {
        if (! $request->user()->tienePermiso('multas.gestionar')) {
            $query->where('usuario_id', $request->user()->id);
        }
        if ($request->boolean('vencidas')) {
            $query->where('estado', 'PENDIENTE')->whereDate('vence_en', '<', today());
        }

        return $query->orderByDesc('id');
    }

    /** GET /api/multas/resumen → totales del usuario (o de todos, para el Encargado) y si está bloqueado */
    public function resumen(Request $request): JsonResponse
    {
        $yo = $request->user();
        $todas = $yo->tienePermiso('multas.gestionar');
        $q = fn () => Multa::when(! $todas, fn ($x) => $x->where('usuario_id', $yo->id));
        [$nVencidas, $montoVencido] = Multas::vencidas($yo->id);

        return response()->json([
            'pendientes' => $q()->where('estado', 'PENDIENTE')->count(),
            'monto_pendiente' => (float) $q()->where('estado', 'PENDIENTE')->sum('monto'),
            'vencidas' => $q()->where('estado', 'PENDIENTE')->whereDate('vence_en', '<', today())->count(),
            'monto_pagado' => (float) $q()->where('estado', 'PAGADA')->sum('monto'),
            'bloqueado' => $nVencidas > 0,                   // el propio usuario no puede reservar ni pedir permutas
            'monto_vencido_propio' => $montoVencido,
            'dias_plazo' => Multas::diasDePlazo(),
            'montos' => collect(Multas::CONCEPTO)->map(fn ($codigo) => ConceptoPago::where('codigo', $codigo)->first(['monto', 'activo'])),
        ]);
    }

    /** PUT /api/multas/{id}/anular  { observacion } → el Encargado la anula (ej: el auxiliar presentó una justificación) */
    public function anular(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['observacion' => ['required', 'string', 'min:5', 'max:200']], ['observacion.required' => 'Indique el motivo de la anulación.']);
        $m = Multa::findOrFail($id);
        abort_if($m->estado !== 'PENDIENTE', 422, "La multa ya está {$m->estado}: no se puede anular.");
        Multas::anular($m, $d['observacion'], $request->user()->id);

        return response()->json(['message' => 'Multa anulada. Se avisó al usuario.', 'data' => $m->fresh($this->con)]);
    }
}
