<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes para recibir los datos reales del proyecto 1 (esquema sislab):
 *  - equipos.activo_fijo: código de activo fijo de la universidad (ej: U147124117399)
 *  - reservas.tipo admite DEFENSA (defensas de proyecto)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipos', function (Blueprint $table) {
            $table->string('activo_fijo', 30)->nullable()->after('codigo');
        });
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT ck_res_tipo');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_tipo CHECK (tipo IN ('EXAMEN','RECUPERACION','PRACTICA','EVENTO','DEFENSA'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE reservas DROP CONSTRAINT ck_res_tipo');
        DB::statement("ALTER TABLE reservas ADD CONSTRAINT ck_res_tipo CHECK (tipo IN ('EXAMEN','RECUPERACION','PRACTICA','EVENTO'))");
        Schema::table('equipos', fn (Blueprint $t) => $t->dropColumn('activo_fijo'));
    }
};
