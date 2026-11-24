<?php

use App\Support\Triggers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de accesos (app), contador de visitas y auditoría por TRIGGERS (base de datos).
 *
 * - bitacora:       la llena Laravel (logins OK/fallidos, IP, recurso accedido, método HTTP).
 * - visitas_pagina: contador persistente por página; se incrementa con la función registrar_visita().
 * - auditoria:      la llenan los triggers. Registra INSERT/UPDATE/DELETE aunque se hagan
 *                   directamente por SQL (psql/pgAdmin), guardando el registro antes y después.
 *
 * El SQL de las funciones y triggers está en app/Support/Triggers.php
 * (así también se pueden reinstalar con "php artisan sistema:reparar").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacora', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $table->string('email', 100)->nullable();
            $table->string('evento', 20);   // LOGIN_OK, LOGIN_FALLIDO, LOGOUT, ACCESO, DENEGADO
            $table->string('metodo', 7)->nullable();
            $table->string('recurso', 60)->nullable();
            $table->string('ruta', 200)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->smallInteger('codigo_http')->nullable();
            $table->string('detalle', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('created_at');
            $table->index('evento');
            $table->index('recurso');
        });

        Schema::create('visitas_pagina', function (Blueprint $table) {
            $table->id();
            $table->string('ruta', 150)->unique();
            $table->bigInteger('contador')->default(0);
            $table->timestamp('actualizado_en')->useCurrent();
        });

        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('tabla', 60);
            $table->string('operacion', 10);
            $table->string('registro_id', 40)->nullable();
            $table->jsonb('datos_antes')->nullable();
            $table->jsonb('datos_despues')->nullable();
            $table->string('usuario_db', 60);                 // usuario de PostgreSQL que hizo el cambio
            $table->bigInteger('usuario_app_id')->nullable(); // usuario del sistema (si vino desde la app)
            $table->timestamp('fecha')->useCurrent();
            $table->index(['tabla', 'fecha']);
        });

        Triggers::instalar();
    }

    public function down(): void
    {
        Triggers::desinstalar();
        Schema::dropIfExists('auditoria');
        Schema::dropIfExists('visitas_pagina');
        Schema::dropIfExists('bitacora');
    }
};
