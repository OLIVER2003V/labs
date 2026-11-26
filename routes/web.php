<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// CU6 - Fotos subidas de objetos encontrados (storage/app/private/objetos). Nombre aleatorio de 32 caracteres:
// se pueden mostrar en <img> sin token, pero no se pueden adivinar.
Route::get('/media/objetos/{archivo}', function (string $archivo) {
    $ruta = Storage::disk('local')->path(App\Support\ImagenObjeto::CARPETA_SUBIDAS.'/'.$archivo);
    abort_unless(is_file($ruta), 404);

    return response()->file($ruta, ['Cache-Control' => 'public, max-age=604800']);
})->where('archivo', '[A-Za-z0-9]{32}\.(jpg|png|webp)');

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
