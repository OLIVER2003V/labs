# Pagos con PagoFácil (QR)

## Cómo funciona

```
Usuario ──"Generar QR"──▶ POST /api/pagos/qr ──▶ Transacción PENDIENTE + QR (vence en 15 min)
                                   │
           escanea y paga con la app de su banco
                                   │
PagoFácil ──aviso──▶ POST /api/pagos/pagofacil/callback ──┐
La pantalla ──cada 3 s──▶ GET /api/pagos/transacciones/{id}/estado ──┤──▶ APROBADA + cuota PAGADA
                                                                      (el trigger cierra el plan si era la última)
```

| Archivo | Rol |
|---|---|
| `app/Services/Pagos/PasarelaQr.php` | Contrato: `generarQr`, `consultar`, `leerCallback` |
| `app/Services/Pagos/SimuladaQr.php` | Pasarela de prueba (sin credenciales) |
| `app/Services/Pagos/PagoFacilQr.php` | Conector real de PagoFácil (**completar con la documentación oficial**) |
| `app/Http/Controllers/PagoQrController.php` | Flujo: iniciar, consultar estado, simulador, callback, confirmación con bloqueo |
| `config/pagos.php` | Elige la pasarela y lee las credenciales del `.env` |
| `resources/js/paginas/Pagos.jsx` | Modal de pago: QR, cuenta regresiva, consulta automática |

## Ahora: modo simulado

`.env` → `PAGOS_PASARELA=simulada`. El QR se genera de verdad; el botón **“Simular pago desde el banco”**
hace de app del banco y el sistema detecta el pago solo (igual que con la pasarela real).

## Cuando lleguen las credenciales

1. En el `.env` del servidor (nunca en git):
   ```
   PAGOS_PASARELA=pagofacil
   PAGOFACIL_URL=https://...            # URL base de la API que indique PagoFácil
   PAGOFACIL_TOKEN_SERVICIO=...
   PAGOFACIL_TOKEN_SECRETO=...
   PAGOFACIL_COMERCIO=...
   PAGOFACIL_CALLBACK=https://www.tecnoweb.org.bo/inf513/grupo08sa/proyecto2/api/pagos/pagofacil/callback
   ```
2. Abrir `app/Services/Pagos/PagoFacilQr.php` y ajustar las líneas marcadas `TODO` a la documentación oficial:
   - ruta y campos del **login** (token de acceso)
   - ruta y campos de **generar QR** (y de dónde sale el id de la transacción y la imagen del QR)
   - ruta y valores de estado de **consultar transacción**
   - campos que envía el **callback** (y validar la firma si la usan)
3. Probar: `php artisan test --filter=PagoQr` (simula las respuestas de PagoFácil con `Http::fake`;
   actualice los nombres de campos del test si cambian) y luego un pago real de Bs 0,10 si lo permiten.
4. Si algo falla, volver a `PAGOS_PASARELA=simulada`: el resto del sistema sigue funcionando.

## Seguridad

- El callback es público (lo llama PagoFácil) pero solo puede **confirmar** una transacción PENDIENTE que exista; cada aviso queda en la bitácora con su IP.
- La confirmación usa `lockForUpdate()`: si llegan a la vez el callback y la consulta, la cuota se paga una sola vez.
- Si se pide el QR de nuevo para la misma cuota, se reutiliza el vigente (no se cobra dos veces).
