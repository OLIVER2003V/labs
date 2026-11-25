<?php

namespace App\Providers;

use App\Services\Pagos\PagoFacilQr;
use App\Services\Pagos\PasarelaQr;
use App\Services\Pagos\SimuladaQr;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pasarela de cobro por QR según .env (PAGOS_PASARELA=simulada | pagofacil)
        $this->app->bind(PasarelaQr::class, fn () => match (config('pagos.pasarela')) {
            'pagofacil' => new PagoFacilQr,
            default => new SimuladaQr,
        });
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
    }
}
