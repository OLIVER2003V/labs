import { useEffect, useState } from 'react';
import { AlertTriangle, History, RotateCcw, Search, Undo2 } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { fechaHora } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import useListado from '../lib/useListado';
import { Badge, Cargando, Confirmar, Encabezado, Modal, Paginacion, Tarjeta, useToast, Vacio } from '../componentes/ui';

/** Bitácora: inicios de sesión exitosos y fallidos, IP, recursos accedidos */
export function Bitacora() {
    const [f, setF] = useState({ q: '', evento: '', desde: '', hasta: '' });
    const filtros = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    const { datos, setPagina } = useListado('/bitacora', filtros);
    const [resumen, setResumen] = useState(null);

    useEffect(() => {
        api.get('/bitacora/resumen').then((r) => setResumen(r.data)).catch(() => {});
    }, []);

    return (
        <>
            <Encabezado titulo="Bitácora de accesos" descripcion="Registro persistente de inicios de sesión (exitosos y fallidos), direcciones IP y recursos accedidos." />

            {resumen && (
                <div className="mb-5 grid gap-5 lg:grid-cols-3">
                    <Tarjeta titulo="Recursos con mayor acceso (30 días)">
                        <ol className="flex flex-col gap-1.5 text-sm">
                            {resumen.recursos.map((r, i) => (
                                <li key={r.recurso} className="flex justify-between"><span>{i + 1}. {r.recurso}</span><strong>{r.total}</strong></li>
                            ))}
                        </ol>
                    </Tarjeta>
                    <Tarjeta titulo="Direcciones IP más activas">
                        <ol className="flex flex-col gap-1.5 text-sm">
                            {resumen.ips.map((r) => (
                                <li key={r.ip} className="flex justify-between gap-2">
                                    <code>{r.ip}</code>
                                    <span>{r.total} {Number(r.fallidos) > 0 && <span className="text-peligro">({r.fallidos} fallidos)</span>}</span>
                                </li>
                            ))}
                        </ol>
                    </Tarjeta>
                    <Tarjeta titulo="Últimos intentos fallidos">
                        <ul className="flex flex-col gap-2 text-sm">
                            {resumen.fallidos.map((r, i) => (
                                <li key={i}>
                                    <span className="font-medium">{r.email}</span>
                                    <span className="block text-xs text-suave">{r.ip} · {r.detalle} · {fechaHora(r.created_at)}</span>
                                </li>
                            ))}
                        </ul>
                    </Tarjeta>
                </div>
            )}

            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <div className="relative min-w-56 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
                        <input className="campo pl-9" placeholder="Buscar por correo, IP o recurso…" aria-label="Buscar en la bitácora" value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
                    </div>
                    <select className="campo w-auto" aria-label="Evento" value={f.evento} onChange={(e) => setF({ ...f, evento: e.target.value })}>
                        <option value="">Evento: todos</option>
                        {['LOGIN_OK', 'LOGIN_FALLIDO', 'LOGOUT', 'ACCESO', 'DENEGADO', 'REGISTRO'].map((x) => <option key={x} value={x}>{x.replace('_', ' ')}</option>)}
                    </select>
                    <input type="date" className="campo w-auto" aria-label="Desde" value={f.desde} onChange={(e) => setF({ ...f, desde: e.target.value })} />
                    <input type="date" className="campo w-auto" aria-label="Hasta" value={f.hasta} onChange={(e) => setF({ ...f, hasta: e.target.value })} />
                </div>
                {!datos ? <Cargando /> : !datos.data.length ? <Vacio /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead>
                                <tr><th>Fecha</th><th>Evento</th><th>Usuario</th><th>IP</th><th>Método / recurso</th><th>HTTP</th><th>Detalle</th></tr>
                            </thead>
                            <tbody>
                                {datos.data.map((b) => (
                                    <tr key={b.id}>
                                        <td className="whitespace-nowrap">{fechaHora(b.created_at)}</td>
                                        <td><Badge valor={b.evento} /></td>
                                        <td>{b.usuario ? `${b.usuario.nombre} ${b.usuario.apellido}` : '—'}<span className="block text-xs text-suave">{b.email}</span></td>
                                        <td><code>{b.ip}</code></td>
                                        <td><code className="text-xs">{b.metodo}</code> {b.recurso}<span className="block max-w-48 truncate text-xs text-suave" title={b.user_agent}>{b.user_agent}</span></td>
                                        <td>{b.codigo_http}</td>
                                        <td className="text-xs">{b.detalle ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={datos} onPagina={setPagina} />
            </div>
        </>
    );
}

const TABLAS = ['usuarios', 'roles', 'rol_permiso', 'laboratorios', 'semestres', 'materias', 'horarios', 'reservas', 'equipos', 'equipos_red',
    'incidencias', 'objetos_encontrados', 'turnos', 'asistencias', 'permisos_auxiliar', 'permutas', 'conceptos_pago', 'planes_pago', 'cuotas',
    'transacciones', 'multas'];

/** "2026-11-25T14:30" de hace N minutos (para el campo datetime-local) */
const haceMinutos = (n) => {
    const d = new Date(Date.now() - n * 60000);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
};

/**
 * Auditoría: cambios capturados por los TRIGGERS de PostgreSQL (incluye cambios hechos por SQL directo).
 * CU10: filtrar los cambios "Directo en BD" (posible sabotaje), deshacer una modificación y restaurar en lote lo eliminado.
 */
export function Auditoria() {
    const { puede, refrescarAvisos, avisos } = useAuth();
    const notificar = useToast();
    const [f, setF] = useState({ tabla: '', operacion: '', origen: '', desde: '', hasta: '' });
    const filtros = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    const { datos, setPagina, cargar } = useListado('/auditoria', filtros);
    const [detalle, setDetalle] = useState(null);
    const [accion, setAccion] = useState(null); // { tipo: 'restaurar' | 'deshacer', a }
    const [lote, setLote] = useState(null);     // formulario de restauración en lote
    const [resultado, setResultado] = useState(null);
    const [enviando, setEnviando] = useState(false);
    const restaura = puede('auditoria.restaurar');

    useEffect(() => {
        if (f.origen === 'directo') refrescarAvisos?.();
    }, [f.origen, refrescarAvisos]);

    const ejecutar = async () => {
        try {
            notificar((await api.post(`/auditoria/${accion.a.id}/${accion.tipo}`)).data.message);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
        setAccion(null);
    };

    const restaurarLote = async () => {
        setEnviando(true);
        try {
            const { data } = await api.post('/auditoria/restaurar-eliminados', {
                desde: lote.desde.replace('T', ' '), hasta: lote.hasta ? lote.hasta.replace('T', ' ') : undefined,
                tabla: lote.tabla || undefined, solo_directos: lote.solo_directos,
            });
            setResultado(data);
            setLote(null);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setEnviando(false);
        }
    };

    return (
        <>
            <Encabezado
                titulo="Auditoría de cambios"
                descripcion="Los triggers de la base de datos registran cada INSERT, UPDATE y DELETE, aunque se hagan directamente por SQL. En rojo, los cambios hechos fuera del sistema. Un borrado se puede restaurar y una modificación se puede deshacer."
            >
                {restaura && (
                    <button className="btn btn-primario" onClick={() => setLote({ desde: haceMinutos(60), hasta: '', tabla: '', solo_directos: true })}>
                        <History className="size-4" aria-hidden /> Restaurar eliminados
                    </button>
                )}
            </Encabezado>
            {avisos['/auditoria'] > 0 && f.origen !== 'directo' && (
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm text-peligro" role="alert">
                    <AlertTriangle className="size-5 shrink-0" aria-hidden />
                    <p className="min-w-0 flex-1 font-medium">
                        {avisos['/auditoria']} cambio(s) en las últimas 24 h se hicieron directamente en la base de datos, fuera del sistema. Revise si fueron autorizados.
                    </p>
                    <button className="btn btn-sm btn-peligro" onClick={() => setF({ ...f, origen: 'directo', desde: new Date(Date.now() - 86400000).toISOString().slice(0, 10) })}>
                        Ver solo esos
                    </button>
                </div>
            )}
            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <select className="campo w-auto" aria-label="Origen" value={f.origen} onChange={(e) => setF({ ...f, origen: e.target.value })}>
                        <option value="">Origen: todos</option>
                        <option value="directo">Solo cambios directos en BD</option>
                        <option value="sistema">Solo desde el sistema</option>
                    </select>
                    <select className="campo w-auto" aria-label="Tabla" value={f.tabla} onChange={(e) => setF({ ...f, tabla: e.target.value })}>
                        <option value="">Tabla: todas</option>
                        {TABLAS.map((t) => <option key={t}>{t}</option>)}
                    </select>
                    <select className="campo w-auto" aria-label="Operación" value={f.operacion} onChange={(e) => setF({ ...f, operacion: e.target.value })}>
                        <option value="">Operación: todas</option>
                        {['INSERT', 'UPDATE', 'DELETE'].map((t) => <option key={t}>{t}</option>)}
                    </select>
                    <label className="flex items-center gap-2 text-sm">Desde <input type="date" className="campo w-auto" value={f.desde} onChange={(e) => setF({ ...f, desde: e.target.value })} /></label>
                    <label className="flex items-center gap-2 text-sm">Hasta <input type="date" className="campo w-auto" value={f.hasta} onChange={(e) => setF({ ...f, hasta: e.target.value })} /></label>
                </div>
                {!datos ? <Cargando /> : !datos.data.length ? <Vacio texto="No hay cambios para ese filtro." /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Fecha</th><th>Tabla</th><th>Operación</th><th>Registro</th><th>Usuario del sistema</th><th>Usuario BD</th><th className="text-right">Acciones</th></tr></thead>
                            <tbody>
                                {datos.data.map((a) => (
                                    <tr key={a.id} className={a.usuario_app_id ? '' : 'bg-peligro-tenue/40'}>
                                        <td className="whitespace-nowrap">{fechaHora(a.fecha)}</td>
                                        <td><code>{a.tabla}</code></td>
                                        <td><Badge valor={a.operacion} /></td>
                                        <td>#{a.registro_id}</td>
                                        <td className={a.usuario_app_id ? '' : 'font-semibold text-peligro'}>{a.usuario_app_id ? a.usuario_app : <span className="flex items-center gap-1"><AlertTriangle className="size-4" aria-hidden /> Directo en BD</span>}</td>
                                        <td><code>{a.usuario_db}</code></td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <button className="btn btn-fantasma btn-sm" onClick={() => setDetalle(a)}>Ver cambios</button>
                                                {a.operacion === 'DELETE' && restaura && (
                                                    <button className="btn btn-fantasma btn-sm text-exito" onClick={() => setAccion({ tipo: 'restaurar', a })}>
                                                        <RotateCcw className="size-4" aria-hidden /> Restaurar
                                                    </button>
                                                )}
                                                {a.operacion === 'UPDATE' && restaura && (
                                                    <button className="btn btn-fantasma btn-sm text-alerta" onClick={() => setAccion({ tipo: 'deshacer', a })}>
                                                        <Undo2 className="size-4" aria-hidden /> Deshacer
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={datos} onPagina={setPagina} />
            </div>

            <Modal abierto={!!detalle} titulo={`${detalle?.operacion} en ${detalle?.tabla} #${detalle?.registro_id}`} onCerrar={() => setDetalle(null)} ancho="max-w-4xl">
                <div className="grid gap-4 md:grid-cols-2">
                    {['datos_antes', 'datos_despues'].map((k) => (
                        <div key={k}>
                            <p className="etiqueta">{k === 'datos_antes' ? 'Antes' : 'Después'}</p>
                            <pre className="max-h-96 overflow-auto rounded-tema bg-superficie-2 p-3 text-xs">
                                {detalle?.[k] ? Object.entries(detalle[k]).map(([c, val]) => {
                                    const cambio = detalle.datos_antes && detalle.datos_despues && JSON.stringify(detalle.datos_antes[c]) !== JSON.stringify(detalle.datos_despues[c]);
                                    return <div key={c} className={cambio ? 'font-bold text-alerta' : ''}>{c}: {JSON.stringify(val)}</div>;
                                }) : '—'}
                            </pre>
                        </div>
                    ))}
                </div>
            </Modal>
            <Confirmar
                abierto={!!accion}
                peligro={false}
                titulo={accion?.tipo === 'deshacer' ? 'Deshacer modificación' : 'Restaurar registro eliminado'}
                mensaje={accion?.tipo === 'deshacer'
                    ? `El registro #${accion?.a.registro_id} de ${accion?.a.tabla} volverá a los valores que tenía antes de este cambio (los campos marcados en "Ver cambios").`
                    : `Se volverá a insertar el registro #${accion?.a.registro_id} en la tabla ${accion?.a.tabla} con los datos que tenía antes de eliminarse.`}
                textoBoton={accion?.tipo === 'deshacer' ? 'Deshacer' : 'Restaurar'}
                onCerrar={() => setAccion(null)}
                onConfirmar={ejecutar}
            />

            <Modal abierto={!!lote} titulo="Restaurar registros eliminados" onCerrar={() => setLote(null)}>
                <p className="mb-4 text-sm text-suave">Vuelve a insertar todo lo que se eliminó en ese período (por ejemplo, si alguien borró datos por SQL). Primero los registros “padre” y después los que dependen de ellos.</p>
                {lote && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <label className="text-sm"><span className="etiqueta">Eliminados desde</span>
                            <input type="datetime-local" className="campo" value={lote.desde} onChange={(e) => setLote({ ...lote, desde: e.target.value })} />
                        </label>
                        <label className="text-sm"><span className="etiqueta">Hasta (opcional)</span>
                            <input type="datetime-local" className="campo" value={lote.hasta} onChange={(e) => setLote({ ...lote, hasta: e.target.value })} />
                        </label>
                        <label className="text-sm sm:col-span-2"><span className="etiqueta">Tabla</span>
                            <select className="campo" value={lote.tabla} onChange={(e) => setLote({ ...lote, tabla: e.target.value })}>
                                <option value="">Todas</option>
                                {TABLAS.filter((t) => t !== 'usuarios').map((t) => <option key={t}>{t}</option>)}
                            </select>
                        </label>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input type="checkbox" className="size-4 accent-[var(--c-primario)]" checked={lote.solo_directos} onChange={(e) => setLote({ ...lote, solo_directos: e.target.checked })} />
                            Solo lo eliminado directamente en la base de datos (fuera del sistema)
                        </label>
                    </div>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    <button className="btn btn-secundario" onClick={() => setLote(null)}>Cancelar</button>
                    <button className="btn btn-primario" disabled={enviando || !lote?.desde} onClick={restaurarLote}>
                        <RotateCcw className="size-4" aria-hidden /> {enviando ? 'Restaurando…' : 'Restaurar'}
                    </button>
                </div>
            </Modal>

            <Modal abierto={!!resultado} titulo="Resultado de la restauración" onCerrar={() => setResultado(null)}>
                <p className="font-medium">{resultado?.message}</p>
                {resultado && Object.keys(resultado.por_tabla ?? {}).length > 0 && (
                    <ul className="mt-3 list-disc pl-5 text-sm">{Object.entries(resultado.por_tabla).map(([t, n]) => <li key={t}><code>{t}</code>: {n}</li>)}</ul>
                )}
                {resultado?.errores?.length > 0 && (
                    <div className="mt-3 rounded-tema border border-alerta bg-alerta-tenue p-3 text-sm">
                        <p className="font-semibold">No se pudieron restaurar:</p>
                        <ul className="list-disc pl-5">{resultado.errores.map((e) => <li key={e}>{e}</li>)}</ul>
                    </div>
                )}
                <div className="mt-5 flex justify-end"><button className="btn btn-primario" onClick={() => setResultado(null)}>Cerrar</button></div>
            </Modal>
        </>
    );
}
