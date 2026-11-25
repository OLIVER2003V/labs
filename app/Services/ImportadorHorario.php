<?php

namespace App\Services;

use App\Models\Horario;
use App\Models\Laboratorio;
use App\Models\Materia;
use App\Models\Semestre;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CU3 - Carga del horario semestral desde Excel (CARHOR del proyecto 1, ahora en la web).
 *
 * revisar(): valida cada fila SIN guardar y devuelve el resultado fila por fila (vista previa).
 * importar(): guarda solo las filas válidas, todo en una transacción.
 *
 * Reglas por fila: laboratorio existente y DISPONIBLE · día válido (LUNES…SÁBADO o 1…6) · horas 07:00–22:00 y fin > inicio ·
 * sigla y grupo válidos · docente activo con rol Docente (por código o correo) · sin choque de laboratorio ni de docente
 * con el horario del semestre NI con otras filas del mismo archivo. La materia se crea si su sigla es nueva.
 */
class ImportadorHorario
{
    const DIAS = ['LUNES' => 1, 'MARTES' => 2, 'MIERCOLES' => 3, 'JUEVES' => 4, 'VIERNES' => 5, 'SABADO' => 6,
        'LU' => 1, 'MA' => 2, 'MI' => 3, 'JU' => 4, 'VI' => 5, 'SA' => 6];

    private array $labs;
    private array $docentes;     // código y correo (minúsculas) → User
    private array $materias;     // sigla → id
    private array $aceptadas = []; // filas válidas del archivo, para detectar choques entre ellas

    public function __construct(private Semestre $semestre)
    {
        $this->labs = Laboratorio::all()->keyBy(fn ($l) => strtoupper($l->codigo))->all();
        $this->docentes = [];
        foreach (User::whereHas('rol', fn ($r) => $r->where('codigo', 'DOC'))->get() as $u) {
            $this->docentes[strtolower($u->codigo)] = $u;
            $this->docentes[strtolower($u->email)] = $u;
        }
        $this->materias = Materia::pluck('id', 'sigla')->all();
    }

    /** @return array{filas: array, validas: int, errores: int} */
    public function revisar(array $filas): array
    {
        $resultado = [];
        foreach ($filas as $f) {
            $resultado[] = $this->validarFila($f['fila'], $f['valores']);
        }

        return [
            'filas' => $resultado,
            'validas' => count(array_filter($resultado, fn ($r) => $r['ok'])),
            'errores' => count(array_filter($resultado, fn ($r) => ! $r['ok'])),
        ];
    }

    /** Guarda las filas válidas. @return array{creadas: int, materias_nuevas: int, errores: int, filas: array} */
    public function importar(array $filas): array
    {
        $revision = $this->revisar($filas);
        $materiasNuevas = 0;
        DB::transaction(function () use ($revision, &$materiasNuevas) {
            foreach ($revision['filas'] as $r) {
                if (! $r['ok']) {
                    continue;
                }
                $d = $r['datos'];
                if (! isset($this->materias[$d['sigla']])) {
                    $this->materias[$d['sigla']] = Materia::create(['sigla' => $d['sigla'], 'nombre' => $d['materia']])->id;
                    $materiasNuevas++;
                }
                Horario::create([
                    'semestre_id' => $this->semestre->id, 'laboratorio_id' => $d['laboratorio_id'], 'materia_id' => $this->materias[$d['sigla']],
                    'docente_id' => $d['docente_id'], 'grupo' => $d['grupo'], 'dia_semana' => $d['dia'],
                    'hora_inicio' => $d['inicio'], 'hora_fin' => $d['fin'],
                ]);
            }
        });

        return ['creadas' => $revision['validas'], 'materias_nuevas' => $materiasNuevas, 'errores' => $revision['errores'], 'filas' => $revision['filas']];
    }

    private function validarFila(int $num, array $v): array
    {
        [$labTxt, $diaTxt, $iniTxt, $finTxt, $siglaTxt, $materiaTxt, $grupoTxt, $docTxt] = $v;
        $res = fn (?string $error, array $datos = []) => [
            'fila' => $num, 'valores' => $v, 'ok' => $error === null, 'mensaje' => $error ?? 'Lista para importar', 'datos' => $datos,
        ];

        $lab = $this->labs[strtoupper($labTxt)] ?? null;
        if (! $lab) {
            return $res("El laboratorio \"$labTxt\" no existe.");
        }
        if ($lab->estado !== 'DISPONIBLE') {
            return $res("El laboratorio {$lab->codigo} está en {$lab->estado}.");
        }
        $dia = $this->dia($diaTxt);
        if (! $dia) {
            return $res("Día \"$diaTxt\" inválido (use LUNES a SÁBADO).");
        }
        $ini = $this->hora($iniTxt);
        $fin = $this->hora($finTxt);
        if (! $ini || ! $fin) {
            return $res('Hora inválida (use el formato HH:MM, ej: 07:00).');
        }
        if ($fin <= $ini) {
            return $res('La hora de fin debe ser posterior a la de inicio.');
        }
        if ($ini < '07:00' || $fin > '22:00') {
            return $res('Los laboratorios atienden de 07:00 a 22:00.');
        }
        $sigla = strtoupper(preg_replace('/\s+/', '', $siglaTxt));
        if (! preg_match('/^[A-Z]{3}\d{3}$/', $sigla)) {
            return $res("Sigla \"$siglaTxt\" inválida (3 letras y 3 números, ej: INF513).");
        }
        if (! isset($this->materias[$sigla]) && mb_strlen(trim($materiaTxt)) < 3) {
            return $res("La materia $sigla es nueva: escriba su nombre.");
        }
        $grupo = strtoupper(trim($grupoTxt));
        if (! preg_match('/^[A-Z0-9]{1,5}$/', $grupo)) {
            return $res("Grupo \"$grupoTxt\" inválido (1 a 5 letras o números).");
        }
        $docente = $this->docentes[strtolower(trim($docTxt))] ?? null;
        if (! $docente) {
            return $res("No hay un docente con código o correo \"$docTxt\".");
        }
        if ($docente->estado !== 'ACTIVO') {
            return $res("El docente {$docente->nombre_completo} está inactivo.");
        }

        // Choques con el horario ya registrado del semestre
        $choque = Horario::with('materia')->where('semestre_id', $this->semestre->id)->where('dia_semana', $dia)
            ->where('hora_inicio', '<', "$fin:00")->where('hora_fin', '>', "$ini:00")
            ->where(fn ($q) => $q->where('laboratorio_id', $lab->id)->orWhere('docente_id', $docente->id))->first();
        if ($choque) {
            $quien = $choque->laboratorio_id === $lab->id ? "{$lab->codigo} ya tiene" : "El docente ya tiene";

            return $res("$quien la clase {$choque->materia->sigla}-{$choque->grupo} de ".substr($choque->hora_inicio, 0, 5).' a '.substr($choque->hora_fin, 0, 5).'.');
        }
        // Choques con otra fila del mismo archivo
        foreach ($this->aceptadas as $a) {
            if ($a['dia'] === $dia && $a['inicio'] < $fin && $ini < $a['fin'] && ($a['laboratorio_id'] === $lab->id || $a['docente_id'] === $docente->id)) {
                return $res("Choca con la fila {$a['fila']} del archivo ({$a['sigla']}-{$a['grupo']}, {$a['inicio']}–{$a['fin']}).");
            }
        }

        $datos = ['fila' => $num, 'laboratorio_id' => $lab->id, 'dia' => $dia, 'inicio' => $ini, 'fin' => $fin, 'sigla' => $sigla,
            'materia' => mb_substr(trim($materiaTxt), 0, 100), 'grupo' => $grupo, 'docente_id' => $docente->id];
        $this->aceptadas[] = $datos;

        return $res(null, $datos + ['docente' => $docente->nombre_completo]);
    }

    private function dia(string $txt): ?int
    {
        $t = strtoupper(strtr(trim($txt), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']));
        if (ctype_digit($t) && (int) $t >= 1 && (int) $t <= 6) {
            return (int) $t;
        }

        return self::DIAS[$t] ?? null;
    }

    /** "7:00", "07:00", "07:00:00" → "07:00" */
    private function hora(string $txt): ?string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', trim($txt), $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $m[1], $m[2]);
    }
}
