# Pagos con tarjeta: Stripe Checkout

El sistema cobra **cuotas de planes de pago, multas (CU9) y pagos únicos** con tarjeta usando
[Stripe Checkout](https://docs.stripe.com/payments/checkout). Se trabaja en **modo prueba**: no se mueve dinero real.

> No nos entregaron credenciales de PagoFácil; Stripe cumple el mismo rol (pasarela externa que confirma el pago).
> Los datos de la tarjeta los maneja Stripe: **nunca pasan por nuestro servidor**.

## Cómo funciona

```
Usuario ── "Pagar" ──▶ POST /api/pagos/stripe ──▶ Stripe crea la página de pago
   │                                                     │
   └──────────── el navegador va a checkout.stripe.com ◀─┘
                       │ paga con tarjeta
                       ▼
   /pagos/resultado?trx=ID ── consulta ──▶ GET /api/pagos/transacciones/ID/estado ──▶ pregunta a Stripe
                                                                     │
   Stripe ── aviso firmado ──▶ POST /api/pagos/stripe/webhook ───────┤
                                                                     ▼
                         transacción APROBADA + cuota / multa PAGADA (una sola vez)
```

- Lo que llegue primero (la consulta al volver o el webhook) confirma el pago; lo otro ya no hace nada.
- Si el usuario cierra la pestaña antes de volver, **el webhook igual confirma** el pago.

| Archivo | Qué hace |
|---|---|
| `app/Services/Pagos/Stripe.php` | Conector: crear página de pago, consultar, verificar firma del webhook (sin librerías) |
| `app/Http/Controllers/PagoStripeController.php` | Iniciar pago, estado, webhook y `confirmar()` |
| `resources/js/paginas/Pagos.jsx` | `ModalPago` (lleva a Stripe) y `ResultadoPago` (pantalla a la que vuelve Stripe) |
| `tests/Feature/PagoStripeTest.php` | Pruebas con la API de Stripe simulada (`Http::fake`) |

## Configuración (archivo `.env`, nunca en git)

```
PAGOS_STRIPE_SECRETA=sk_test_...     # Desarrolladores → Claves de API → Clave secreta
PAGOS_STRIPE_WEBHOOK=whsec_...       # se obtiene al crear el webhook (abajo)
PAGOS_STRIPE_MONEDA=bob              # bolivianos
```

Después de cambiar el `.env`: `php artisan config:clear` (en el servidor: `php artisan config:clear --env=servidor`).

## Crear el webhook en Stripe (una sola vez, para el servidor)

1. Stripe Dashboard (modo prueba) → **Workbench → Webhooks → Añade un destino**.
2. **Eventos**: elige estos 4
   - `checkout.session.completed`
   - `checkout.session.expired`
   - `checkout.session.async_payment_succeeded`
   - `checkout.session.async_payment_failed`
3. **Tipo de destino**: Punto de conexión de webhook (*Webhook endpoint*).
4. **URL del punto de conexión**:
   ```
   https://www.tecnoweb.org.bo/inf513/grupo08sa/proyecto2/api/pagos/stripe/webhook
   ```
5. Crear. En el detalle del destino, **Secreto de firma → Revelar** → copiar el `whsec_...` en
   `PAGOS_STRIPE_WEBHOOK` del `.env` del servidor.

### ¿Y en mi computadora (local)?

Stripe no puede llegar a `127.0.0.1`, pero **no hace falta**: al volver de Stripe, la pantalla de resultado
le pregunta a Stripe por el pago y lo confirma. El webhook es el respaldo para el servidor.
(Opcional: con la [Stripe CLI](https://docs.stripe.com/stripe-cli) `stripe listen --forward-to 127.0.0.1:8000/api/pagos/stripe/webhook`
se reciben los avisos en local; el `whsec_...` que muestra va en el `.env` local.)

## Tarjetas de prueba

| Tarjeta | Resultado |
|---|---|
| `4242 4242 4242 4242` | Pago aprobado |
| `4000 0000 0000 0002` | Tarjeta rechazada |
| `4000 0025 0000 3155` | Pide autenticación 3D Secure |

Cualquier fecha futura (ej. 12/34), cualquier CVC (ej. 123) y cualquier nombre.
