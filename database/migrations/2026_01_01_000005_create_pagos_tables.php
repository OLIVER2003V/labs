<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de pagos electrónicos (pasarela simulada).
 *  - conceptos_pago: catálogo de cobros (alquiler de laboratorio, multas, certificados...)
 *  - metodos_pago:   tarjetas / QR / billetera registradas por cada usuario
 *  - planes_pago:    deuda dividida en cuotas asignada a un usuario
 *  - cuotas:         cada cuota del plan
 *  - transacciones:  cada intento de pago (único o de cuota), aprobado o rechazado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos_pago', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->decimal('monto', 10, 2);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE conceptos_pago ADD CONSTRAINT ck_con_monto CHECK (monto > 0)");

        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 10);            // TARJETA, QR, BILLETERA
            $table->string('alias', 50);
            $table->string('titular', 100);
            $table->string('marca', 20)->nullable();      // VISA, MASTERCARD...
            $table->string('ultimos4', 4)->nullable();    // nunca se guarda el número completo
            $table->string('vencimiento', 5)->nullable(); // MM/AA
            $table->string('token', 64);                  // token simulado de la pasarela
            $table->boolean('predeterminado')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE metodos_pago ADD CONSTRAINT ck_met_tipo CHECK (tipo IN ('TARJETA','QR','BILLETERA'))");

        Schema::create('planes_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('concepto_id')->nullable()->constrained('conceptos_pago');
            $table->foreignId('reserva_id')->nullable()->constrained('reservas');
            $table->string('descripcion', 200);
            $table->decimal('monto_total', 10, 2);
            $table->smallInteger('nro_cuotas');
            $table->string('estado', 10)->default('VIGENTE');
            $table->foreignId('creado_por')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
        DB::statement("ALTER TABLE planes_pago ADD CONSTRAINT ck_pla_monto CHECK (monto_total > 0)");
        DB::statement("ALTER TABLE planes_pago ADD CONSTRAINT ck_pla_cuotas CHECK (nro_cuotas BETWEEN 1 AND 24)");
        DB::statement("ALTER TABLE planes_pago ADD CONSTRAINT ck_pla_estado CHECK (estado IN ('VIGENTE','PAGADO','ANULADO'))");

        Schema::create('cuotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('planes_pago')->cascadeOnDelete();
            $table->smallInteger('numero');
            $table->decimal('monto', 10, 2);
            $table->date('fecha_vencimiento');
            $table->string('estado', 10)->default('PENDIENTE');
            $table->timestamp('pagado_en')->nullable();
            $table->timestamps();
            $table->unique(['plan_id', 'numero']);
        });
        DB::statement("ALTER TABLE cuotas ADD CONSTRAINT ck_cuo_estado CHECK (estado IN ('PENDIENTE','PAGADA'))");

        Schema::create('transacciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();     // TRX-000001
            $table->foreignId('usuario_id')->constrained('usuarios');
            $table->foreignId('metodo_pago_id')->nullable()->constrained('metodos_pago');
            $table->foreignId('cuota_id')->nullable()->constrained('cuotas');        // pago de cuota
            $table->foreignId('concepto_id')->nullable()->constrained('conceptos_pago'); // pago único
            $table->string('descripcion', 200);
            $table->decimal('monto', 10, 2);
            $table->string('estado', 10);
            $table->string('autorizacion', 20)->nullable();
            $table->string('mensaje', 200)->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE transacciones ADD CONSTRAINT ck_trx_estado CHECK (estado IN ('APROBADA','RECHAZADA'))");
        DB::statement("ALTER TABLE transacciones ADD CONSTRAINT ck_trx_monto CHECK (monto > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('transacciones');
        Schema::dropIfExists('cuotas');
        Schema::dropIfExists('planes_pago');
        Schema::dropIfExists('metodos_pago');
        Schema::dropIfExists('conceptos_pago');
    }
};
