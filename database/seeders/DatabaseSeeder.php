<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan migrate:fresh --seed   → reconstruye toda la base con datos de demostración.
     */
    public function run(): void
    {
        // ORIGEN_DATOS: auto (si existe el esquema sislab usa los datos reales) | sislab | demo
        $origen = env('ORIGEN_DATOS', 'auto');
        if ($origen === 'sislab' || ($origen === 'auto' && ImportarSislabSeeder::disponible())) {
            $this->call([SeguridadSeeder::class, ImportarSislabSeeder::class]);
            DatosSeeder::conceptosDePago();
        } else {
            $this->call([SeguridadSeeder::class, DatosSeeder::class]);
        }

        // Los triggers registraron todo el sembrado como "cambios"; empezamos la auditoría limpia.
        DB::table('auditoria')->truncate();
    }
}
