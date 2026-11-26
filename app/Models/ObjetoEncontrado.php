<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ObjetoEncontrado extends Model
{
    protected $table = 'objetos_encontrados';

    protected $fillable = ['laboratorio_id', 'descripcion', 'imagen', 'fecha_hallazgo', 'registrado_por', 'estado', 'entregado_a', 'ci_receptor', 'fecha_entrega'];

    /** URL completa de la imagen (la pantalla la muestra en la lista y en las tarjetas) */
    protected $appends = ['imagen_url', 'imagen_propia'];

    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen ? url($this->imagen) : null;
    }

    /** true = foto subida del objeto real; false = imagen de referencia según el tipo de objeto */
    public function getImagenPropiaAttribute(): bool
    {
        return \App\Support\ImagenObjeto::esSubida($this->imagen);
    }

    protected function casts(): array
    {
        return ['fecha_hallazgo' => 'date:Y-m-d', 'fecha_entrega' => 'datetime'];
    }

    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }
    public function registrador(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
