<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// El frontend es una SPA en React: cualquier ruta que no sea /api devuelve la misma vista
// y React Router decide qué pantalla mostrar.
Route::get('/{any?}', function () {
    $demo = [];
    if (config('app.demo_login')) {
        // Primer usuario activo de cada rol (con APP_DEMO_LOGIN=true en .env)
        $demo = DB::table('roles')->orderBy('id')->get()->map(function ($r) {
            $email = DB::table('usuarios')->where('rol_id', $r->id)->where('estado', 'ACTIVO')->whereNull('deleted_at')->orderBy('id')->value('email');

            return $email ? ['rol' => $r->nombre, 'email' => $email] : null;
        })->filter()->values()->all();
    }

    return view('app', ['demo' => $demo]);
})->where('any', '^(?!api).*$');
