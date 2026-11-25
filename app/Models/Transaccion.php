<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cada intento de pago con Stripe: PENDIENTE → APROBADA (o EXPIRADA / RECHAZADA).
 * referencia_externa = id de la sesión de Stripe (cs_...) · url_pago = página de pago de Stripe · autorizacion = id del pago (pi_...)
 */
class Transaccion extends Model
{
    protected $table = 'transacciones';

    protected $fillable = ['codigo', 'usuario_id', 'cuota_id', 'concepto_id', 'multa_id', 'descripcion', 'monto', 'estado', 'autorizacion',
        'mensaje', 'pasarela', 'referencia_externa', 'url_pago', 'expira_en'];

    protected $hidden = ['url_pago']; // solo se entrega al dueño mientras está pendiente (ver PagoStripeController)

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'expira_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function cuota(): BelongsTo
    {
        return $this->belongsTo(Cuota::class, 'cuota_id');
    }

    public function concepto(): BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }

    public function multa(): BelongsTo
    {
        return $this->belongsTo(Multa::class, 'multa_id');
    }
}
