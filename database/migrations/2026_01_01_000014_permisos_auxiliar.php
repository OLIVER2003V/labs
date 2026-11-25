<?php

use App\Support\Triggers;
use Database\Seeders\ImportarSislabSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU7 - Permisos (licencias) de los auxiliares: la entidad PERMISO del proyecto 1.
 * Se llama "permisos_auxiliar" porque "permisos" ya es la tabla de la matriz de acceso (RBAC).
 *
 * Un permiso es por AUXILIAR y FECHA: justifica todos sus turnos de ese día.
 * Si ya tenía FALTA ese día, pasa a PERMISO (justificación retroactiva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos_auxiliar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auxiliar_id')->constrained('usuarios');
            $table->date('fecha');
            $table->string('motivo', 200);
            $table->foreignId('registrado_por')->constrained('usuarios');
            $table->timestamps();
            $table->unique(['auxiliar_id', 'fecha']); // un permiso por auxiliar y día
        });

        Triggers::instalar(); // auditoría de permisos_auxiliar

        if (ImportarSislabSeeder::disponible() && DB::table('usuarios')->exists()) {
            ImportarSislabSeeder::importarPermisos();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_auxiliar');
    }
};
