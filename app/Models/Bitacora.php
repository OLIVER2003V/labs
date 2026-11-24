<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bitacora extends Model
{
    protected $table = 'bitacora';

    protected $fillable = ['usuario_id', 'email', 'evento', 'metodo', 'recurso', 'ruta', 'ip', 'user_agent', 'codigo_http', 'detalle'];

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function usuario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
