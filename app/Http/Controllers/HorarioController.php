<?php

namespace App\Http\Controllers;

use App\Models\Horario;
use App\Models\Semestre;
use App\Models\User;
use App\Services\Disponibilidad;
use App\Services\Notificador;
use Illuminate\Support\Collection;
use App\Services\ImportadorHorario;
use App\Services\LectorHorario;
use App\Models\Laboratorio;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** CU3 - Carga horaria base (clases semanales en laboratorio) */
class HorarioController extends CrudController
{
    protected string $modelo = Horario::class;
    protected array $buscarEn = ['grupo', 'materia.sigla', 'materia.nombre', 'laboratorio.codigo', 'docente.nombre', 'docente.apellido'];
    protected array $con = ['materia', 'laboratorio', 'docente', 'semestre'];
    protected array $filtros = ['laboratorio_id', 'dia_semana', 'docente_id', 'semestre_id'];
    protected string $ordenarPor = 'dia_semana';
    protected string $direccion = 'asc';

    /** Reservas que la clase va a pisar (se cancelan después de guardar) */
    private ?Collection $afectadas = null;

    protected function consulta(Builder $query, Request $request): Builder
    {
        // Por defecto se muestra el horario del semestre activo
        if (! $request->filled('semestre_id') && ($activo = Semestre::activo())) {
            $query->where('semestre_id', $activo->id);
        }

        return $query->orderBy('hora_inicio');
    }

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'materia_id' => ['required', 'integer', 'exists:materias,id'],
            'docente_id' => ['required', 'integer', 'exists:usuarios,id'],
            'grupo' => ['required', 'regex:/^[A-Za-z0-9]{1,5}$/'],
            'dia_semana' => ['required', 'integer', 'between:1,6'],
            'hora_inicio' => ['required', 'date_format:H:i', 'after_or_equal:07:00'],
            'hora_fin' => ['required', 'date_format:H:i', 'after:hora_inicio', 'before_or_equal:22:00'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $semestre = Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo. Active uno en Configuración > Semestres.');
        $datos['semestre_id'] = $registro?->semestre_id ?? $semestre->id;
        $datos['grupo'] = strtoupper($datos['grupo']);

        $docente = User::with('rol')->findOrFail($datos['docente_id']);
        abort_unless($docente->rol->codigo === 'DOC', 422, 'El usuario seleccionado no tiene el rol Docente.');

        $choque = Horario::with('materia')
            ->where('semestre_id', $datos['semestre_id'])
            ->where('dia_semana', $datos['dia_semana'])
            ->where('id', '<>', $registro?->id ?? 0)
            ->where('hora_inicio', '<', Disponibilidad::hms($datos['hora_fin']))
            ->where('hora_fin', '>', Disponibilidad::hms($datos['hora_inicio']))
            ->where(fn ($q) => $q->where('laboratorio_id', $datos['laboratorio_id'])->orWhere('docente_id', $datos['docente_id']))
            ->first();

        if ($choque) {
            $quien = $choque->laboratorio_id == $datos['laboratorio_id'] ? 'El laboratorio' : 'El docente';
            abort(422, "$quien ya tiene la clase {$choque->materia->sigla}-{$choque->grupo} de "
                .substr($choque->hora_inicio, 0, 5).' a '.substr($choque->hora_fin, 0, 5).' ese día.');
        }

        // Regla FICCT: la clase del horario base tiene prioridad sobre las reservas puntuales.
        // Si la clase cae sobre reservas futuras, se pide confirmar; luego se cancelan y se avisa a cada solicitante.
        $semestre = $registro?->semestre ?? Semestre::find($datos['semestre_id']);
        $this->afectadas = Disponibilidad::reservasSobreClase($semestre, (int) $datos['laboratorio_id'], (int) $datos['dia_semana'], $datos['hora_inicio'], $datos['hora_fin']);
        if ($this->afectadas->isNotEmpty() && ! $request->boolean('confirmar')) {
            abort(response()->json([
                'message' => "Esta clase cae sobre {$this->afectadas->count()} reserva(s) futura(s). La clase tiene prioridad: "
                    .'las reservas se cancelarán y se notificará a sus solicitantes.',
                'requiere_confirmacion' => true,
                'detalle' => $this->afectadas->map(fn ($r) => Notificador::describir($r))->values(),
            ], 409));
        }

        return $datos;
    }

    protected function despuesDeGuardar(Model $registro, Request $request, bool $nuevo): void
    {
        if ($this->afectadas?->isNotEmpty()) {
            $registro->load(['materia', 'laboratorio']);
            $n = Notificador::cancelarReservasAfectadas($this->afectadas,
                "el {$registro->laboratorio->codigo} tiene asignada la clase {$registro->materia->sigla}-{$registro->grupo} en ese horario", $request->user()->id);
            $this->mensaje = ($nuevo ? 'Clase registrada' : 'Clase actualizada')." y se cancelaron $n reserva(s) con aviso a sus solicitantes.";
        }
    }

    /**
     * POST /api/horarios/importar  (archivo .xlsx o .csv)
     *   guardar=0 → solo revisa y devuelve el resultado fila por fila (vista previa)
     *   guardar=1 → importa las filas válidas
     * semestre_id opcional: por defecto el semestre activo (también sirve uno PLANIFICADO, para cargar el próximo).
     */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:2048', 'mimes:xlsx,csv,txt'],
            'semestre_id' => ['nullable', 'integer', 'exists:semestres,id'],
        ], ['archivo.mimes' => 'El archivo debe ser .xlsx (Excel) o .csv.', 'archivo.max' => 'El archivo no puede pesar más de 2 MB.'],
            ['archivo' => 'archivo']);

        $semestre = $request->filled('semestre_id') ? Semestre::findOrFail($request->integer('semestre_id')) : Semestre::activo();
        abort_unless($semestre, 422, 'No hay un semestre activo. Elija el semestre al que pertenece el horario.');
        abort_if($semestre->estado === 'CERRADO', 422, "El semestre {$semestre->gestion} está cerrado.");

        try {
            $filas = LectorHorario::leer($request->file('archivo')->getRealPath(), $request->file('archivo')->getClientOriginalName());
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $importador = new ImportadorHorario($semestre);
        if (! $request->boolean('guardar')) {
            return response()->json(['semestre' => $semestre->gestion] + $importador->revisar($filas));
        }

        $r = $importador->importar($filas, $request->user()->id);
        abort_if($r['creadas'] === 0, 422, 'Ninguna fila es válida: no se importó nada.');

        return response()->json([
            'message' => "Se importaron {$r['creadas']} clase(s) al semestre {$semestre->gestion}"
                .($r['materias_nuevas'] ? " y se registraron {$r['materias_nuevas']} materia(s) nueva(s)" : '')
                .($r['reservas_canceladas'] ? ". Se cancelaron {$r['reservas_canceladas']} reserva(s) que chocaban, con aviso a sus solicitantes" : '')
                .($r['errores'] ? ". {$r['errores']} fila(s) con errores no se importaron." : '.'),
        ] + $r, 201);
    }

    /** GET /api/horarios/plantilla → Excel con el formato y dos filas de ejemplo con datos reales */
    public function plantilla()
    {
        $labs = Laboratorio::where('estado', 'DISPONIBLE')->orderBy('codigo')->limit(2)->pluck('codigo')->all();
        $docentes = User::whereHas('rol', fn ($r) => $r->where('codigo', 'DOC'))->where('estado', 'ACTIVO')->orderBy('id')->limit(2)->pluck('codigo')->all();
        $ejemplos = [
            [$labs[0] ?? 'LAB-41', 'LUNES', '07:00', '08:30', 'INF513', 'TECNOLOGIA WEB', 'SA', $docentes[0] ?? 'codigo del docente'],
            [$labs[1] ?? 'LAB-42', 'MIERCOLES', '14:00', '15:30', 'INF412', 'SISTEMAS DE INFORMACION II', 'SB', $docentes[1] ?? 'correo@ficct.edu.bo'],
        ];

        return response()->download(LectorHorario::plantilla($ejemplos), 'plantilla_horario.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
