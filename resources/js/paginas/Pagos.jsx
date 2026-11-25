import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { Ban, CheckCircle2, Clock, CreditCard, ExternalLink, Loader2, Lock, Plus, Search, XCircle } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import useListado from '../lib/useListado';
import { bs, fecha, fechaHora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import { Badge, Cargando, Confirmar, Encabezado, Formulario, Modal, Paginacion, Tarjeta, useOpciones, useToast, Vacio } from '../componentes/ui';

/*
 * Pagos con tarjeta por Stripe Checkout:
 *   1. "Pagar" → el servidor crea la transacción y una página de pago en Stripe (POST /api/pagos/stripe)
 *   2. el navegador va a la página de Stripe; ahí se ingresa la tarjeta (nunca pasa por nuestro servidor)
 *   3. Stripe devuelve a /pagos/resultado?trx=ID → <ResultadoPago> consulta el estado hasta confirmarlo
 */

/** Modal de pago: muestra el monto y lleva a la página segura de Stripe. objetivo = { cuota_id } | { multa_id } | { concepto_id } */
export function ModalPago({ abierto, titulo, monto, objetivo, onCerrar }) {
    const [estado, setEstado] = useState('elegir'); // elegir | redirigiendo | error
    const [error, setError] = useState('');
    useEffect(() => {
        if (abierto) {
            setEstado('elegir');
            setError('');
        }
    }, [abierto]);

    const pagar = async () => {
        setEstado('redirigiendo');
        try {
            const { data } = await api.post('/pagos/stripe', objetivo);
            if (!data.url) throw new Error(data.message);
            sessionStorage.setItem('pago_volver', window.location.pathname.replace(window.APP?.base ?? '', '') || '/pagos');
            window.location.assign(data.url); // página de pago de Stripe
        } catch (e) {
            setError(e.response ? mensajeError(e) : e.message);
            setEstado('error');
        }
    };

    return (
        <Modal abierto={abierto} titulo={titulo} onCerrar={estado === 'redirigiendo' ? () => {} : onCerrar}>
            {estado === 'redirigiendo' ? (
                <div className="flex flex-col items-center gap-3 py-8" role="status">
                    <Loader2 className="size-12 animate-spin text-primario" aria-hidden />
                    <p className="font-medium">Abriendo la página de pago segura de Stripe…</p>
                </div>
            ) : (
                <>
                    <p className="text-suave">Monto a pagar</p>
                    <p className="mb-4 text-3xl font-bold">{bs(monto)}</p>
                    <div className="flex items-center gap-3 rounded-tema border border-primario bg-primario-tenue p-3">
                        <CreditCard className="size-6 shrink-0 text-primario" aria-hidden />
                        <span><strong>Tarjeta de débito o crédito</strong><span className="block text-xs text-suave">Pago procesado por Stripe. Los datos de la tarjeta no pasan por este sistema.</span></span>
                    </div>
                    <p className="mt-3 flex items-center gap-1.5 text-xs text-suave"><Lock className="size-3.5" aria-hidden /> Será dirigido a la página de Stripe y luego volverá aquí con el comprobante.</p>
                    {estado === 'error' && <p className="mt-3 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm font-medium text-peligro" role="alert">{error}</p>}
                    <div className="mt-5 flex justify-end gap-2">
                        <button className="btn btn-secundario" onClick={onCerrar}>Cancelar</button>
                        <button className="btn btn-primario" onClick={pagar}><ExternalLink className="size-4" aria-hidden /> Pagar {bs(monto)} con tarjeta</button>
                    </div>
                </>
            )}
        </Modal>
    );
}

// =========================================================== Resultado (Stripe vuelve aquí)
export function ResultadoPago() {
    const [params] = useSearchParams();
    const navegar = useNavigate();
    const { refrescarAvisos } = useAuth();
    const id = params.get('trx');
    const cancelado = params.get('cancelado');
    const [r, setR] = useState(null);
    const [intentos, setIntentos] = useState(0);
    const volver = useRef(sessionStorage.getItem('pago_volver') || '/pagos');

    useEffect(() => {
        if (!id) return undefined;
        let vigente = true;
        let t;
        const consultar = async (n) => {
            try {
                const { data } = await api.get(`/pagos/transacciones/${id}/estado`);
                if (!vigente) return;
                setR(data);
                setIntentos(n);
                // Stripe puede tardar unos segundos en confirmar: se reintenta hasta ~30 s (salvo que el usuario haya cancelado)
                if (data.data.estado === 'PENDIENTE' && !cancelado && n < 15) t = setTimeout(() => consultar(n + 1), 2000);
                if (data.data.estado === 'APROBADA') refrescarAvisos?.();
            } catch (e) {
                if (vigente) setR({ error: mensajeError(e) });
            }
        };
        consultar(1);
        return () => {
            vigente = false;
            clearTimeout(t);
        };
    }, [id, cancelado, refrescarAvisos]);

    if (!id) {
        return (
            <div className="mx-auto max-w-lg">
                <div className="tarjeta p-8 text-center">
                    <p className="text-lg font-semibold">No hay ningún pago para mostrar.</p>
                    <p className="mt-1 text-sm text-suave">Esta pantalla se abre sola al volver de la página de pago de Stripe.</p>
                    <button className="btn btn-primario mt-4" onClick={() => navegar('/pagos')}>Ir a Pagos</button>
                </div>
            </div>
        );
    }
    const trx = r?.data;
    const ok = trx?.estado === 'APROBADA';
    const esperando = trx?.estado === 'PENDIENTE' && !cancelado && intentos < 15;

    return (
        <div className="mx-auto max-w-lg">
            <div className="tarjeta flex flex-col items-center gap-2 p-8 text-center">
                {!r || esperando ? (
                    <>
                        <Loader2 className="size-14 animate-spin text-primario" aria-hidden />
                        <p className="text-xl font-bold" role="status">Confirmando el pago con Stripe…</p>
                    </>
                ) : r.error ? (
                    <>
                        <XCircle className="size-14 text-peligro" aria-hidden />
                        <p className="text-xl font-bold">No se pudo consultar el pago</p>
                        <p className="text-suave">{r.error}</p>
                    </>
                ) : (
                    <>
                        {ok ? <CheckCircle2 className="size-14 text-exito" aria-hidden /> : trx.estado === 'PENDIENTE' ? <Clock className="size-14 text-alerta" aria-hidden /> : <XCircle className="size-14 text-peligro" aria-hidden />}
                        <p className="text-xl font-bold">{ok ? 'Pago aprobado' : cancelado && trx.estado === 'PENDIENTE' ? 'Pago cancelado' : trx.estado === 'PENDIENTE' ? 'Pago en proceso' : trx.estado === 'EXPIRADA' ? 'La página de pago venció' : 'Pago rechazado'}</p>
                        <p className="text-suave">
                            {ok ? r.message : cancelado && trx.estado === 'PENDIENTE' ? 'No se realizó ningún cobro. Puede intentarlo de nuevo cuando quiera.'
                                : trx.estado === 'PENDIENTE' ? 'Stripe todavía no confirma el pago. Revise sus transacciones en unos minutos.' : trx.mensaje}
                        </p>
                        <dl className="mt-3 grid w-full grid-cols-2 gap-1 rounded-tema bg-superficie-2 p-3 text-left text-sm">
                            <dt className="text-suave">Transacción</dt><dd className="font-mono">{trx.codigo}</dd>
                            <dt className="text-suave">Concepto</dt><dd>{trx.descripcion}</dd>
                            <dt className="text-suave">Monto</dt><dd className="font-semibold">{bs(trx.monto)}</dd>
                            {ok && <><dt className="text-suave">Referencia Stripe</dt><dd className="font-mono text-xs break-all">{trx.autorizacion}</dd></>}
                            <dt className="text-suave">Fecha</dt><dd>{fechaHora(trx.updated_at)}</dd>
                        </dl>
                        {r.modo_prueba && <p className="mt-2 text-xs text-suave">Stripe en modo de prueba: no se cobró dinero real.</p>}
                    </>
                )}
                <div className="mt-4 flex gap-2">
                    {ok && <button className="btn btn-secundario" onClick={() => window.print()}>Imprimir comprobante</button>}
                    <button className="btn btn-primario" onClick={() => { sessionStorage.removeItem('pago_volver'); navegar(volver.current); }}>Volver</button>
                </div>
            </div>
        </div>
    );
}

/** Tarjeta de un plan con sus cuotas */
function PlanCard({ plan, onPagar, mostrarUsuario, acciones }) {
    const pagadas = plan.cuotas.filter((c) => c.estado === 'PAGADA').length;
    const siguiente = plan.cuotas.find((c) => c.estado === 'PENDIENTE');
    return (
        <article className="tarjeta p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h3 className="font-semibold">{plan.descripcion}</h3>
                    <p className="text-sm text-suave">
                        {mostrarUsuario && <>{plan.usuario?.nombre_completo} · </>}
                        {bs(plan.monto_total)} en {plan.nro_cuotas} cuota(s) · creado {fecha(plan.created_at?.slice(0, 10))}
                    </p>
                </div>
                <div className="flex items-center gap-2"><Badge valor={plan.estado} />{acciones}</div>
            </div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-superficie-2" role="progressbar" aria-valuenow={pagadas} aria-valuemin={0} aria-valuemax={plan.nro_cuotas} aria-label="Cuotas pagadas">
                <div className="h-full bg-exito" style={{ width: `${(pagadas / plan.nro_cuotas) * 100}%` }} />
            </div>
            <p className="mt-1 text-xs text-suave">{pagadas} de {plan.nro_cuotas} cuotas pagadas</p>
            <div className="mt-3 overflow-x-auto">
                <table className="tabla w-full text-sm">
                    <thead><tr><th>N°</th><th>Vence</th><th>Monto</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        {plan.cuotas.map((c) => {
                            const vencida = c.estado === 'PENDIENTE' && c.fecha_vencimiento < hoyISO();
                            return (
                                <tr key={c.id}>
                                    <td>{c.numero}</td>
                                    <td className={vencida ? 'font-semibold text-peligro' : ''}>{fecha(c.fecha_vencimiento)}{vencida && ' (vencida)'}</td>
                                    <td>{bs(c.monto)}</td>
                                    <td><Badge valor={c.estado} />{c.pagado_en && <span className="block text-xs text-suave">{fechaHora(c.pagado_en)}</span>}</td>
                                    <td className="text-right">
                                        {onPagar && plan.estado === 'VIGENTE' && siguiente?.id === c.id && (
                                            <button className="btn btn-primario btn-sm" onClick={() => onPagar(plan, c)}>Pagar</button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </article>
    );
}

// =========================================================== Mis pagos
export function MisPagos() {
    const { puede } = useAuth();
    const conceptos = useOpciones('conceptos');
    const [planes, setPlanes] = useState(null);
    const [pago, setPago] = useState(null); // { titulo, monto, objetivo }
    const [concepto, setConcepto] = useState('');
    const [f, setF] = useState({ q: '', estado: '' });
    const trx = useListado('/pagos/transacciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)));
    const verTodos = puede('pagos.ver_todos');

    const cargarPlanes = () => api.get('/pagos/planes', { params: { per_page: 50 } }).then((r) => setPlanes(r.data.data));
    useEffect(() => {
        cargarPlanes();
    }, []);

    const propios = puede('planes.gestionar') ? null : planes; // el Encargado gestiona planes en otra pantalla
    const elegido = conceptos.find((c) => String(c.id) === concepto);
    const pagables = conceptos.filter((c) => !c.label.startsWith('Multa por')); // las multas se pagan en Pagos → Multas

    return (
        <>
            <Encabezado titulo="Pagos" descripcion="Pague sus cuotas o un concepto único con tarjeta de débito o crédito (procesado por Stripe).">
                {puede('multas.ver') && <Link to="/multas" className="btn btn-secundario">Ver mis multas</Link>}
            </Encabezado>

            <div className="grid gap-5 lg:grid-cols-3">
                <section className="lg:col-span-2">
                    <h2 className="mb-3 text-lg font-semibold">{propios ? 'Mis planes de pago' : 'Planes de pago'}</h2>
                    {planes === null ? <Cargando /> : !propios ? (
                        <div className="tarjeta p-4 text-sm">Los planes de todos los usuarios se gestionan en <Link className="font-semibold text-primario underline" to="/pagos/planes">Planes de pago</Link>.</div>
                    ) : !propios.length ? <div className="tarjeta"><Vacio texto="No tiene planes de pago." /></div> : (
                        <div className="flex flex-col gap-4">
                            {propios.map((p) => (
                                <PlanCard
                                    key={p.id}
                                    plan={p}
                                    onPagar={puede('pagos.pagar') ? (plan, c) => setPago({
                                        titulo: `Cuota ${c.numero}/${plan.nro_cuotas}: ${plan.descripcion}`,
                                        monto: c.monto,
                                        objetivo: { cuota_id: c.id },
                                    }) : null}
                                />
                            ))}
                        </div>
                    )}
                </section>

                {puede('pagos.pagar') && (
                    <Tarjeta titulo="Pago único">
                        <p className="mb-3 text-sm text-suave">Pague un concepto sin plan de cuotas (certificados, reposiciones…).</p>
                        <label htmlFor="concepto" className="etiqueta">Concepto</label>
                        <select id="concepto" className="campo" value={concepto} onChange={(e) => setConcepto(e.target.value)}>
                            <option value="">— Seleccione —</option>
                            {pagables.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                        </select>
                        {elegido && <p className="mt-3 text-2xl font-bold">{bs(elegido.monto)}</p>}
                        <button
                            className="btn btn-primario mt-4 w-full"
                            disabled={!elegido}
                            onClick={() => setPago({
                                titulo: `Pago único: ${elegido.label.split(' - Bs')[0]}`,
                                monto: elegido.monto,
                                objetivo: { concepto_id: elegido.id },
                            })}
                        >
                            Continuar al pago
                        </button>
                    </Tarjeta>
                )}
            </div>

            <h2 className="mt-8 mb-3 text-lg font-semibold">{verTodos ? 'Transacciones (todos los usuarios)' : 'Mis transacciones'}</h2>
            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <div className="relative min-w-56 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
                        <input className="campo pl-9" placeholder="Buscar por código o descripción…" aria-label="Buscar transacciones" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
                    </div>
                    <select className="campo w-auto" aria-label="Estado" value={f.estado} onChange={(e) => setF({ ...f, estado: e.target.value })}>
                        <option value="">Estado: todos</option><option>APROBADA</option><option>PENDIENTE</option><option>EXPIRADA</option><option>RECHAZADA</option>
                    </select>
                </div>
                {!trx.datos ? <Cargando /> : !trx.datos.data.length ? <Vacio texto="Sin transacciones." /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Código</th><th>Fecha</th>{verTodos && <th>Usuario</th>}<th>Descripción</th><th>Pasarela</th><th>Monto</th><th>Estado</th></tr></thead>
                            <tbody>
                                {trx.datos.data.map((t) => (
                                    <tr key={t.id}>
                                        <td className="font-mono">{t.codigo}</td>
                                        <td className="whitespace-nowrap">{fechaHora(t.created_at)}</td>
                                        {verTodos && <td>{t.usuario?.nombre_completo}</td>}
                                        <td>{t.descripcion}</td>
                                        <td className="text-xs">{{ stripe: 'Tarjeta (Stripe)', pagofacil: 'QR PagoFácil', simulada: 'QR (simulado)', tarjeta: 'Tarjeta (simulada)' }[t.pasarela] ?? '—'}</td>
                                        <td className="font-semibold">{bs(t.monto)}</td>
                                        <td><Badge valor={t.estado} />{['RECHAZADA', 'EXPIRADA'].includes(t.estado) && <span className="block text-xs text-suave">{t.mensaje}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={trx.datos} onPagina={trx.setPagina} />
            </div>

            <ModalPago abierto={!!pago} titulo={pago?.titulo} monto={pago?.monto} objetivo={pago?.objetivo} onCerrar={() => setPago(null)} />
        </>
    );
}

// =========================================================== Planes de pago (Encargado)
const CAMPOS_PLAN = [
    { name: 'usuario_id', label: 'Usuario', tipo: 'select', opciones: 'usuarios', requerido: true, ancho: 'completo' },
    { name: 'concepto_id', label: 'Concepto', tipo: 'select', opciones: 'conceptos', ancho: 'completo', ayuda: 'Opcional: completa la descripción y el monto.' },
    { name: 'descripcion', label: 'Descripción', requerido: true, min: 5, max: 200, ancho: 'completo' },
    { name: 'monto_total', label: 'Monto total (Bs)', tipo: 'number', requerido: true, min: 0.01, max: 100000, paso: '0.01' },
    { name: 'nro_cuotas', label: 'Número de cuotas', tipo: 'number', requerido: true, min: 1, max: 24, entero: true },
    { name: 'fecha_primera_cuota', label: 'Vencimiento de la 1.ª cuota', tipo: 'date', requerido: true, noPasado: true },
];

export function PlanesPago() {
    const notificar = useToast();
    const conceptos = useOpciones('conceptos');
    const [f, setF] = useState({ q: '', estado: '' });
    const { datos, setPagina, cargar } = useListado('/pagos/planes', Object.fromEntries(Object.entries(f).filter(([, x]) => x)));
    const [form, setForm] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [anular, setAnular] = useState(null);

    // Al elegir un concepto se completan descripción y monto
    const setValores = (fn) => setV((prev) => {
        const nuevo = typeof fn === 'function' ? fn(prev) : fn;
        if (nuevo.concepto_id !== prev.concepto_id && nuevo.concepto_id) {
            const c = conceptos.find((x) => String(x.id) === String(nuevo.concepto_id));
            if (c) return { ...nuevo, descripcion: c.label.split(' - Bs')[0], monto_total: c.monto };
        }
        return nuevo;
    });

    const guardar = async () => {
        const e = validar(v, CAMPOS_PLAN);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            notificar((await api.post('/pagos/planes', v)).data.message);
            setForm(false);
            cargar();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const cuota = v.monto_total && v.nro_cuotas >= 1 ? Number(v.monto_total) / Number(v.nro_cuotas) : null;

    return (
        <>
            <Encabezado titulo="Planes de pago" descripcion="Deudas en cuotas por usuario (cursos, certificados, reposiciones…). Cada cuota se paga con tarjeta por Stripe.">
                <button className="btn btn-primario" onClick={() => { setV({ nro_cuotas: 1, fecha_primera_cuota: hoyISO() }); setErrores({}); setForm(true); }}>
                    <Plus className="size-4" aria-hidden /> Nuevo plan
                </button>
            </Encabezado>
            <div className="tarjeta mb-4 flex flex-wrap gap-3 p-3">
                <div className="relative min-w-56 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
                    <input className="campo pl-9" placeholder="Buscar por descripción o usuario…" aria-label="Buscar planes" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
                </div>
                <select className="campo w-auto" aria-label="Estado" value={f.estado} onChange={(e) => setF({ ...f, estado: e.target.value })}>
                    <option value="">Estado: todos</option><option>VIGENTE</option><option>PAGADO</option><option>ANULADO</option>
                </select>
            </div>
            {!datos ? <Cargando /> : !datos.data.length ? <div className="tarjeta"><Vacio /></div> : (
                <div className="grid gap-4 lg:grid-cols-2">
                    {datos.data.map((p) => (
                        <PlanCard
                            key={p.id} plan={p} mostrarUsuario
                            acciones={p.estado === 'VIGENTE' && !p.cuotas.some((c) => c.estado === 'PAGADA') && (
                                <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setAnular(p)}><Ban className="size-4" aria-hidden /> Anular</button>
                            )}
                        />
                    ))}
                </div>
            )}
            <div className="tarjeta mt-4"><Paginacion datos={datos} onPagina={setPagina} /></div>

            <Modal abierto={form} titulo="Nuevo plan de pagos" onCerrar={() => setForm(false)} ancho="max-w-2xl">
                <Formulario campos={CAMPOS_PLAN} valores={v} setValores={setValores} errores={errores} onEnviar={guardar} enviando={enviando} onCancelar={() => setForm(false)} textoBoton="Crear plan" />
                {cuota && <p className="mt-3 rounded-tema bg-info-tenue p-3 text-sm text-info">Se generarán {v.nro_cuotas} cuota(s) mensuales de aproximadamente {bs(cuota)}.</p>}
            </Modal>
            <Confirmar
                abierto={!!anular} titulo="Anular plan" mensaje={`¿Anular el plan "${anular?.descripcion}"?`} textoBoton="Anular"
                onCerrar={() => setAnular(null)}
                onConfirmar={async () => {
                    try {
                        notificar((await api.put(`/pagos/planes/${anular.id}/anular`)).data.message);
                        cargar();
                    } catch (e) {
                        notificar(mensajeError(e), 'error');
                    }
                    setAnular(null);
                }}
            />
        </>
    );
}
