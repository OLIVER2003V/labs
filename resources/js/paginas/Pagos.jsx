import { useCallback, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { Link } from 'react-router-dom';
import { Ban, CheckCircle2, CreditCard, Loader2, Plus, QrCode, Search, Smartphone, Star, Trash2, XCircle } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { luhn, P, validar } from '../lib/validar';
import useListado from '../lib/useListado';
import { bs, fecha, fechaHora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import { Badge, Cargando, Confirmar, Encabezado, Formulario, Modal, Paginacion, Tarjeta, useOpciones, useToast, Vacio } from '../componentes/ui';

const ICONO_METODO = { TARJETA: CreditCard, QR: QrCode, BILLETERA: Smartphone };
const etiquetaMetodo = (m) => `${m.alias} · ${m.marca ?? m.tipo}${m.ultimos4 ? ` •••• ${m.ultimos4}` : ''}`;

/** Pantalla del QR: muestra el código, cuenta regresiva y consulta el estado cada 3 s hasta que se pague */
function PantallaQr({ qr, onTerminar }) {
    const notificar = useToast();
    const [info, setInfo] = useState(qr);
    const [imagen, setImagen] = useState(qr.qr_es_imagen ? `data:image/png;base64,${qr.qr}` : null);
    const [ahora, setAhora] = useState(Date.now());
    const trx = info.data;

    useEffect(() => {
        if (!qr.qr_es_imagen && qr.qr) QRCode.toDataURL(qr.qr, { width: 260, margin: 1 }).then(setImagen);
    }, [qr]);

    useEffect(() => {
        const reloj = setInterval(() => setAhora(Date.now()), 1000);
        const consulta = setInterval(async () => {
            try {
                const { data } = await api.get(`/pagos/transacciones/${trx.id}/estado`);
                setInfo(data);
                if (data.data.estado !== 'PENDIENTE') onTerminar({ ok: data.data.estado === 'APROBADA', message: data.message, data: data.data });
            } catch {
                /* se reintenta en la siguiente vuelta */
            }
        }, 3000);
        return () => {
            clearInterval(reloj);
            clearInterval(consulta);
        };
    }, [trx.id, onTerminar]);

    const restante = Math.max(0, Math.floor((new Date(trx.expira_en).getTime() - ahora) / 1000));
    const simular = async () => {
        try {
            notificar((await api.post(`/pagos/simulador/${trx.id}/pagar`)).data.message, 'info');
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    return (
        <div className="flex flex-col items-center gap-3 text-center">
            <p className="text-sm text-suave">Escanee con la app de su banco (QR Simple / PagoFácil)</p>
            <div className="rounded-tema border border-borde bg-white p-3">
                {imagen ? <img src={imagen} alt={`Código QR para pagar ${bs(trx.monto)}`} className="size-60" /> : <Loader2 className="m-24 size-12 animate-spin text-primario" />}
            </div>
            <p className="text-2xl font-bold">{bs(trx.monto)}</p>
            <p className="text-xs text-suave">Transacción <span className="font-mono">{trx.codigo}</span> · Ref. <span className="font-mono">{trx.referencia_externa}</span></p>
            <p className="flex items-center gap-2 text-sm font-medium" role="status">
                <Loader2 className="size-4 animate-spin" aria-hidden /> Esperando el pago… vence en {Math.floor(restante / 60)}:{String(restante % 60).padStart(2, '0')}
            </p>
            {imagen && <a className="btn btn-secundario btn-sm" href={imagen} download={`${trx.codigo}.png`}>Descargar QR</a>}
            {info.simulada && (
                <div className="mt-2 w-full rounded-tema border border-dashed border-alerta bg-alerta-tenue p-3 text-sm">
                    <p className="font-semibold text-alerta">Modo simulado (PAGOS_PASARELA=simulada)</p>
                    <p className="mb-2 text-suave">Este botón hace de “app del banco”: marca el QR como pagado y el sistema lo detecta solo.</p>
                    <button className="btn btn-primario btn-sm" onClick={simular}>Simular pago desde el banco</button>
                </div>
            )}
        </div>
    );
}

/**
 * Modal de pago: QR PagoFácil (o simulado) o un método guardado (tarjeta / billetera, pasarela interna simulada)
 * objetivo = { cuota_id } o { concepto_id }   ·   onPagar(metodoId) paga con un método guardado
 */
export function PasarelaModal({ abierto, titulo, monto, objetivo, onCerrar, onPagar }) {
    const [metodos, setMetodos] = useState(null);
    const [metodo, setMetodo] = useState('qr');
    const [estado, setEstado] = useState('elegir'); // elegir | procesando | qr | resultado
    const [qr, setQr] = useState(null);
    const [resultado, setResultado] = useState(null);
    const terminar = useCallback((r) => {
        setResultado(r);
        setEstado('resultado');
    }, []);

    useEffect(() => {
        if (!abierto) return;
        setEstado('elegir');
        setResultado(null);
        setQr(null);
        setMetodo('qr');
        api.get('/pagos/metodos').then((r) => setMetodos(r.data)).catch(() => setMetodos([]));
    }, [abierto]);

    const pagar = async () => {
        setEstado('procesando');
        try {
            if (metodo === 'qr') {
                const { data } = await api.post('/pagos/qr', objetivo);
                setQr(data);
                setEstado('qr');
                return;
            }
            await new Promise((r) => setTimeout(r, 1200)); // simula la espera del banco
            const { data } = await onPagar(Number(metodo));
            terminar({ ok: true, ...data });
        } catch (e) {
            terminar({ ok: false, message: mensajeError(e), data: e.response?.data?.data });
        }
    };

    const opcion = (activo) => `flex cursor-pointer items-center gap-3 rounded-tema border p-3 ${activo ? 'border-primario bg-primario-tenue' : 'border-borde'}`;

    return (
        <Modal abierto={abierto} titulo={titulo} onCerrar={estado === 'procesando' ? () => {} : onCerrar}>
            {estado === 'elegir' && (
                <>
                    <p className="text-suave">Monto a pagar</p>
                    <p className="mb-4 text-3xl font-bold">{bs(monto)}</p>
                    <fieldset className="flex flex-col gap-2">
                        <legend className="etiqueta">Forma de pago</legend>
                        <label className={opcion(metodo === 'qr')}>
                            <input type="radio" name="metodo" value="qr" checked={metodo === 'qr'} onChange={() => setMetodo('qr')} className="accent-[var(--c-primario)]" />
                            <QrCode className="size-5" aria-hidden />
                            <span><strong>QR PagoFácil</strong><span className="block text-xs text-suave">Pague desde la app de cualquier banco</span></span>
                        </label>
                        {metodos === null ? <Cargando /> : metodos.map((m) => {
                            const I = ICONO_METODO[m.tipo];
                            return (
                                <label key={m.id} className={opcion(String(m.id) === metodo)}>
                                    <input type="radio" name="metodo" value={m.id} checked={String(m.id) === metodo} onChange={(e) => setMetodo(e.target.value)} className="accent-[var(--c-primario)]" />
                                    <I className="size-5" aria-hidden /> {etiquetaMetodo(m)}
                                </label>
                            );
                        })}
                    </fieldset>
                    {metodos?.length === 0 && <p className="mt-2 text-xs text-suave">¿Prefiere tarjeta? <Link to="/pagos/metodos" className="underline">Registre un método de pago</Link>.</p>}
                    {metodo !== 'qr' && <p className="mt-3 text-xs text-suave">Pasarela interna simulada: las tarjetas terminadas en 0002 son rechazadas por fondos insuficientes.</p>}
                    <div className="mt-5 flex justify-end gap-2">
                        <button className="btn btn-secundario" onClick={onCerrar}>Cancelar</button>
                        <button className="btn btn-primario" disabled={!metodo} onClick={pagar}>{metodo === 'qr' ? 'Generar QR' : `Pagar ${bs(monto)}`}</button>
                    </div>
                </>
            )}
            {estado === 'procesando' && (
                <div className="flex flex-col items-center gap-3 py-8" role="status">
                    <Loader2 className="size-12 animate-spin text-primario" aria-hidden />
                    <p className="font-medium">{metodo === 'qr' ? 'Generando el QR de pago…' : 'Procesando pago con la pasarela…'}</p>
                </div>
            )}
            {estado === 'qr' && qr && <PantallaQr qr={qr} onTerminar={terminar} />}
            {estado === 'resultado' && resultado && (
                <div className="flex flex-col items-center gap-2 py-4 text-center">
                    {resultado.ok ? <CheckCircle2 className="size-14 text-exito" aria-hidden /> : <XCircle className="size-14 text-peligro" aria-hidden />}
                    <p className="text-xl font-bold">{resultado.ok ? 'Pago aprobado' : resultado.data?.estado === 'EXPIRADA' ? 'QR vencido' : 'Pago rechazado'}</p>
                    <p className="text-suave">{resultado.message}</p>
                    {resultado.data && (
                        <dl className="mt-3 grid w-full grid-cols-2 gap-1 rounded-tema bg-superficie-2 p-3 text-left text-sm">
                            <dt className="text-suave">Transacción</dt><dd className="font-mono">{resultado.data.codigo}</dd>
                            <dt className="text-suave">Monto</dt><dd>{bs(resultado.data.monto)}</dd>
                            <dt className="text-suave">Autorización</dt><dd className="font-mono">{resultado.data.autorizacion ?? '—'}</dd>
                            <dt className="text-suave">Fecha</dt><dd>{fechaHora(resultado.data.created_at)}</dd>
                        </dl>
                    )}
                    <button className="btn btn-primario mt-4" onClick={onCerrar}>Cerrar</button>
                </div>
            )}
        </Modal>
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
    const [pago, setPago] = useState(null); // { titulo, monto, ejecutar }
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

    return (
        <>
            <Encabezado titulo="Pagos" descripcion="Pague sus cuotas o un concepto único con QR PagoFácil o con un método de pago guardado.">
                {puede('pagos.pagar') && <Link to="/pagos/metodos" className="btn btn-secundario"><CreditCard className="size-4" aria-hidden /> Mis métodos de pago</Link>}
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
                                        ejecutar: (metodo) => api.post(`/pagos/cuotas/${c.id}/pagar`, { metodo_pago_id: metodo }),
                                    }) : null}
                                />
                            ))}
                        </div>
                    )}
                </section>

                {puede('pagos.pagar') && (
                    <Tarjeta titulo="Pago único">
                        <p className="mb-3 text-sm text-suave">Pague un concepto sin plan de cuotas (certificados, multas, reposiciones…).</p>
                        <label htmlFor="concepto" className="etiqueta">Concepto</label>
                        <select id="concepto" className="campo" value={concepto} onChange={(e) => setConcepto(e.target.value)}>
                            <option value="">— Seleccione —</option>
                            {conceptos.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}
                        </select>
                        {elegido && <p className="mt-3 text-2xl font-bold">{bs(elegido.monto)}</p>}
                        <button
                            className="btn btn-primario mt-4 w-full"
                            disabled={!elegido}
                            onClick={() => setPago({
                                titulo: `Pago único: ${elegido.label.split(' - Bs')[0]}`,
                                monto: elegido.monto,
                                objetivo: { concepto_id: elegido.id },
                                ejecutar: (metodo) => api.post('/pagos/unico', { concepto_id: elegido.id, metodo_pago_id: metodo }),
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
                        <option value="">Estado: todos</option><option>APROBADA</option><option>RECHAZADA</option>
                    </select>
                </div>
                {!trx.datos ? <Cargando /> : !trx.datos.data.length ? <Vacio texto="Sin transacciones." /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Código</th><th>Fecha</th>{verTodos && <th>Usuario</th>}<th>Descripción</th><th>Método</th><th>Monto</th><th>Estado</th></tr></thead>
                            <tbody>
                                {trx.datos.data.map((t) => (
                                    <tr key={t.id}>
                                        <td className="font-mono">{t.codigo}</td>
                                        <td className="whitespace-nowrap">{fechaHora(t.created_at)}</td>
                                        {verTodos && <td>{t.usuario?.nombre_completo}</td>}
                                        <td>{t.descripcion}</td>
                                        <td className="text-xs">{t.metodo ? etiquetaMetodo(t.metodo) : { pagofacil: 'QR PagoFácil', simulada: 'QR (simulado)' }[t.pasarela] ?? '—'}</td>
                                        <td className="font-semibold">{bs(t.monto)}</td>
                                        <td><Badge valor={t.estado} />{t.estado === 'RECHAZADA' && <span className="block text-xs text-suave">{t.mensaje}</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={trx.datos} onPagina={trx.setPagina} />
            </div>

            <PasarelaModal
                abierto={!!pago}
                titulo={pago?.titulo}
                monto={pago?.monto}
                objetivo={pago?.objetivo}
                onPagar={(metodo) => pago.ejecutar(metodo)}
                onCerrar={() => {
                    setPago(null);
                    cargarPlanes();
                    trx.cargar();
                }}
            />
        </>
    );
}

// =========================================================== Métodos de pago
const CAMPOS_METODO = [
    { name: 'tipo', label: 'Tipo', tipo: 'select', requerido: true, opciones: [{ id: 'TARJETA', label: 'Tarjeta de débito/crédito' }, { id: 'QR', label: 'QR Simple' }, { id: 'BILLETERA', label: 'Billetera móvil' }] },
    { name: 'alias', label: 'Alias', requerido: true, min: 3, max: 50, placeholder: 'Ej: Mi Visa' },
    { name: 'titular', label: 'Titular', requerido: true, min: 5, max: 100, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.', ancho: 'completo' },
    {
        name: 'numero_tarjeta', label: 'Número de tarjeta', requerido: true, oculto: (v) => v.tipo !== 'TARJETA', placeholder: '4111 1111 1111 1111', ancho: 'completo', max: 23,
        validar: (x) => {
            const n = x.replace(/\s/g, '');
            if (!/^\d{13,19}$/.test(n)) return 'La tarjeta debe tener entre 13 y 19 dígitos.';
            return luhn(n) ? null : 'El número de tarjeta no es válido (verificación Luhn).';
        },
        ayuda: 'Prueba: 4111 1111 1111 1111 (aprobada) · 4000 0000 0000 0002 (rechazada)',
    },
    {
        name: 'vencimiento', label: 'Vencimiento (MM/AA)', requerido: true, oculto: (v) => v.tipo !== 'TARJETA', placeholder: '12/28', max: 5,
        patron: /^(0[1-9]|1[0-2])\/\d{2}$/, mensajePatron: 'Use el formato MM/AA.',
        validar: (x) => {
            const [m, a] = x.split('/').map(Number);
            return new Date(2000 + a, m, 0, 23, 59) < new Date() ? 'La tarjeta está vencida.' : null;
        },
    },
    { name: 'cvv', label: 'CVV', tipo: 'password', requerido: true, oculto: (v) => v.tipo !== 'TARJETA', patron: /^\d{3,4}$/, mensajePatron: 'El CVV tiene 3 o 4 dígitos.', max: 4, ayuda: 'No se almacena.' },
    { name: 'telefono', label: 'Número de celular', requerido: true, oculto: (v) => v.tipo !== 'BILLETERA', patron: P.telefono, mensajePatron: 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.' },
];

export function MetodosPago() {
    const notificar = useToast();
    const [metodos, setMetodos] = useState(null);
    const [form, setForm] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [borrar, setBorrar] = useState(null);

    const cargar = () => api.get('/pagos/metodos').then((r) => setMetodos(r.data));
    useEffect(() => {
        cargar();
    }, []);

    const guardar = async () => {
        const e = validar(v, CAMPOS_METODO);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            const datos = { ...v, numero_tarjeta: v.numero_tarjeta?.replace(/\s/g, '') };
            notificar((await api.post('/pagos/metodos', datos)).data.message);
            setForm(false);
            cargar();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const accion = async (fn) => {
        try {
            notificar((await fn()).data.message);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    return (
        <>
            <Encabezado titulo="Métodos de pago" descripcion="Por seguridad solo se guardan los últimos 4 dígitos de la tarjeta y un token; el CVV nunca se almacena.">
                <button className="btn btn-primario" onClick={() => { setV({ tipo: 'TARJETA' }); setErrores({}); setForm(true); }}>
                    <Plus className="size-4" aria-hidden /> Agregar método
                </button>
            </Encabezado>
            {metodos === null ? <Cargando /> : !metodos.length ? <div className="tarjeta"><Vacio texto="Aún no registró métodos de pago." /></div> : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {metodos.map((m) => {
                        const I = ICONO_METODO[m.tipo];
                        return (
                            <article key={m.id} className="tarjeta relative overflow-hidden p-5">
                                <div className="absolute -top-8 -right-8 size-28 rounded-full bg-primario-tenue" aria-hidden />
                                <I className="relative size-8 text-primario" aria-hidden />
                                <p className="relative mt-3 font-semibold">{m.alias}</p>
                                <p className="font-mono text-lg tracking-widest">{m.ultimos4 ? `•••• ${m.ultimos4}` : 'QR'}</p>
                                <p className="text-xs text-suave">{m.titular} · {m.marca}{m.vencimiento && ` · vence ${m.vencimiento}`}</p>
                                <div className="mt-4 flex items-center justify-between">
                                    {m.predeterminado ? (
                                        <span className="flex items-center gap-1 text-sm font-semibold text-exito"><Star className="size-4 fill-current" aria-hidden /> Predeterminado</span>
                                    ) : (
                                        <button className="btn btn-fantasma btn-sm" onClick={() => accion(() => api.put(`/pagos/metodos/${m.id}/predeterminado`))}>Usar por defecto</button>
                                    )}
                                    <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setBorrar(m)} aria-label={`Eliminar ${m.alias}`}><Trash2 className="size-4" /></button>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
            <Modal abierto={form} titulo="Agregar método de pago" onCerrar={() => setForm(false)}>
                <Formulario campos={CAMPOS_METODO} valores={v} setValores={setV} errores={errores} onEnviar={guardar} enviando={enviando} onCancelar={() => setForm(false)} textoBoton="Registrar" />
            </Modal>
            <Confirmar
                abierto={!!borrar} titulo="Eliminar método de pago" mensaje={`¿Eliminar "${borrar?.alias}"? Las transacciones anteriores se conservan.`} textoBoton="Eliminar"
                onCerrar={() => setBorrar(null)}
                onConfirmar={async () => {
                    await accion(() => api.delete(`/pagos/metodos/${borrar.id}`));
                    setBorrar(null);
                }}
            />
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
            <Encabezado titulo="Planes de pago" descripcion="Deudas en cuotas por usuario: alquileres para eventos, multas por daños, cursos.">
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
