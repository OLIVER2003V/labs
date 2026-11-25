<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema (tabla usuarios). Su rol determina qué permisos tiene.
 */
class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'usuarios';

    protected $fillable = [
        'codigo', 'nombre', 'apellido', 'email', 'telefono', 'carrera', 'detalle_registro', 'aprobado_por', 'aprobado_en', 'password', 'rol_id', 'estado', 'preferencias',
    ];

    protected $hidden = ['password'];

    protected $appends = ['nombre_completo'];

    /** Caché en memoria de los códigos de permiso del usuario durante la petición */
    private ?array $cachePermisos = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'preferencias' => 'array',
            'ultimo_acceso' => 'datetime',
            'aprobado_en' => 'datetime',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    public function getNombreCompletoAttribute(): string
    {
        return trim("{$this->nombre} {$this->apellido}");
    }

    /** Lista de códigos de permiso del rol del usuario, ej: ['reservas.ver', 'reservas.crear'] */
    public function permisos(): array
    {
        if ($this->cachePermisos === null) {
            $this->cachePermisos = Permiso::query()
                ->join('rol_permiso', 'rol_permiso.permiso_id', '=', 'permisos.id')
                ->where('rol_permiso.rol_id', $this->rol_id)
                ->pluck('permisos.codigo')
                ->all();
        }

        return $this->cachePermisos;
    }

    public function tienePermiso(string $codigo): bool
    {
        return in_array($codigo, $this->permisos(), true);
    }
}
