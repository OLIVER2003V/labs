<?php

namespace App\Http\Controllers;

use App\Models\ConceptoPago;
use App\Models\Cuota;
use App\Models\MetodoPago;
use App\Models\PlanPago;
use App\Models\Transaccion;
use App\Models\User;
use App\Services\PasarelaPago;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Módulo de pagos electrónicos:
 *  - Métodos de pago del usuario (tarjeta, QR, billetera)
 *  - Pagos únicos (por un concepto del catálogo)
 *  - Planes de pago en cuotas por usuario
 *  - Historial de transacciones
 */
class PagoController extends Controller
{
    // ---------------- Métodos de pago (propios) ----------------

    public function metodos(Request $request): JsonResponse
    {
        return response()->json(
            MetodoPago::where('usuario_id', $request->user()->id)->orderByDesc('predeterminado')->orderBy('id')->get()
        );
    }

    public function registrarMetodo(Request $request): JsonResponse
    {
        $tipo = $request->input('tipo');
        $reglas = [
            'tipo' => ['required', Rule::in(['TARJETA', 'QR', 'BILLETERA'])],
            'alias' => ['required', 'string', 'min:3', 'max:50'],
            'titular' => ['required', 'string', 'min:5', 'max:100', 'regex:/^[\pL\s]+$/u'],
        ];
        if ($tipo === 'TARJETA') {
            $reglas += [
                'numero_tarjeta' => ['required', 'digits_between:13,19'],
                'vencimiento' => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{2}$/'],
                'cvv' => ['required', 'digits_between:3,4'],
            ];
        } elseif ($tipo === 'BILLETERA') {
            $reglas += ['telefono' => ['required', 'regex:/^[67]\d{7}$/']];
        }
        $d = $request->validate($reglas, [
            'titular.regex' => 'El titular solo puede contener letras y espacios.',
            'vencimiento.regex' => 'El vencimiento debe tener el formato MM/AA.',
            'telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.',
        ]);

        $datos = [
            'usuario_id' => $request->user()->id, 'tipo' => $d['tipo'], 'alias' => $d['alias'],
            'titular' => mb_strtoupper($d['titular']), 'token' => 'tok_'.Str::random(32),
        ];
        if ($tipo === 'TARJETA') {
            abort_unless(PasarelaPago::luhn($d['numero_tarjeta']), 422, 'El número de tarjeta no es válido.');
            abort_if(PasarelaPago::vencida($d['vencimiento']), 422, 'La tarjeta está vencida.');
            $datos += ['marca' => PasarelaPago::marca($d['numero_tarjeta']), 'ultimos4' => substr($d['numero_tarjeta'], -4), 'vencimiento' => $d['vencimiento']];
        } elseif ($tipo === 'BILLETERA') {
            $datos += ['marca' => 'BILLETERA', 'ultimos4' => substr($d['telefono'], -4)];
        } else {
            $datos += ['marca' => 'QR SIMPLE'];
        }
        $datos['predeterminado'] = ! MetodoPago::where('usuario_id', $request->user()->id)->exists();

        return response()->json(['message' => 'Método de pago registrado.', 'data' => MetodoPago::create($datos)], 201);
    }

    public function predeterminado(Request $request, int $id): JsonResponse
    {
        $metodo = MetodoPago::where('usuario_id', $request->user()->id)->findOrFail($id);
        DB::transaction(function () use ($metodo) {
            MetodoPago::where('usuario_id', $metodo->usuario_id)->update(['predeterminado' => false]);
            $metodo->update(['predeterminado' => true]);
        });

        return response()->json(['message' => "\"{$metodo->alias}\" es ahora su método predeterminado."]);
    }

    public function eliminarMetodo(Request $request, int $id): JsonResponse
    {
        MetodoPago::where('usuario_id', $request->user()->id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Método de pago eliminado.']);
    }

    // ---------------- Planes de pago ----------------

    public function planes(Request $request): JsonResponse
    {
        $q = PlanPago::with(['usuario', 'concepto', 'cuotas' => fn ($c) => $c->orderBy('numero')])->orderByDesc('id');
        if (! $request->user()->tienePermiso('planes.gestionar')) {
            $q->where('usuario_id', $request->user()->id);
        } elseif ($request->filled('usuario_id')) {
            $q->where('usuario_id', $request->integer('usuario_id'));
        }
        $q->when($request->filled('estado'), fn ($x) => $x->where('estado', $request->estado));
        if ($texto = trim((string) $request->query('q'))) {
            $q->where(fn ($x) => $x->where('descripcion', 'ILIKE', "%$texto%")
                ->orWhereHas('usuario', fn ($u) => $u->where('nombre', 'ILIKE', "%$texto%")->orWhere('apellido', 'ILIKE', "%$texto%")));
        }

        return response()->json($q->paginate($this->porPagina($request, 10)));
    }

    public function crearPlan(Request $request): JsonResponse
    {
        $d = $request->validate([
            'usuario_id' => ['required', 'integer', 'exists:usuarios,id'],
            'concepto_id' => ['nullable', 'integer', 'exists:conceptos_pago,id'],
            'descripcion' => ['required', 'string', 'min:5', 'max:200'],
            'monto_total' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'nro_cuotas' => ['required', 'integer', 'between:1,24'],
            'fecha_primera_cuota' => ['required', 'date', 'after_or_equal:today'],
        ], ['fecha_primera_cuota.after_or_equal' => 'La primera cuota no puede vencer en una fecha pasada.']);
        abort_unless(User::where('id', $d['usuario_id'])->where('estado', 'ACTIVO')->exists(), 422, 'El usuario está inactivo.');

        $plan = DB::transaction(function () use ($d, $request) {
            $plan = PlanPago::create([
                'usuario_id' => $d['usuario_id'], 'concepto_id' => $d['concepto_id'] ?? null,
                'descripcion' => $d['descripcion'], 'monto_total' => $d['monto_total'],
                'nro_cuotas' => $d['nro_cuotas'], 'estado' => 'VIGENTE', 'creado_por' => $request->user()->id,
            ]);
            // Cuotas iguales; la última absorbe el redondeo para que la suma sea exacta
            $base = floor($d['monto_total'] * 100 / $d['nro_cuotas']) / 100;
            $primera = Carbon::parse($d['fecha_primera_cuota']);
            for ($i = 1; $i <= $d['nro_cuotas']; $i++) {
                $monto = $i < $d['nro_cuotas'] ? $base : round($d['monto_total'] - $base * ($d['nro_cuotas'] - 1), 2);
                Cuota::create([
                    'plan_id' => $plan->id, 'numero' => $i, 'monto' => $monto,
                    'fecha_vencimiento' => $primera->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                ]);
            }

            return $plan;
        });

        return response()->json(['message' => "Plan de pagos creado con {$d['nro_cuotas']} cuota(s).", 'data' => $plan->load('cuotas')], 201);
    }

    public function anularPlan(int $id): JsonResponse
    {
        $plan = PlanPago::findOrFail($id);
        abort_if($plan->estado !== 'VIGENTE', 422, "El plan ya está {$plan->estado}.");
        abort_if($plan->cuotas()->where('estado', 'PAGADA')->exists(), 422, 'No se puede anular un plan que ya tiene cuotas pagadas.');
        $plan->update(['estado' => 'ANULADO']);

        return response()->json(['message' => 'Plan anulado.']);
    }

    // ---------------- Procesar pagos ----------------

    public function pagarCuota(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['metodo_pago_id' => ['required', 'integer']]);
        $usuario = $request->user();
        $metodo = MetodoPago::where('usuario_id', $usuario->id)->findOrFail($d['metodo_pago_id']);

        return DB::transaction(function () use ($id, $usuario, $metodo) {
            $cuota = Cuota::with('plan')->lockForUpdate()->findOrFail($id); // bloqueo: evita pagar dos veces
            abort_if($cuota->plan->usuario_id !== $usuario->id, 403, 'Esta cuota no le pertenece.');
            abort_if($cuota->plan->estado !== 'VIGENTE', 422, "El plan está {$cuota->plan->estado}.");
            abort_if($cuota->estado === 'PAGADA', 422, 'Esta cuota ya fue pagada.');
            $anterior = Cuota::where('plan_id', $cuota->plan_id)->where('numero', '<', $cuota->numero)->where('estado', 'PENDIENTE')->exists();
            abort_if($anterior, 422, 'Debe pagar primero las cuotas anteriores.');

            $trx = $this->procesar($usuario, $metodo, (float) $cuota->monto,
                "Cuota {$cuota->numero}/{$cuota->plan->nro_cuotas} - {$cuota->plan->descripcion}", ['cuota_id' => $cuota->id]);
            if ($trx->estado === 'APROBADA') {
                $cuota->update(['estado' => 'PAGADA', 'pagado_en' => now()]); // el trigger cierra el plan si era la última
            }

            return $this->respuesta($trx);
        });
    }

    public function pagoUnico(Request $request): JsonResponse
    {
        $d = $request->validate([
            'concepto_id' => ['required', 'integer', 'exists:conceptos_pago,id'],
            'metodo_pago_id' => ['required', 'integer'],
        ]);
        $usuario = $request->user();
        $metodo = MetodoPago::where('usuario_id', $usuario->id)->findOrFail($d['metodo_pago_id']);
        $concepto = ConceptoPago::where('activo', true)->findOrFail($d['concepto_id']);

        $trx = DB::transaction(fn () => $this->procesar($usuario, $metodo, (float) $concepto->monto, $concepto->nombre, ['concepto_id' => $concepto->id]));

        return $this->respuesta($trx);
    }

    public function transacciones(Request $request): JsonResponse
    {
        $q = Transaccion::with(['usuario', 'metodo' => fn ($m) => $m->withTrashed()])->orderByDesc('id');
        if (! $request->user()->tienePermiso('pagos.ver_todos')) {
            $q->where('usuario_id', $request->user()->id);
        }
        $q->when($request->filled('estado'), fn ($x) => $x->where('estado', $request->estado));
        if ($texto = trim((string) $request->query('q'))) {
            $q->where(fn ($x) => $x->where('codigo', 'ILIKE', "%$texto%")->orWhere('descripcion', 'ILIKE', "%$texto%"));
        }

        return response()->json($q->paginate($this->porPagina($request, 10)));
    }

    private function procesar(User $usuario, MetodoPago $metodo, float $monto, string $descripcion, array $extra): Transaccion
    {
        $resultado = PasarelaPago::cobrar($metodo, $monto);
        $trx = Transaccion::create($extra + [
            'codigo' => 'TMP-'.Str::random(12),
            'usuario_id' => $usuario->id,
            'metodo_pago_id' => $metodo->id,
            'descripcion' => substr($descripcion, 0, 200),
            'monto' => $monto,
            'estado' => $resultado['aprobada'] ? 'APROBADA' : 'RECHAZADA',
            'autorizacion' => $resultado['autorizacion'],
            'mensaje' => $resultado['mensaje'],
        ]);
        $trx->update(['codigo' => 'TRX-'.str_pad((string) $trx->id, 6, '0', STR_PAD_LEFT)]);

        return $trx;
    }

    private function respuesta(Transaccion $trx): JsonResponse
    {
        $ok = $trx->estado === 'APROBADA';

        return response()->json([
            'message' => $ok ? "Pago aprobado. Transacción {$trx->codigo}, autorización {$trx->autorizacion}." : "Pago rechazado: {$trx->mensaje}",
            'data' => $trx,
        ], $ok ? 201 : 402);
    }
}
