<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FechaBloqueada extends Model
{
    protected $table = 'fechas_bloqueadas';

    protected $fillable = ['semestre_id', 'fecha', 'tipo', 'motivo', 'laboratorio_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function semestre(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Semestre::class, 'semestre_id');
    }
    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
}
