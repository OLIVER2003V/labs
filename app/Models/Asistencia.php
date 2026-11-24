<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = ['turno_id', 'auxiliar_id', 'fecha', 'hora_entrada', 'hora_salida', 'estado', 'observacion'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function turno(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }
    public function auxiliar(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'auxiliar_id');
    }
}
