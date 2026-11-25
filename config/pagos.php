<?php

/*
 * Pasarela de pagos por QR.
 *   PAGOS_PASARELA=simulada   → todo funciona sin credenciales (QR de prueba + botón "simular pago del banco")
 *   PAGOS_PASARELA=pagofacil  → usa App\Services\Pagos\PagoFacilQr con las credenciales de abajo
 */
return [
    'pasarela' => env('PAGOS_PASARELA', 'simulada'),

    // Minutos de validez del QR antes de marcarse EXPIRADO
    'minutos_qr' => (int) env('PAGOS_MINUTOS_QR', 15),

    // CU9: días para pagar una multa antes de que venza (vencida = no puede reservar ni pedir permutas)
    'dias_multa' => (int) env('PAGOS_DIAS_MULTA', 7),

    'pagofacil' => [
        // Completar con los datos que entregue PagoFácil (NO subir a git: van en .env)
        'url' => env('PAGOFACIL_URL', ''),
        'token_servicio' => env('PAGOFACIL_TOKEN_SERVICIO', ''),
        'token_secreto' => env('PAGOFACIL_TOKEN_SECRETO', ''),
        'comercio' => env('PAGOFACIL_COMERCIO', ''),
        // URL pública a la que PagoFácil avisa cuando se paga (debe ser accesible desde internet)
        'callback' => env('PAGOFACIL_CALLBACK', rtrim((string) env('APP_URL'), '/').'/api/pagos/pagofacil/callback'),
    ],
];
