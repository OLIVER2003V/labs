<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cuota extends Model
{
    protected $table = 'cuotas';

    protected $fillable = ['plan_id', 'numero', 'monto', 'fecha_vencimiento', 'estado', 'pagado_en'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'fecha_vencimiento' => 'date:Y-m-d', 'pagado_en' => 'datetime'];
    }

    public function plan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlanPago::class, 'plan_id');
    }
}
