<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Respaldo y restauración de TODOS los datos en un archivo JSON (storage/app/respaldos).
 * No depende de pg_dump: funciona con solo PHP y la conexión de Laravel.
 */
class Respaldo
{
    /** Tablas en orden de dependencias (padres antes que hijos) */
    const TABLAS = [
        'roles', 'permisos', 'rol_permiso', 'menus', 'usuarios', 'personal_access_tokens',
        'laboratorios', 'semestres', 'fechas_bloqueadas', 'materias', 'horarios', 'reservas',
        'equipos', 'equipos_red', 'incidencias', 'objetos_encontrados', 'turnos', 'asistencias', 'permisos_auxiliar', 'permutas',
        'conceptos_pago', 'metodos_pago', 'planes_pago', 'cuotas', 'transacciones',
        'notificaciones', 'mensajes', 'bitacora', 'visitas_pagina', 'auditoria',
    ];

    public static function carpeta(): string
    {
        // Respaldos del servidor (php artisan ... --env=servidor) y los locales en carpetas distintas
        $dir = storage_path('app/respaldos'.(app()->environment('production') ? '/servidor' : ''));
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** Crea el respaldo y devuelve [ruta, totalFilas] */
    public static function crear(?string $nombre = null): array
    {
        $datos = ['creado' => now()->toDateTimeString(), 'tablas' => []];
        $total = 0;
        foreach (self::TABLAS as $tabla) {
            $filas = DB::table($tabla)->get()->map(fn ($f) => (array) $f)->all();
            $datos['tablas'][$tabla] = $filas;
            $total += count($filas);
        }
        $nombre ??= 'respaldo_'.now()->format('Ymd_His').'.json';
        $ruta = self::carpeta().DIRECTORY_SEPARATOR.$nombre;
        File::put($ruta, json_encode($datos, JSON_UNESCAPED_UNICODE));
        File::copy($ruta, self::carpeta().DIRECTORY_SEPARATOR.'ultimo.json');

        return [$ruta, $total];
    }

    /** Lista de respaldos disponibles (más reciente primero) */
    public static function listar(): array
    {
        $archivos = array_filter(glob(self::carpeta().DIRECTORY_SEPARATOR.'*.json') ?: [], fn ($a) => basename($a) !== 'ultimo.json');
        usort($archivos, fn ($a, $b) => filemtime($b) <=> filemtime($a)); // más reciente primero

        return array_values($archivos);
    }

    /**
     * Restaura un respaldo: vacía las tablas y vuelve a insertar todo, en una sola transacción.
     * Los triggers de usuario se desactivan mientras tanto (para no alterar estados ni auditar la carga).
     */
    public static function restaurar(string $ruta): int
    {
        $datos = json_decode(File::get($ruta), true, 512, JSON_THROW_ON_ERROR);
        $total = 0;

        DB::transaction(function () use ($datos, &$total) {
            foreach (self::TABLAS as $t) {
                DB::statement("ALTER TABLE {$t} DISABLE TRIGGER USER");
            }
            DB::statement('TRUNCATE '.implode(', ', self::TABLAS).' RESTART IDENTITY CASCADE');

            foreach (self::TABLAS as $tabla) {
                $columnas = DB::getSchemaBuilder()->getColumnListing($tabla);
                $filas = array_map(
                    fn ($f) => array_intersect_key($f, array_flip($columnas)),
                    $datos['tablas'][$tabla] ?? []
                );
                foreach (array_chunk($filas, 300) as $lote) {
                    DB::table($tabla)->insert($lote);
                }
                $total += count($filas);
                if (in_array('id', $columnas, true)) {
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$tabla}', 'id'), COALESCE((SELECT MAX(id) FROM {$tabla}), 1), (SELECT COUNT(*) > 0 FROM {$tabla}))");
                }
            }

            foreach (self::TABLAS as $t) {
                DB::statement("ALTER TABLE {$t} ENABLE TRIGGER USER");
            }
        });

        return $total;
    }

    /** Evita borrar tablas de OTROS proyectos (migrate:fresh) si el esquema es compartido. null = es seguro */
    public static function motivoParaNoReconstruir(): ?string
    {
        $esquema = DB::selectOne('SELECT current_schema() AS s')->s;
        $ajenas = collect(DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'))
            ->pluck('tablename')
            ->diff(array_merge(self::TABLAS, ['migrations']))
            ->values();

        return $ajenas->isNotEmpty()
            ? "El esquema \"$esquema\" tiene tablas de otro proyecto (".$ajenas->take(5)->implode(', ').'). '
              .'migrate:fresh las borraría. Use un esquema propio (DB_SCHEMA=proyecto2).'
            : null;
    }
}
