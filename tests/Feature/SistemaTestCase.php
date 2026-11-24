<?php

namespace Tests\Feature;

use App\Models\Semestre;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Base de las pruebas: base PostgreSQL de prueba (db_grupo08sa_test) con migraciones + datos de demostración.
 * Cada prueba corre dentro de una transacción que se deshace al final.
 */
abstract class SistemaTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        // El semestre activo cubre los próximos meses, para que las pruebas no dependan de la fecha actual
        Semestre::where('estado', 'ACTIVO')->update(['fecha_inicio' => now()->subMonth(), 'fecha_fin' => now()->addMonths(3)]);
    }

    protected function como(string $email): User
    {
        $u = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($u);

        return $u;
    }

    /** Un martes futuro sin feriados ni bloqueos */
    protected function martesLibre(): string
    {
        $d = Carbon::today()->addDays(7)->next(Carbon::TUESDAY);
        while (\DB::table('fechas_bloqueadas')->whereDate('fecha', $d)->exists()) {
            $d->addWeek();
        }

        return $d->toDateString();
    }
}
