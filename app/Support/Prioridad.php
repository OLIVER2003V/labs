<?php

namespace App\Support;

/**
 * CU4 - Jerarquía de uso de los laboratorios (1 = más importante):
 *
 *   1. Hackathon, CUP, PSA
 *   2. Examen
 *   3. Capacitación externa, Conferencia
 *   4. CLASES (horario base)
 *   5. Prácticas de auxiliares de cátedra
 *
 * Una reserva puede DESPLAZAR lo que está debajo de ella (una clase, o una reserva de menor prioridad), pero
 * para eso debe solicitarse con al menos DIAS_ANTICIPACION días (así se avisa a tiempo al docente o al solicitante).
 * Si el horario está libre, se puede reservar sin anticipación. Un feriado o bloqueo nunca se desplaza.
 */
class Prioridad
{
    /** código => [nombre, nivel] */
    const TIPOS = [
        'HACKATHON' => ['Hackathon', 1],
        'CUP' => ['CUP (curso preuniversitario)', 1],
        'PSA' => ['PSA (prueba de suficiencia académica)', 1],
        'EXAMEN' => ['Examen', 2],
        'CAPACITACION' => ['Capacitación externa', 3],
        'CONFERENCIA' => ['Conferencia', 3],
        'PRACTICA' => ['Práctica de auxiliar de cátedra', 5],
    ];

    /** Nivel de las clases del horario base */
    const CLASE = 4;

    /** Días de anticipación para desplazar una clase o una reserva de menor prioridad */
    const DIAS_ANTICIPACION = 4;

    public static function codigos(): array
    {
        return array_keys(self::TIPOS);
    }

    public static function nivel(string $tipo): int
    {
        return self::TIPOS[$tipo][1] ?? 9;
    }

    public static function nombre(string $tipo): string
    {
        return self::TIPOS[$tipo][0] ?? $tipo;
    }

    /** ¿Una reserva de este tipo puede desplazar algo de ese nivel? (solo lo que está estrictamente debajo) */
    public static function desplaza(string $tipo, int $nivelExistente): bool
    {
        return self::nivel($tipo) < $nivelExistente;
    }

    /** Tipos que están por encima de las clases (pueden ocupar un laboratorio en horario de clase) */
    public static function sobreClases(): array
    {
        return array_keys(array_filter(self::TIPOS, fn ($t) => $t[1] < self::CLASE));
    }
}
