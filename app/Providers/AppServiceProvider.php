<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        RateLimiter::for('visitas', fn (Request $r) => Limit::perMinute(120)->by('visitas|'.$r->ip()));
    }
}
