<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU9 - Los pagos pasan a Stripe Checkout (tarjeta) como única pasarela.
 *  - Se elimina el QR simulado/PagoFácil y las "tarjetas guardadas" simuladas (tabla metodos_pago):
 *    con Stripe los datos de la tarjeta los guarda Stripe, nunca nuestro sistema.
 *  - transacciones: qr_contenido → url_pago (la página de pago de Stripe); autorizacion admite el id del pago de Stripe (pi_...).
 *  - Las transacciones antiguas se conservan como historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transacciones')->where('estado', 'PENDIENTE')->update(['estado' => 'EXPIRADA', 'mensaje' => 'Pasarela anterior retirada.']);

        Schema::table('transacciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('metodo_pago_id');
        });
        Schema::table('transacciones', function (Blueprint $table) {
            $table->renameColumn('qr_contenido', 'url_pago');
            $table->string('autorizacion', 40)->nullable()->change();
            $table->string('pasarela', 20)->default('stripe')->change();
        });
        Schema::dropIfExists('metodos_pago');

        DB::table('menus')->where('ruta', '/pagos/metodos')->delete();
    }

    public function down(): void
    {
        Schema::create('metodos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->string('alias', 50);
            $table->string('titular', 100);
            $table->string('marca', 20)->nullable();
            $table->string('ultimos4', 4)->nullable();
            $table->string('vencimiento', 5)->nullable();
            $table->string('token', 64);
            $table->boolean('predeterminado')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::table('transacciones', function (Blueprint $table) {
            $table->renameColumn('url_pago', 'qr_contenido');
            $table->string('pasarela', 20)->default('tarjeta')->change();
            $table->foreignId('metodo_pago_id')->nullable()->constrained('metodos_pago');
        });
    }
};
