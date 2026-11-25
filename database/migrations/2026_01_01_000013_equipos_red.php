<?php

use App\Support\Triggers;
use Database\Seeders\ImportarSislabSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU6 - Configuración de red de cada equipo (EQUIPO_RED del proyecto 1).
 * Relación 1 a 1 con equipos (equipo_id único, se borra en cascada con el equipo).
 * Regla 2 del CU6: la IP no se puede repetir en la red de la facultad (índice único).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipos_red', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->unique()->constrained('equipos')->cascadeOnDelete();
            $table->string('ip', 15)->unique();
            $table->string('mascara', 15);
            $table->string('gateway', 15);
            $table->string('dns1', 15);
            $table->string('dns2', 15)->nullable();
            $table->string('proxy', 60)->nullable();      // ej: 172.20.172.254:3128
            $table->string('grupo_red', 20)->nullable();  // ej: LAB41
            $table->timestamps();
        });

        Triggers::instalar(); // auditoría de equipos_red

        // Si la base ya tiene los datos reales del proyecto 1, se trae también la configuración de red
        if (ImportarSislabSeeder::disponible() && DB::table('equipos')->exists()) {
            ImportarSislabSeeder::importarRed();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('equipos_red');
    }
};
