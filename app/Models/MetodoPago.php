<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MetodoPago extends Model
{
    use SoftDeletes;

    protected $table = 'metodos_pago';

    protected $fillable = ['usuario_id', 'tipo', 'alias', 'titular', 'marca', 'ultimos4', 'vencimiento', 'token', 'predeterminado'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['predeterminado' => 'boolean'];
    }

    public function usuario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
