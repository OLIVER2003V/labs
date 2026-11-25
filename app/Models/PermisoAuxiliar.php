<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CU7 - Permiso (licencia) de un auxiliar para un día: justifica todos sus turnos de esa fecha */
class PermisoAuxiliar extends Model
{
    protected $table = 'permisos_auxiliar';

    protected $fillable = ['auxiliar_id', 'fecha', 'motivo', 'registrado_por'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function auxiliar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auxiliar_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
