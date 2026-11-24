<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptoPago extends Model
{
    protected $table = 'conceptos_pago';

    protected $fillable = ['codigo', 'nombre', 'monto', 'activo'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'activo' => 'boolean'];
    }
}
