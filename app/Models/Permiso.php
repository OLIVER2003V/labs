<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'permisos';

    protected $fillable = ['codigo', 'recurso', 'accion', 'descripcion'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [];
    }
}
