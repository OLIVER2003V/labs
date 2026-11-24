<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Tamaño de página seguro: entre 1 y 100 (evita ?per_page=0 o ?per_page=100000) */
    protected function porPagina(Request $request, int $predeterminado = 10): int
    {
        return min(max((int) $request->query('per_page', $predeterminado), 1), 100);
    }
}
