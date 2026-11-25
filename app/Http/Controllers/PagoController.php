<?php

namespace App\Http\Controllers;

use App\Models\Cuota;
use App\Models\PlanPago;
use App\Models\Transaccion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Módulo de pagos:
 *  - Planes de pago en cuotas por usuario (los crea el Encargado)
 *  - Historial de transacciones
 * El cobro con tarjeta (cuotas, multas y pagos únicos) lo hace Stripe: ver PagoStripeController.
 */
class PagoController extends Controller
{
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

    // ---------------- Historial ----------------

    public function transacciones(Request $request): JsonResponse
    {
        $q = Transaccion::with('usuario')->orderByDesc('id');
        if (! $request->user()->tienePermiso('pagos.ver_todos')) {
            $q->where('usuario_id', $request->user()->id);
        }
        $q->when($request->filled('estado'), fn ($x) => $x->where('estado', $request->estado));
        if ($texto = trim((string) $request->query('q'))) {
            $q->where(fn ($x) => $x->where('codigo', 'ILIKE', "%$texto%")->orWhere('descripcion', 'ILIKE', "%$texto%"));
        }

        return response()->json($q->paginate($this->porPagina($request, 10)));
    }
}
