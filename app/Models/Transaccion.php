<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaccion extends Model
{
    protected $table = 'transacciones';

    protected $fillable = ['codigo', 'usuario_id', 'metodo_pago_id', 'cuota_id', 'concepto_id', 'multa_id', 'descripcion', 'monto', 'estado', 'autorizacion', 'mensaje', 'pasarela', 'referencia_externa', 'qr_contenido', 'expira_en'];

    protected function casts(): array
    {
        return ['monto' => 'decimal:2', 'expira_en' => 'datetime'];
    }

    public function usuario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
    public function metodo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(MetodoPago::class, 'metodo_pago_id');
    }
    public function cuota(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Cuota::class, 'cuota_id');
    }
    public function concepto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ConceptoPago::class, 'concepto_id');
    }
}
