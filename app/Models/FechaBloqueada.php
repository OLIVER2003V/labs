<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FechaBloqueada extends Model
{
    protected $table = 'fechas_bloqueadas';

    protected $fillable = ['semestre_id', 'fecha', 'hora_inicio', 'hora_fin', 'tipo', 'motivo', 'laboratorio_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function semestre(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Semestre::class, 'semestre_id');
    }
    public function laboratorio(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Laboratorio::class, 'laboratorio_id');
    }

    /** true si el bloqueo es de día completo (sin horario) */
    public function esDiaCompleto(): bool
    {
        return $this->hora_inicio === null;
    }

    /**
     * ¿Este bloqueo se cruza con la franja [inicio, fin]? (franja null = todo el día)
     * Un bloqueo de día completo se cruza con todo.
     */
    public function seCruzaCon(?string $inicio, ?string $fin): bool
    {
        if ($this->esDiaCompleto() || $inicio === null) {
            return true;
        }
        $hms = fn ($h) => strlen($h) === 5 ? "$h:00" : $h;

        return $this->hora_inicio < $hms($fin) && $hms($inicio) < $this->hora_fin;
    }

    /** "todo el día" o "08:00–12:00" */
    public function horario(): string
    {
        return $this->esDiaCompleto() ? 'todo el día' : substr($this->hora_inicio, 0, 5).'–'.substr($this->hora_fin, 0, 5);
    }
}
