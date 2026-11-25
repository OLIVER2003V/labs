<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Limpieza de los pagos de PRUEBA hechos con las pasarelas retiradas (QR simulado, PagoFácil, tarjetas simuladas):
 * no movieron dinero real. Hoy el único cobro es con Stripe.
 * Después se borran los conceptos de cobro descartados (alquiler, daño, devolución tardía) que ya no tienen pagos.
 */
return new class extends Migration
{
    const PASARELAS_RETIRADAS = ['simulada', 'pagofacil', 'tarjeta'];
    const CONCEPTOS_DESCARTADOS = ['ALQ-LAB-HORA', 'MULTA-DANO', 'MULTA-RETRASO'];

    public function up(): void
    {
        $prueba = DB::table('transacciones')->whereIn('pasarela', self::PASARELAS_RETIRADAS)->whereNull('multa_id');
        $cuotas = (clone $prueba)->whereNotNull('cuota_id')->pluck('cuota_id');
        // Si alguna cuota se "pagó" con una pasarela de prueba, vuelve a quedar pendiente
        DB::table('cuotas')->whereIn('id', $cuotas)->update(['estado' => 'PENDIENTE', 'pagado_en' => null, 'updated_at' => now()]);
        DB::table('planes_pago')->whereIn('id', DB::table('cuotas')->whereIn('id', $cuotas)->select('plan_id'))->where('estado', 'PAGADO')
            ->update(['estado' => 'VIGENTE', 'updated_at' => now()]);
        $prueba->delete();

        foreach (DB::table('conceptos_pago')->whereIn('codigo', self::CONCEPTOS_DESCARTADOS)->pluck('id') as $id) {
            $usado = DB::table('transacciones')->where('concepto_id', $id)->exists() || DB::table('planes_pago')->where('concepto_id', $id)->exists();
            if (! $usado) {
                DB::table('conceptos_pago')->where('id', $id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Los pagos de prueba no se recrean (eran simulados).
    }
};
