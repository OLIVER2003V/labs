<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanPago extends Model
{
    protected $table = 'planes_pago';

    protected $fillable = ['usuario_id', 'concepto_id', 'reserva_id', 'descripcion', 'monto_total', 'nro_cuotas', 'estado', 'creado_por'];

    protected function casts(): array
    {
        return ['monto_total' => 'decimal:2', 'nro_cuotas' => 'integer'];
    }

    public function usuario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
    public function concepto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }
    public function reserva(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }
    public function cuotas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Cuota::class, 'plan_id');
    }
}
