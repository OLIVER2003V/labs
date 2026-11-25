<?php

namespace App\Http\Controllers;

use App\Models\FechaBloqueada;
use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Services\Notificador;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU2 - Fechas bloqueadas (feriados, PSA, mantenimiento).
 *  - Se puede bloquear un RANGO (desde–hasta): se crea un registro por día, sin domingos.
 *  - No se permiten duplicados: un día ya bloqueado (para ese laboratorio o para todos) se omite.
 *  - Si hay reservas PENDIENTES o APROBADAS en esos días, se piden confirmar; al confirmar se cancelan
 *    y cada solicitante recibe una notificación con el motivo.
 */
class FechaBloqueadaController extends CrudController
{
    protected string $modelo = FechaBloqueada::class;
    protected array $buscarEn = ['tipo', 'motivo', 'laboratorio.codigo'];
    protected array $con = ['semestre', 'laboratorio'];
    protected array $filtros = ['semestre_id', 'tipo'];
    protected string $ordenarPor = 'fecha';

    const TIPOS = ['FERIADO', 'PSA', 'MANTENIMIENTO', 'OTRO'];

    /** Reglas de edición (un solo día). La creación usa store() con rango. */
    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'semestre_id' => ['required', 'integer', 'exists:semestres,id'],
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'semestre_id' => ['required', 'integer', 'exists:semestres,id'],
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
        ], [], ['fecha_desde' => 'fecha desde', 'fecha_hasta' => 'fecha hasta']);
        $desde = $d['fecha_desde'];
        $hasta = $d['fecha_hasta'] ?? $desde;
        $lab = $d['laboratorio_id'] ?? null;

        $this->dentroDelSemestre($d['semestre_id'], $desde, $hasta);
        abort_if(count(CarbonPeriod::create($desde, $hasta)) > 62, 422, 'El rango no puede superar 62 días.');

        // Días del rango, sin domingos (los laboratorios no atienden)
        $dias = collect(CarbonPeriod::create($desde, $hasta))->reject->isSunday()->map->toDateString()->values();
        abort_if($dias->isEmpty(), 422, 'El rango elegido solo contiene domingos.');

        // Sin duplicados: se omiten los días que ya están bloqueados para este laboratorio o para todos
        $yaBloqueados = $this->diasYaBloqueados($dias->all(), $lab);
        $nuevos = $dias->diff($yaBloqueados)->values();
        abort_if($nuevos->isEmpty(), 422, $dias->count() === 1 ? 'Esa fecha ya está bloqueada.' : 'Todas esas fechas ya están bloqueadas.');

        $motivo = "{$d['tipo']}: {$d['motivo']}";
        $afectadas = $this->reservasAfectadas($nuevos->all(), $lab);
        $this->exigirConfirmacion($request, $afectadas, 'Ese bloqueo afecta reservas existentes');

        $canceladas = DB::transaction(function () use ($nuevos, $d, $lab, $afectadas, $motivo, $request) {
            foreach ($nuevos as $dia) {
                FechaBloqueada::create(['semestre_id' => $d['semestre_id'], 'fecha' => $dia, 'tipo' => $d['tipo'], 'motivo' => $d['motivo'], 'laboratorio_id' => $lab]);
            }

            return Notificador::cancelarReservasAfectadas($afectadas, "fecha bloqueada ($motivo)", $request->user()->id);
        });

        $msg = ($nuevos->count() === 1 ? 'Se bloqueó 1 día' : "Se bloquearon {$nuevos->count()} días")
            .($yaBloqueados ? ' ('.count($yaBloqueados).' ya estaba(n) bloqueado(s) y se omitieron)' : '')
            .($canceladas ? ". $canceladas reserva(s) cancelada(s) y sus solicitantes fueron notificados." : '.');

        return response()->json(['message' => $msg, 'creados' => $nuevos->count(), 'canceladas' => $canceladas], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $registro = FechaBloqueada::findOrFail($id);
        $d = $request->validate($this->reglas($request, $registro));
        $lab = $d['laboratorio_id'] ?? null;
        $this->dentroDelSemestre($d['semestre_id'], $d['fecha'], $d['fecha']);
        abort_if(date('N', strtotime($d['fecha'])) == 7, 422, 'Los domingos los laboratorios no atienden: no hace falta bloquearlos.');
        abort_if($this->diasYaBloqueados([$d['fecha']], $lab, $registro->id), 422, 'Esa fecha ya está bloqueada.');

        $afectadas = $this->reservasAfectadas([$d['fecha']], $lab);
        $this->exigirConfirmacion($request, $afectadas, 'Ese bloqueo afecta reservas existentes');

        DB::transaction(function () use ($registro, $d, $afectadas, $request) {
            $registro->update($d);
            Notificador::cancelarReservasAfectadas($afectadas, "fecha bloqueada ({$d['tipo']}: {$d['motivo']})", $request->user()->id);
        });

        return response()->json(['message' => 'Fecha bloqueada actualizada.'.($afectadas->count() ? " {$afectadas->count()} reserva(s) cancelada(s) y notificadas." : ''),
            'data' => $registro->fresh($this->con)]);
    }

    private function dentroDelSemestre(int $semestreId, string $desde, string $hasta): void
    {
        $sem = Semestre::findOrFail($semestreId);
        $fuera = $desde < $sem->fecha_inicio->format('Y-m-d') || $hasta > $sem->fecha_fin->format('Y-m-d');
        abort_if($fuera, 422, "Las fechas deben estar dentro del semestre {$sem->gestion} (".$sem->fecha_inicio->format('d/m/Y').' al '.$sem->fecha_fin->format('d/m/Y').').');
    }

    /** Días ya bloqueados: para el mismo laboratorio, o con un bloqueo general (todos los laboratorios) */
    private function diasYaBloqueados(array $dias, ?int $lab, ?int $excepto = null): array
    {
        return FechaBloqueada::whereIn('fecha', $dias)
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))
            ->where(fn ($q) => $q->whereNull('laboratorio_id')->when($lab, fn ($x) => $x->orWhere('laboratorio_id', $lab)))
            ->pluck('fecha')->map(fn ($f) => $f->format('Y-m-d'))->unique()->values()->all();
    }

    /** Reservas pendientes o aprobadas en esos días (de ese laboratorio, o de todos si el bloqueo es general) */
    private function reservasAfectadas(array $dias, ?int $lab)
    {
        return Reserva::with(['laboratorio', 'solicitante'])
            ->whereIn('estado', ['PENDIENTE', 'APROBADA'])->whereIn('fecha', $dias)
            ->when($lab, fn ($q) => $q->where('laboratorio_id', $lab))
            ->orderBy('fecha')->orderBy('hora_inicio')->get();
    }

    /** Si hay reservas afectadas y no se confirmó, responde 409 con la lista para que el usuario decida */
    private function exigirConfirmacion(Request $request, $afectadas, string $titulo): void
    {
        if ($afectadas->isNotEmpty() && ! $request->boolean('confirmar')) {
            abort(response()->json([
                'message' => "$titulo: {$afectadas->count()} reserva(s) se cancelarán y se notificará a sus solicitantes.",
                'requiere_confirmacion' => true,
                'detalle' => $afectadas->map(fn ($r) => Notificador::describir($r))->values(),
            ], 409));
        }
    }
}
