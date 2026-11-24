<?php

namespace App\Http\Controllers;

use App\Models\ConceptoPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pagos - Catálogo de conceptos de cobro */
class ConceptoPagoController extends CrudController
{
    protected string $modelo = ConceptoPago::class;
    protected array $buscarEn = ['codigo', 'nombre'];
    protected array $filtros = ['activo'];
    protected string $ordenarPor = 'nombre';
    protected string $direccion = 'asc';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'codigo' => ['required', 'regex:/^[A-Za-z0-9-]{3,20}$/', Rule::unique('conceptos_pago', 'codigo')->ignore($registro?->id)],
            'nombre' => ['required', 'string', 'min:3', 'max:100'],
            'monto' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'activo' => ['required', 'boolean'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        $datos['codigo'] = strtoupper($datos['codigo']);

        return $datos;
    }
}
