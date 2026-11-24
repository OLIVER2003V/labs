<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Importa los DATOS REALES del proyecto 1 (sistema vía mail, esquema "sislab")
 * hacia las tablas del proyecto 2. Solo LEE sislab: nunca lo modifica.
 *
 *   php artisan db:seed --class=ImportarSislabSeeder   (o migrate:fresh --seed con ORIGEN_DATOS=sislab)
 *
 * Se conservan los ID originales, así las relaciones (FK) se mantienen tal cual.
 * Los usuarios del proyecto 1 no tenían contraseña (entraban por correo): reciben PASSWORD_INICIAL (Ficct2026).
 */
class ImportarSislabSeeder extends Seeder
{
    /** Tablas del proyecto 2 que se llenan aquí (para desactivar sus triggers durante la carga) */
    const DESTINO = ['usuarios', 'laboratorios', 'semestres', 'fechas_bloqueadas', 'materias', 'horarios', 'reservas',
        'equipos', 'incidencias', 'objetos_encontrados', 'turnos', 'asistencias', 'bitacora'];

    public static function disponible(): bool
    {
        $o = config('database.origen_sislab', 'sislab');

        return (bool) DB::selectOne("SELECT to_regclass(?) AS t", ["$o.usuario"])->t;
    }

    public function run(): void
    {
        $o = config('database.origen_sislab', 'sislab'); // esquema de origen
        $s = fn (string $tabla) => DB::table("$o.$tabla");
        $roles = DB::table('roles')->pluck('id', 'codigo');
        $rolOrigen = $s('rol')->pluck('codigo', 'id');
        $password = Hash::make(env('PASSWORD_INICIAL', 'Ficct2026'));
        $vacio = fn ($v) => ($v === null || trim((string) $v) === '') ? null : trim($v);

        // Durante la carga se apagan los triggers (no auditar la importación, no alterar estados)
        foreach (self::DESTINO as $t) {
            DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
        }

        $this->lote('usuarios', $s('usuario')->orderBy('id')->get()->map(fn ($u) => [
            'id' => $u->id, 'codigo' => trim($u->codigo), 'nombre' => trim($u->nombre), 'apellido' => trim($u->apellido) ?: '-',
            'email' => strtolower(trim($u->correo)), 'telefono' => $vacio($u->telefono), 'password' => $password,
            'rol_id' => $roles[$rolOrigen[$u->rol_id]] ?? $roles['DOC'], 'estado' => $u->estado === 'ACTIVO' ? 'ACTIVO' : 'INACTIVO',
            'created_at' => $u->creado_en ?? now(), 'updated_at' => now(),
        ]));

        $this->lote('laboratorios', $s('laboratorio')->orderBy('id')->get()->map(fn ($l) => [
            'id' => $l->id, 'codigo' => $l->codigo, 'nombre' => $l->nombre, 'capacidad' => $l->capacidad,
            'ubicacion' => $l->ubicacion, 'estado' => $l->estado, 'tarifa_hora' => 150, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->lote('semestres', $s('semestre')->orderBy('id')->get()->map(fn ($x) => (array) $x + ['created_at' => now(), 'updated_at' => now()]));
        $this->lote('fechas_bloqueadas', $s('fecha_bloqueada')->orderBy('id')->get()->map(fn ($x) => (array) $x + ['created_at' => now(), 'updated_at' => now()]));
        $this->lote('materias', $s('materia')->orderBy('id')->get()->map(fn ($x) => (array) $x + ['created_at' => now(), 'updated_at' => now()]));
        $this->lote('horarios', $s('horario_clase')->orderBy('id')->get()->map(fn ($x) => (array) $x + ['created_at' => now(), 'updated_at' => now()]));

        $this->lote('reservas', $s('reserva')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'laboratorio_id' => $r->laboratorio_id, 'solicitante_id' => $r->solicitante_id, 'fecha' => $r->fecha,
            'hora_inicio' => $r->hora_inicio, 'hora_fin' => $r->hora_fin,
            'tipo' => str_starts_with((string) $r->tipo, 'DEFENSA') ? 'DEFENSA' : $r->tipo,
            'motivo' => $vacio($r->motivo) ?? 'Sin motivo', 'estado' => $r->estado, 'resuelto_por' => $r->resuelto_por,
            'observacion' => $r->observacion, 'created_at' => $r->creado_en ?? now(), 'updated_at' => $r->creado_en ?? now(),
        ]));

        $this->lote('equipos', $s('equipo')->orderBy('id')->get()->map(fn ($e) => [
            'id' => $e->id, 'codigo' => $e->codigo, 'activo_fijo' => $vacio($e->activo), 'laboratorio_id' => $e->laboratorio_id,
            'tipo' => $e->tipo, 'descripcion' => $e->descripcion, 'estado' => $e->estado, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->lote('incidencias', $s('incidencia')->orderBy('id')->get()->map(fn ($i) => [
            'id' => $i->id, 'equipo_id' => $i->equipo_id, 'reportado_por' => $i->reportado_por, 'descripcion' => $i->descripcion,
            'estado' => $i->estado, 'solucion' => $i->solucion, 'fecha_solucion' => $i->fecha_solucion,
            'created_at' => $i->fecha_reporte ?? now(), 'updated_at' => $i->fecha_solucion ?? $i->fecha_reporte ?? now(),
        ]));

        $this->lote('objetos_encontrados', $s('objeto_encontrado')->orderBy('id')->get()->map(fn ($x) => [
            'id' => $x->id, 'laboratorio_id' => $x->laboratorio_id, 'descripcion' => $x->descripcion, 'fecha_hallazgo' => $x->fecha_hallazgo,
            'registrado_por' => $x->registrado_por ?? $x->encontrado_por, 'estado' => $x->estado, 'entregado_a' => $vacio($x->entregado_a),
            'ci_receptor' => $vacio($x->ci_receptor), 'fecha_entrega' => $x->fecha_entrega,
            'created_at' => $x->fecha_hallazgo, 'updated_at' => $x->fecha_entrega ?? $x->fecha_hallazgo,
        ]));

        $this->lote('turnos', $s('turno')->orderBy('id')->get()->map(fn ($x) => (array) $x + ['created_at' => now(), 'updated_at' => now()]));
        $this->lote('asistencias', $s('asistencia')->orderBy('id')->get()->map(fn ($x) => (array) $x + [
            'observacion' => $x->estado === 'FALTA' ? 'Sin registro de entrada' : null, 'created_at' => $x->fecha, 'updated_at' => $x->fecha,
        ]));

        // Bitácora del proyecto 1: cada comando recibido por correo se registra como un acceso
        $emails = DB::table('usuarios')->pluck('id', 'email');
        $this->lote('bitacora', $s('bitacora')->orderBy('id')->get()->map(fn ($b) => [
            'usuario_id' => $emails[strtolower((string) $b->remitente)] ?? null, 'email' => substr((string) $b->remitente, 0, 100),
            'evento' => $b->resultado === 'DENEGADO' ? 'DENEGADO' : 'ACCESO', 'metodo' => 'MAIL',
            'recurso' => strtolower(substr((string) ($b->comando ?: 'correo'), 0, 60)), 'ruta' => substr((string) $b->asunto, 0, 200),
            'ip' => null, 'user_agent' => 'Proyecto 1 (vía mail)', 'codigo_http' => $b->resultado === 'OK' ? 200 : ($b->resultado === 'DENEGADO' ? 403 : 422),
            'detalle' => substr((string) $b->detalle, 0, 300) ?: null, 'created_at' => $b->fecha,
        ]));

        foreach (self::DESTINO as $t) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1), (SELECT COUNT(*) > 0 FROM {$t}))");
            DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
        }

        // Cuenta del Administrador del sistema (rol nuevo del proyecto 2; no existía en el proyecto 1)
        DB::table('usuarios')->insert([
            'codigo' => 'ADM-001', 'nombre' => 'Administrador', 'apellido' => 'Sistema', 'email' => 'admin@ficct.edu.bo',
            'password' => $password, 'rol_id' => $roles['ADM'], 'estado' => 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->command?->info('Importados desde '.$o.': '.collect(self::DESTINO)->map(fn ($t) => "$t=".DB::table($t)->count())->implode(', '));
    }

    private function lote(string $tabla, $filas): void
    {
        foreach ($filas->chunk(300) as $c) {
            DB::table($tabla)->insert($c->values()->all());
        }
    }
}
