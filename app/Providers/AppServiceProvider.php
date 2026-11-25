<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Login: máximo 5 intentos por minuto por (correo + IP) → frena ataques de fuerza bruta.
        // Cada limitador tiene su propia clave para no mezclar contadores entre rutas.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)
            ->by('login|'.strtolower((string) $r->input('email')).'|'.$r->ip()));

        // Registro público: máximo 3 solicitudes por hora por IP (evita cuentas basura)
        RateLimiter::for('registro', fn (Request $r) => Limit::perHour(3)->by('registro|'.$r->ip()));

        RateLimiter::for('visitas', fn (Request $r) => Limit::perMinute(120)->by('visitas|'.$r->ip()));

        // "php artisan serve" en PowerShell (Windows): Laravel solo pasa al servidor la variable "SYSTEMROOT"
        // en mayúsculas, pero PowerShell la llama "SystemRoot". Sin ella PHP no inicia curl.
        ServeCommand::$passthroughVariables[] = 'SystemRoot';

        // CU10: lo que se cambia con los comandos del propio sistema (php artisan migrate, sistema:reparar, respaldo:restaurar,
        // asistencia:faltas…) queda en la auditoría a nombre del Encargado principal (el administrador). Así la alerta de "cambios directos en la BD"
        // solo muestra lo hecho POR FUERA del sistema (SQL directo, pgAdmin), que es lo que puede ser sabotaje.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $e) {
                try {
                    $admin = $e->connection->selectOne("SELECT u.id FROM usuarios u JOIN roles r ON r.id = u.rol_id
                        WHERE r.codigo = 'ENC' AND u.deleted_at IS NULL ORDER BY (LOWER(u.email) = ?) DESC, u.id LIMIT 1",
                        [strtolower((string) config('app.encargado_principal'))]);
                    if ($admin) {
                        $e->connection->select("SELECT set_config('app.usuario_id', ?, false)", [(string) $admin->id]);
                    }
                } catch (Throwable) {
                    // base recién creada (todavía sin tablas): no hay a quién atribuir
                }
            });
        }
    }
}
