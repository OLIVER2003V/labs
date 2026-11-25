<?php

namespace App\Http\Controllers;

use App\Models\FechaBloqueada;
use App\Models\Reserva;
use App\Models\Semestre;
use App\Services\Disponibilidad;
use App\Services\Notificador;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU2 - Fechas bloqueadas: feriados, PSA, CUP (Curso Preuniversitario), mantenimientos.
 *  - Rango de días (desde–hasta): se crea un registro por día y laboratorio, sin domingos.
 *  - Uno, varios o todos los laboratorios.
 *  - Horario opcional (ej: PSA de 08:00 a 12:00): fuera de ese horario el laboratorio sigue disponible.
 *  - Período previo: se puede bloquear hasta 90 días ANTES del inicio del semestre (PSA y CUP suelen ser antes).
 *  - Sin duplicados: si ya hay un bloqueo que cubre ese día, laboratorio y horario, se omite.
 *  - Reservas afectadas: se piden confirmar; al confirmar se cancelan y se notifica a cada solicitante.
 */
class FechaBloqueadaController extends CrudController
{
    protected string $modelo = FechaBloqueada::class;
    protected array $buscarEn = ['tipo', 'motivo', 'laboratorio.codigo'];
    protected array $con = ['semestre', 'laboratorio'];
    protected array $filtros = ['semestre_id', 'tipo'];
    protected string $ordenarPor = 'fecha';

    const TIPOS = ['FERIADO', 'PSA', 'CUP', 'MANTENIMIENTO', 'OTRO'];
    const DIAS_PREVIOS = 90;   // cuántos días antes del inicio del semestre se puede bloquear
    const MAX_DIAS = 120;      // tamaño máximo de un rango

    private function reglasHorario(): array
    {
        return [
            'hora_inicio' => ['nullable', 'date_format:H:i', 'required_with:hora_fin'],
            'hora_fin' => ['nullable', 'date_format:H:i', 'required_with:hora_inicio', 'after:hora_inicio'],
        ];
    }

    /** Reglas de edición (un día, un laboratorio o todos). La creación usa store() con rango. */
    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'semestre_id' => ['required', 'integer', 'exists:semestres,id'],
            'fecha' => ['required', 'date'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'],
        ] + $this->reglasHorario();
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'semestre_id' => ['required', 'integer', 'exists:semestres,id'],
            'fecha_desde' => ['required', 'date'],
            'fecha_hasta' => ['nullable', 'date', 'after_or_equal:fecha_desde'],
            'tipo' => ['required', Rule::in(self::TIPOS)],
            'motivo' => ['required', 'string', 'min:3', 'max:200'],
            'laboratorios' => ['nullable', 'array'],                 // varios laboratorios (vacío = todos)
            'laboratorios.*' => ['integer', 'exists:laboratorios,id'],
            'laboratorio_id' => ['nullable', 'integer', 'exists:laboratorios,id'], // uno solo (compatibilidad)
        ] + $this->reglasHorario(), [], ['fecha_desde' => 'fecha desde', 'fecha_hasta' => 'fecha hasta']);

        $desde = $d['fecha_desde'];
        $hasta = $d['fecha_hasta'] ?? $desde;
        [$hi, $hf] = [$d['hora_inicio'] ?? null, $d['hora_fin'] ?? null];
        $labs = array_values(array_unique($d['laboratorios'] ?? (isset($d['laboratorio_id']) ? [$d['laboratorio_id']] : [])));
        $destinos = $labs ?: [null]; // null = todos los laboratorios

        $this->dentroDelSemestre($d['semestre_id'], $desde, $hasta);
        abort_if(count(CarbonPeriod::create($desde, $hasta)) > self::MAX_DIAS, 422, 'El rango no puede superar '.self::MAX_DIAS.' días.');

        $dias = collect(CarbonPeriod::create($desde, $hasta))->reject->isSunday()->map->toDateString()->values();
        abort_if($dias->isEmpty(), 422, 'El rango elegido solo contiene domingos.');

        // Combinaciones día × laboratorio que todavía no están bloqueadas en ese horario
        $nuevos = [];
        $omitidos = 0;
        foreach ($dias as $dia) {
            foreach ($destinos as $lab) {
                $this->yaBloqueado($dia, $lab, $hi, $hf) ? $omitidos++ : $nuevos[] = [$dia, $lab];
            }
        }
        abort_if(! $nuevos, 422, count($dias) * count($destinos) === 1 ? 'Ya existe un bloqueo para esa fecha y horario.' : 'Todas esas fechas ya están bloqueadas en ese horario.');

        $afectadas = collect($nuevos)->flatMap(fn ($n) => $this->reservasAfectadas($n[0], $n[1], $hi, $hf))->unique('id')->values();
        $this->exigirConfirmacion($request, $afectadas);

        $motivo = $d['tipo'].': '.$d['motivo'].($hi ? " ({$hi}–{$hf})" : '');
        $canceladas = DB::transaction(function () use ($nuevos, $d, $hi, $hf, $afectadas, $motivo, $request) {
            foreach ($nuevos as [$dia, $lab]) {
                FechaBloqueada::create(['semestre_id' => $d['semestre_id'], 'fecha' => $dia, 'hora_inicio' => $hi, 'hora_fin' => $hf,
                    'tipo' => $d['tipo'], 'motivo' => $d['motivo'], 'laboratorio_id' => $lab]);
            }

            return Notificador::cancelarReservasAfectadas($afectadas, "bloqueo $motivo", $request->user()->id);
        });

        $n = count($nuevos);
        $msg = ($n === 1 ? 'Se creó 1 bloqueo' : "Se crearon $n bloqueos")
            .' ('.count($dias).' día(s)'.($labs ? ' × '.count($labs).' laboratorio(s)' : ', todos los laboratorios').($hi ? ", de {$hi} a {$hf}" : ', todo el día').')'
            .($omitidos ? ". $omitidos ya estaba(n) bloqueado(s) y se omitieron" : '')
            .($canceladas ? ". $canceladas reserva(s) cancelada(s) y sus solicitantes fueron notificados." : '.');

        return response()->json(['message' => $msg, 'creados' => $n, 'omitidos' => $omitidos, 'canceladas' => $canceladas], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $registro = FechaBloqueada::findOrFail($id);
        $d = $request->validate($this->reglas($request, $registro));
        $lab = $d['laboratorio_id'] ?? null;
        [$hi, $hf] = [$d['hora_inicio'] ?? null, $d['hora_fin'] ?? null];
        $this->dentroDelSemestre($d['semestre_id'], $d['fecha'], $d['fecha']);
        abort_if(date('N', strtotime($d['fecha'])) == 7, 422, 'Los domingos los laboratorios no atienden: no hace falta bloquearlos.');
        abort_if($this->yaBloqueado($d['fecha'], $lab, $hi, $hf, $registro->id), 422, 'Ya existe un bloqueo para esa fecha y horario.');

        $afectadas = $this->reservasAfectadas($d['fecha'], $lab, $hi, $hf);
        $this->exigirConfirmacion($request, $afectadas);

        DB::transaction(function () use ($registro, $d, $hi, $hf, $afectadas, $request) {
            $registro->update(['hora_inicio' => $hi, 'hora_fin' => $hf] + $d);
            Notificador::cancelarReservasAfectadas($afectadas, "bloqueo {$d['tipo']}: {$d['motivo']}", $request->user()->id);
        });

        return response()->json(['message' => 'Bloqueo actualizado.'.($afectadas->count() ? " {$afectadas->count()} reserva(s) cancelada(s) y notificadas." : ''),
            'data' => $registro->fresh($this->con)]);
    }

    /** Desde 90 días antes del inicio del semestre hasta su fin (PSA y CUP suelen ser antes del inicio) */
    private function dentroDelSemestre(int $semestreId, string $desde, string $hasta): void
    {
        $sem = Semestre::findOrFail($semestreId);
        $minimo = $sem->fecha_inicio->copy()->subDays(self::DIAS_PREVIOS);
        $fuera = $desde < $minimo->format('Y-m-d') || $hasta > $sem->fecha_fin->format('Y-m-d');
        abort_if($fuera, 422, "Para el semestre {$sem->gestion} se puede bloquear del ".$minimo->format('d/m/Y')
            .' ('.self::DIAS_PREVIOS.' días antes del inicio, para PSA o CUP) al '.$sem->fecha_fin->format('d/m/Y').'.');
    }

    /** ¿Ya hay un bloqueo que cubre ese día, laboratorio (o todos) y horario? */
    private function yaBloqueado(string $dia, ?int $lab, ?string $hi, ?string $hf, ?int $excepto = null): bool
    {
        return FechaBloqueada::whereDate('fecha', $dia)
            ->when($excepto, fn ($q) => $q->where('id', '<>', $excepto))
            ->where(fn ($q) => $q->whereNull('laboratorio_id')->when($lab, fn ($x) => $x->orWhere('laboratorio_id', $lab)))
            ->get()
            ->contains(fn (FechaBloqueada $b) => $b->seCruzaCon($hi, $hf));
    }

    /** Reservas pendientes o aprobadas que caen en ese día, laboratorio (o todos) y horario */
    private function reservasAfectadas(string $dia, ?int $lab, ?string $hi, ?string $hf)
    {
        return Reserva::with(['laboratorio', 'solicitante'])
            ->whereIn('estado', ['PENDIENTE', 'APROBADA'])->whereDate('fecha', $dia)
            ->when($lab, fn ($q) => $q->where('laboratorio_id', $lab))
            ->when($hi, fn ($q) => $q->where('hora_inicio', '<', Disponibilidad::hms($hf))->where('hora_fin', '>', Disponibilidad::hms($hi)))
            ->orderBy('hora_inicio')->get();
    }

    /** Si hay reservas afectadas y no se confirmó, responde 409 con la lista para que el usuario decida */
    private function exigirConfirmacion(Request $request, $afectadas): void
    {
        if ($afectadas->isNotEmpty() && ! $request->boolean('confirmar')) {
            abort(response()->json([
                'message' => "Ese bloqueo afecta reservas existentes: {$afectadas->count()} reserva(s) se cancelarán y se notificará a sus solicitantes.",
                'requiere_confirmacion' => true,
                'detalle' => $afectadas->map(fn ($r) => Notificador::describir($r))->values(),
            ], 409));
        }
    }
}
