<?php

use App\Models\User;
use App\Support\Respaldo;
use App\Support\Triggers;
use Database\Seeders\SeguridadSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;
use App\Services\ControlAsistencia;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| KIT DE RECUPERACIÓN  (ver docs/RECUPERACION.md)
|--------------------------------------------------------------------------
|  php artisan sistema:verificar            → diagnóstico completo (qué está roto y cómo arreglarlo)
|  php artisan sistema:reparar              → reinstala triggers/funciones, permisos, menú, admin y cachés
|  php artisan respaldo:crear               → guarda TODOS los datos en storage/app/respaldos/*.json
|  php artisan respaldo:listar
|  php artisan respaldo:restaurar           → vuelve al último respaldo (ultimo.json)
|  php artisan respaldo:restaurar --reconstruir  → si borraron tablas: recrea el esquema y carga el respaldo
*/

Artisan::command('sistema:verificar', function () {
    $fallas = 0;
    $ok = function (bool $cond, string $que, string $solucion = '') use (&$fallas) {
        $this->line(($cond ? '<fg=green>  ✔ </>' : '<fg=red>  ✘ </>').$que.(! $cond && $solucion ? "  <fg=yellow>→ $solucion</>" : ''));
        $fallas += $cond ? 0 : 1;

        return $cond;
    };

    $this->info('== Configuración');
    $ok(File::exists(base_path('.env')), 'Archivo .env', 'copie el respaldo: cp ~/respaldos/.env .env');
    $ok(! empty(config('app.key')), 'APP_KEY definida', 'php artisan key:generate');
    $this->line('     APP_URL='.config('app.url').'  APP_DEBUG='.(config('app.debug') ? 'true (desactívelo en producción)' : 'false'));
    $ok(File::exists(public_path('build/manifest.json')), 'Frontend compilado (public/build/manifest.json)', 'vuelva a copiar la carpeta build del paquete');
    $ok(is_writable(storage_path('logs')) && is_writable(storage_path('framework/views')), 'storage/ con permisos de escritura', 'chmod -R 775 storage bootstrap/cache');
    $ok(is_writable(base_path('bootstrap/cache')), 'bootstrap/cache con permisos de escritura', 'chmod -R 775 bootstrap/cache');

    $this->info('== Base de datos');
    try {
        $info = DB::selectOne('SELECT current_database() AS bd, current_schema() AS esquema, current_user AS usuario');
        $ok(true, "Conexión: {$info->bd} / esquema {$info->esquema} / usuario {$info->usuario}");
    } catch (Throwable $e) {
        $ok(false, 'Conexión a PostgreSQL: '.substr($e->getMessage(), 0, 120), 'revise DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD en .env');
        $this->error("\n$fallas problema(s). Sin base de datos no se puede seguir verificando.");

        return 1;
    }

    $faltan = array_filter(array_merge(Respaldo::TABLAS, ['migrations']), fn ($t) => ! Schema::hasTable($t));
    $ok(! $faltan, 'Tablas ('.count(Respaldo::TABLAS).')'.($faltan ? ' — faltan: '.implode(', ', $faltan) : ''), 'php artisan respaldo:restaurar --reconstruir');

    $funciones = collect(DB::select("SELECT proname FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = current_schema()"))->pluck('proname')->all();
    $faltanF = array_diff(Triggers::FUNCIONES, $funciones);
    $ok(! $faltanF, 'Funciones PL/pgSQL'.($faltanF ? ' — faltan: '.implode(', ', $faltanF) : ''), 'php artisan sistema:reparar');

    $triggers = collect(DB::select("SELECT t.tgname, t.tgenabled FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
        JOIN pg_namespace n ON n.oid = c.relnamespace WHERE NOT t.tgisinternal AND n.nspname = current_schema()"))->keyBy('tgname');
    $faltanT = array_diff(array_keys(Triggers::esperados()), $triggers->keys()->all());
    $desact = $triggers->filter(fn ($t) => $t->tgenabled === 'D')->keys()->all();
    $ok(! $faltanT, 'Triggers ('.count(Triggers::esperados()).')'.($faltanT ? ' — faltan: '.implode(', ', $faltanT) : ''), 'php artisan sistema:reparar');
    $ok(! $desact, 'Triggers habilitados'.($desact ? ' — desactivados: '.implode(', ', $desact) : ''), 'php artisan sistema:reparar');

    if (! $faltan) {
        $this->info('== Datos de seguridad');
        $adm = DB::table('roles')->where('codigo', 'ADM')->first();
        $ok((bool) $adm, 'Rol Administrador (ADM)', 'php artisan sistema:reparar');
        $nPermisos = DB::table('permisos')->count();
        $esperados = collect(SeguridadSeeder::PERMISOS)->flatten()->count();
        $ok($nPermisos >= $esperados, "Permisos: $nPermisos de $esperados", 'php artisan sistema:reparar');
        if ($adm) {
            $ok(DB::table('rol_permiso')->where('rol_id', $adm->id)->count() >= $nPermisos, 'El ADM tiene todos los permisos', 'php artisan sistema:reparar');
        }
        $ok(DB::table('menus')->count() >= count(SeguridadSeeder::MENU), 'Menú dinámico ('.DB::table('menus')->count().' opciones)', 'php artisan sistema:reparar');
        $admin = User::withTrashed()->where('email', 'admin@ficct.edu.bo')->first();
        $ok($admin && ! $admin->trashed() && $admin->estado === 'ACTIVO', 'Usuario admin@ficct.edu.bo activo', 'php artisan sistema:reparar --admin');
        $this->line('     Registros: usuarios='.DB::table('usuarios')->count().', reservas='.DB::table('reservas')->count()
            .', laboratorios='.DB::table('laboratorios')->count().', bitácora='.DB::table('bitacora')->count());
    }

    $this->info('== Respaldos');
    $ultimo = Respaldo::listar()[0] ?? null;
    $ok((bool) $ultimo, $ultimo ? 'Último respaldo: '.basename($ultimo).' ('.round(filesize($ultimo) / 1024).' KB)' : 'No hay respaldos', 'php artisan respaldo:crear');

    $this->newLine();
    $fallas ? $this->error("$fallas problema(s) encontrado(s). Aplique la solución indicada en amarillo.") : $this->info('Todo en orden ✔');

    return $fallas ? 1 : 0;
})->purpose('Diagnostica configuración, base de datos, triggers, permisos y respaldos');

Artisan::command('sistema:reparar {--admin : Restablece la contraseña del admin a Ficct2026} {--permisos : Restaura la matriz de permisos original de todos los roles}', function () {
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/respaldos'] as $d) {
        File::ensureDirectoryExists(storage_path($d));
    }
    $this->callSilently('optimize:clear');
    $this->line('✔ Carpetas de storage y cachés');

    $faltan = array_filter(Respaldo::TABLAS, fn ($t) => ! Schema::hasTable($t));
    if ($faltan) {
        $this->error('Faltan tablas ('.implode(', ', $faltan).'). Ejecute: php artisan respaldo:restaurar --reconstruir');

        return 1;
    }

    Triggers::instalar();
    $this->line('✔ Funciones y triggers reinstalados');

    // Roles y permisos base (solo agrega lo que falte)
    foreach (SeguridadSeeder::ROLES as [$codigo, $nombre, $desc, $sistema]) {
        DB::table('roles')->updateOrInsert(['codigo' => $codigo], ['nombre' => $nombre, 'descripcion' => $desc, 'es_sistema' => $sistema, 'updated_at' => now()]);
    }
    foreach (SeguridadSeeder::PERMISOS as $recurso => $acciones) {
        foreach ($acciones as $accion => $d) {
            DB::table('permisos')->updateOrInsert(['codigo' => "$recurso.$accion"], ['recurso' => $recurso, 'accion' => $accion, 'descripcion' => $d]);
        }
    }
    $roles = DB::table('roles')->pluck('id', 'codigo');
    $permisos = DB::table('permisos')->pluck('id', 'codigo');
    DB::table('rol_permiso')->insertOrIgnore($permisos->map(fn ($id) => ['rol_id' => $roles['ADM'], 'permiso_id' => $id])->values()->all());
    if ($this->option('permisos')) {
        foreach (SeguridadSeeder::ROL_PERMISOS as $rol => $lista) {
            DB::table('rol_permiso')->where('rol_id', $roles[$rol])->delete();
            foreach ($permisos as $codigo => $id) {
                if (in_array($codigo, $lista, true) || in_array(explode('.', $codigo)[0].'.*', $lista, true)) {
                    DB::table('rol_permiso')->insert(['rol_id' => $roles[$rol], 'permiso_id' => $id]);
                }
            }
        }
        $this->line('✔ Matriz de permisos original restaurada');
    }
    $this->line('✔ Roles y permisos base (el Administrador tiene todos)');

    DB::table('menus')->delete();
    foreach (SeguridadSeeder::MENU as $i => [$grupo, $titulo, $ruta, $icono, $permiso]) {
        DB::table('menus')->insert(['grupo' => $grupo, 'titulo' => $titulo, 'ruta' => $ruta, 'icono' => $icono, 'permiso_codigo' => $permiso, 'orden' => ($i + 1) * 10, 'activo' => true]);
    }
    $this->line('✔ Menú dinámico regenerado');

    $admin = User::withTrashed()->where('email', 'admin@ficct.edu.bo')->first();
    if (! $admin) {
        User::create(['codigo' => 'ADM-001', 'nombre' => 'Admin', 'apellido' => 'Sistema', 'email' => 'admin@ficct.edu.bo',
            'password' => 'Ficct2026', 'rol_id' => $roles['ADM'], 'estado' => 'ACTIVO']);
        $this->line('✔ Usuario admin@ficct.edu.bo creado (contraseña Ficct2026)');
    } else {
        $admin->restore();
        $cambios = ['estado' => 'ACTIVO', 'rol_id' => $roles['ADM']];
        if ($this->option('admin')) {
            $cambios['password'] = Hash::make('Ficct2026');
        }
        $admin->forceFill($cambios)->save();
        $this->line('✔ Usuario admin activo con rol Administrador'.($this->option('admin') ? ' (contraseña: Ficct2026)' : ''));
    }

    $this->info('Reparación terminada. Ejecute php artisan sistema:verificar para confirmar.');

    return 0;
})->purpose('Reinstala triggers, funciones, permisos base, menú y usuario admin');

Artisan::command('respaldo:crear {nombre? : Nombre opcional del archivo}', function () {
    [$ruta, $total] = Respaldo::crear($this->argument('nombre') ? $this->argument('nombre').'.json' : null);
    $this->info("✔ Respaldo creado: $ruta ($total registros). Copia en ultimo.json");
})->purpose('Guarda todos los datos del sistema en un archivo JSON');

Artisan::command('respaldo:listar', function () {
    $filas = array_map(fn ($a) => [basename($a), round(filesize($a) / 1024).' KB', date('Y-m-d H:i:s', filemtime($a))], Respaldo::listar());
    $filas ? $this->table(['Archivo', 'Tamaño', 'Fecha'], $filas) : $this->warn('No hay respaldos. Cree uno con: php artisan respaldo:crear');
})->purpose('Lista los respaldos disponibles');

Artisan::command('respaldo:restaurar {archivo=ultimo.json} {--reconstruir : Recrea todas las tablas (migrate:fresh) antes de cargar} {--forzar : No pedir confirmación}', function () {
    $archivo = $this->argument('archivo');
    $ruta = File::exists($archivo) ? $archivo : Respaldo::carpeta().DIRECTORY_SEPARATOR.$archivo;
    if (! File::exists($ruta)) {
        $this->error("No existe el respaldo $ruta. Use respaldo:listar.");

        return 1;
    }
    if (! $this->option('forzar') && ! $this->confirm('Se reemplazarán TODOS los datos actuales por los del respaldo '.basename($ruta).'. ¿Continuar?')) {
        return 1;
    }

    if ($this->option('reconstruir')) {
        if ($motivo = Respaldo::motivoParaNoReconstruir()) {
            $this->error($motivo);

            return 1;
        }
        $this->line('Recreando tablas, funciones y triggers…');
        $this->call('migrate:fresh', ['--force' => true]);
    } else {
        try {
            Respaldo::crear('antes_de_restaurar_'.now()->format('Ymd_His').'.json');
            $this->line('✔ Copia de seguridad del estado actual guardada (antes_de_restaurar_*.json)');
        } catch (Throwable $e) {
            $this->warn('No se pudo respaldar el estado actual (¿faltan tablas?). Si falla, use --reconstruir.');
        }
    }

    $total = Respaldo::restaurar($ruta);
    $this->callSilently('optimize:clear');
    $this->info("✔ Restaurados $total registros desde ".basename($ruta));

    return 0;
})->purpose('Restaura todos los datos desde un respaldo JSON');

/*
|--------------------------------------------------------------------------
| CU7 - Faltas automáticas
|--------------------------------------------------------------------------
|  php artisan asistencia:faltas [fecha]   → registra FALTA (o PERMISO) en los turnos que pasaron 30 min sin entrada
|  Se programa cada 5 minutos. En el servidor basta un cron:  * * * * * php artisan schedule:run
|  Si el hosting no tiene cron, igual se registran al abrir la pantalla de Asistencia.
*/
Artisan::command('asistencia:faltas {fecha? : AAAA-MM-DD (por defecto hoy)}', function () {
    $fecha = $this->argument('fecha') ? Carbon::parse($this->argument('fecha'))->startOfDay() : Carbon::today();
    $r = ControlAsistencia::registrarFaltas($fecha);
    $this->info("{$fecha->format('d/m/Y')}: {$r['faltas']} falta(s) y {$r['permisos']} permiso(s) registrados.");
})->purpose('Registra las faltas de los auxiliares que no marcaron entrada');

Schedule::command('asistencia:faltas')->everyFiveMinutes()->withoutOverlapping();
