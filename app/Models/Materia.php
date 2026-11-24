<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Materia extends Model
{
    protected $table = 'materias';

    protected $fillable = ['sigla', 'nombre'];

    protected function casts(): array
    {
        return [];
    }
}
