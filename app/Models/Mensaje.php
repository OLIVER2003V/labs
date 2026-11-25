<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CU5 - Mensaje del chat interno (privado entre dos usuarios) */
class Mensaje extends Model
{
    protected $table = 'mensajes';

    const UPDATED_AT = null; // un mensaje no se edita: solo tiene created_at

    protected $fillable = ['remitente_id', 'destinatario_id', 'contenido', 'leido_en'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime', 'created_at' => 'datetime'];
    }

    public function remitente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remitente_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }
}
