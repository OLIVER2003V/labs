<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpieza final: se quita lo que quedó de ideas descartadas.
 *  - Nadie paga alquiler de laboratorio → se elimina laboratorios.tarifa_hora y el concepto ALQ-LAB-HORA.
 *  - Multas: solo atraso, falta y reserva no utilizada (CU9) → se retiran MULTA-DANO y MULTA-RETRASO.
 *    Si un concepto ya tiene pagos o planes, no se borra (es historial): queda desactivado.
 *  - Sesiones vencidas: se borran (desde ahora también se limpian solas al iniciar sesión).
 */
return new class extends Migration
{
    const OBSOLETOS = ['ALQ-LAB-HORA', 'MULTA-DANO', 'MULTA-RETRASO'];

    public function up(): void
    {
        foreach (DB::table('conceptos_pago')->whereIn('codigo', self::OBSOLETOS)->pluck('id') as $id) {
            $usado = DB::table('transacciones')->where('concepto_id', $id)->exists() || DB::table('planes_pago')->where('concepto_id', $id)->exists()
                || DB::table('multas')->where('concepto_id', $id)->exists();
            $usado ? DB::table('conceptos_pago')->where('id', $id)->update(['activo' => false, 'updated_at' => now()])
                : DB::table('conceptos_pago')->where('id', $id)->delete();
        }

        if (Schema::hasColumn('laboratorios', 'tarifa_hora')) {
            Schema::table('laboratorios', fn (Blueprint $t) => $t->dropColumn('tarifa_hora'));
        }

        DB::table('personal_access_tokens')->where('expires_at', '<', now())->delete();
    }

    public function down(): void
    {
        Schema::table('laboratorios', fn (Blueprint $t) => $t->decimal('tarifa_hora', 10, 2)->default(0));
    }
};
