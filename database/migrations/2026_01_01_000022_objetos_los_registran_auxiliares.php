<?php

use App\Support\Encargado;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Objetos encontrados: los registran y los entregan los AUXILIARES (el Encargado solo los consulta).
 *  - Los objetos que figuraban registrados por otro rol (ej: los importados del sistema anterior, a nombre del Encargado)
 *    pasan al auxiliar que tenía turno ese día en ese laboratorio (o, si no hay, a un auxiliar con turno ese día).
 *  - El Encargado deja de tener el permiso objetos.crear (ver Encargado::EXCLUIDOS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $auxiliares = DB::table('usuarios')->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->where('roles.codigo', 'AUX')->whereNull('usuarios.deleted_at')->orderBy('usuarios.id')->pluck('usuarios.id');
        if ($auxiliares->isNotEmpty()) {
            $esAux = $auxiliares->flip();
            $objetos = DB::table('objetos_encontrados')->orderBy('id')->get();
            $i = 0;
            foreach ($objetos as $o) {
                if (isset($esAux[$o->registrado_por])) {
                    continue;
                }
                $dia = Carbon::parse($o->fecha_hallazgo)->dayOfWeekIso;
                $turno = fn ($q) => $q->join('semestres as s', 's.id', '=', 't.semestre_id')->where('t.dia_semana', $dia)
                    ->whereIn('t.auxiliar_id', $auxiliares)
                    ->orderByRaw('CASE WHEN ? BETWEEN s.fecha_inicio AND s.fecha_fin THEN 0 ELSE 1 END', [$o->fecha_hallazgo])
                    ->orderBy('t.hora_inicio')->value('t.auxiliar_id');
                $aux = $turno(DB::table('turnos as t')->where('t.laboratorio_id', $o->laboratorio_id))
                    ?? $turno(DB::table('turnos as t'))
                    ?? $auxiliares[$i++ % $auxiliares->count()];
                DB::table('objetos_encontrados')->where('id', $o->id)->update(['registrado_por' => $aux]);
            }
        }

        if (DB::table('roles')->where('codigo', Encargado::ROL)->exists()) {
            Encargado::asegurarRol(); // quita objetos.crear al Encargado
        }
    }

    public function down(): void
    {
        // Los registros reasignados no se revierten (no se guarda quién figuraba antes; queda en la auditoría)
    }
};
