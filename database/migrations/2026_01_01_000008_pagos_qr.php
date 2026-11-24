<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pagos por QR (PagoFácil u otra pasarela): la transacción nace PENDIENTE con un QR
 * y pasa a APROBADA cuando la pasarela confirma el pago (callback o consulta de estado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transacciones', function (Blueprint $table) {
            $table->string('pasarela', 20)->default('tarjeta')->after('metodo_pago_id'); // tarjeta | simulada | pagofacil
            $table->string('referencia_externa', 100)->nullable()->after('pasarela');     // id de la transacción en la pasarela
            $table->text('qr_contenido')->nullable();                                    // texto del QR o imagen base64
            $table->timestamp('expira_en')->nullable();
            $table->index(['pasarela', 'referencia_externa']);
        });
        DB::statement('ALTER TABLE transacciones DROP CONSTRAINT ck_trx_estado');
        DB::statement("ALTER TABLE transacciones ADD CONSTRAINT ck_trx_estado CHECK (estado IN ('PENDIENTE','APROBADA','RECHAZADA','EXPIRADA'))");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM transacciones WHERE estado IN ('PENDIENTE','EXPIRADA')");
        DB::statement('ALTER TABLE transacciones DROP CONSTRAINT ck_trx_estado');
        DB::statement("ALTER TABLE transacciones ADD CONSTRAINT ck_trx_estado CHECK (estado IN ('APROBADA','RECHAZADA'))");
        Schema::table('transacciones', fn (Blueprint $t) => $t->dropColumn(['pasarela', 'referencia_externa', 'qr_contenido', 'expira_en']));
    }
};
