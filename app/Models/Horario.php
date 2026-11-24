<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Horario extends Model
{
    protected $table = 'horarios';

    protected $fillable = ['semestre_id', 'laboratorio_id', 'materia_id', 'docente_id', 'grupo', 'dia_semana', 'hora_inicio', 'hora_fin'];

    protected function casts(): array
    {
        return ['dia_semana' => 'integer'];
    }

    public function semestre(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Semestre::class, 'semestre_id');
    }
    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    public function materia(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Materia::class, 'materia_id');
    }
    public function docente(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'docente_id');
    }
}
