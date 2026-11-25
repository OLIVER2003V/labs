<?php

/*
 * Pagos con Stripe Checkout (tarjeta). Las claves van SOLO en .env (nunca en git):
 *   PAGOS_STRIPE_SECRETA=sk_test_...   → Stripe Dashboard → Desarrolladores → Claves de API → Clave secreta
 *   PAGOS_STRIPE_WEBHOOK=whsec_...     → Stripe Dashboard → Workbench → Webhooks → (tu destino) → Secreto de firma
 * Ver docs/STRIPE.md
 */
return [
    'stripe' => [
        'secreta' => env('PAGOS_STRIPE_SECRETA', ''),
        'webhook' => env('PAGOS_STRIPE_WEBHOOK', ''),
        'moneda' => strtolower(env('PAGOS_STRIPE_MONEDA', 'bob')), // bolivianos
        'minutos' => (int) env('PAGOS_STRIPE_MINUTOS', 30),        // validez de la página de pago (mínimo 30)
    ],

    // CU9: días para pagar una multa antes de que venza (vencida = no puede reservar ni pedir permutas)
    'dias_multa' => (int) env('PAGOS_DIAS_MULTA', 7),
];
