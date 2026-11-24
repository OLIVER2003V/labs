<?php

namespace App\Services\Pagos;

use App\Models\Transaccion;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Contrato de una pasarela de cobro por QR. Cambiar de pasarela = cambiar PAGOS_PASARELA en .env.
 * Implementaciones: SimuladaQr (pruebas) y PagoFacilQr (producción).
 */
interface PasarelaQr
{
    /** Nombre corto guardado en transacciones.pasarela */
    public function nombre(): string;

    /**
     * Pide a la pasarela un QR para cobrar la transacción.
     * @return array{referencia: string, qr: string, es_imagen: bool}
     *         qr = texto a codificar en el QR, o imagen PNG en base64 si es_imagen = true
     */
    public function generarQr(Transaccion $trx, User $usuario): array;

    /** Consulta a la pasarela el estado del cobro: PENDIENTE | PAGADO | FALLIDO */
    public function consultar(Transaccion $trx): string;

    /**
     * Interpreta el aviso (callback/webhook) que envía la pasarela al pagarse un QR.
     * @return array{referencia: string, estado: string, autorizacion: ?string}|null  (null = aviso inválido)
     */
    public function leerCallback(Request $request): ?array;
}
