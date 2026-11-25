<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CU9 - Multa por atraso, falta del auxiliar o reserva no utilizada */
class Multa extends Model
{
    protected $table = 'multas';

    protected $fillable = ['usuario_id', 'tipo', 'concepto_id', 'monto', 'motivo', 'fecha', 'vence_en', 'estado',
        'asistencia_id', 'reserva_id', 'pagada_en', 'anulada_por', 'observacion'];

    protected $appends = ['vencida'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'vence_en' => 'date:Y-m-d', 'pagada_en' => 'datetime', 'monto' => 'decimal:2'];
    }

    /** Pendiente y con el plazo pasado (esto es lo que bloquea reservas y permutas) */
    public function getVencidaAttribute(): bool
    {
        return $this->estado === 'PENDIENTE' && $this->vence_en->lt(today());
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function anulador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por');
    }

    public function asistencia(): BelongsTo
    {
        return $this->belongsTo(Asistencia::class, 'asistencia_id');
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'reserva_id');
    }
}
