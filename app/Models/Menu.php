<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Menu extends Model
{
    protected $table = 'menus';

    protected $fillable = ['grupo', 'titulo', 'ruta', 'icono', 'permiso_codigo', 'orden', 'activo'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
