<?php

use Illuminate\Support\Facades\Route;

// El frontend es una SPA en React: cualquier ruta que no sea /api devuelve la misma vista
// y React Router decide qué pantalla mostrar.
Route::view('/{any?}', 'app')->where('any', '^(?!api).*$');
