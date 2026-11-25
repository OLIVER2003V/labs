import { useEffect, useState } from 'react';
import { RotateCcw, Search } from 'lucide-react';
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

const TABLAS = ['usuarios', 'roles', 'rol_permiso', 'laboratorios', 'semestres', 'materias', 'horarios', 'reservas', 'equipos', 'incidencias',
    'objetos_encontrados', 'turnos', 'asistencias', 'conceptos_pago', 'planes_pago', 'cuotas', 'transacciones', 'metodos_pago'];

/** Auditoría: cambios capturados por los TRIGGERS de PostgreSQL (incluye cambios hechos por SQL directo) */
export function Auditoria() {
    const { puede } = useAuth();
    const notificar = useToast();
    const [f, setF] = useState({ tabla: '', operacion: '' });
    const filtros = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    const { datos, setPagina, cargar } = useListado('/auditoria', filtros);
    const [detalle, setDetalle] = useState(null);
    const [restaurar, setRestaurar] = useState(null);

    return (
        <>
            <Encabezado
                titulo="Auditoría de cambios"
                descripcion="Los triggers de la base de datos registran cada INSERT, UPDATE y DELETE, aunque se hagan directamente por SQL. Un registro eliminado puede restaurarse desde aquí."
            />
            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <select className="campo w-auto" aria-label="Tabla" value={f.tabla} onChange={(e) => setF({ ...f, tabla: e.target.value })}>
                        <option value="">Tabla: todas</option>
                        {TABLAS.map((t) => <option key={t}>{t}</option>)}
                    </select>
                    <select className="campo w-auto" aria-label="Operación" value={f.operacion} onChange={(e) => setF({ ...f, operacion: e.target.value })}>
                        <option value="">Operación: todas</option>
                        {['INSERT', 'UPDATE', 'DELETE'].map((t) => <option key={t}>{t}</option>)}
                    </select>
                </div>
                {!datos ? <Cargando /> : !datos.data.length ? <Vacio texto="Aún no hay cambios registrados." /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Fecha</th><th>Tabla</th><th>Operación</th><th>Registro</th><th>Usuario del sistema</th><th>Usuario BD</th><th className="text-right">Acciones</th></tr></thead>
                            <tbody>
                                {datos.data.map((a) => (
                                    <tr key={a.id}>
                                        <td className="whitespace-nowrap">{fechaHora(a.fecha)}</td>
                                        <td><code>{a.tabla}</code></td>
                                        <td><Badge valor={a.operacion} /></td>
                                        <td>#{a.registro_id}</td>
                                        <td className={a.usuario_app_id ? '' : 'font-semibold text-peligro'}>{a.usuario_app}</td>
                                        <td><code>{a.usuario_db}</code></td>
                                        <td>
                                            <div className="flex justify-end gap-1">
                                                <button className="btn btn-fantasma btn-sm" onClick={() => setDetalle(a)}>Ver cambios</button>
                                                {a.operacion === 'DELETE' && puede('auditoria.restaurar') && (
                                                    <button className="btn btn-fantasma btn-sm text-exito" onClick={() => setRestaurar(a)}>
                                                        <RotateCcw className="size-4" aria-hidden /> Restaurar
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
                abierto={!!restaurar}
                peligro={false}
                titulo="Restaurar registro eliminado"
                mensaje={`Se volverá a insertar el registro #${restaurar?.registro_id} en la tabla ${restaurar?.tabla} con los datos que tenía antes de eliminarse.`}
                textoBoton="Restaurar"
                onCerrar={() => setRestaurar(null)}
                onConfirmar={async () => {
                    try {
                        notificar((await api.post(`/auditoria/${restaurar.id}/restaurar`)).data.message);
                        cargar();
                    } catch (e) {
                        notificar(mensajeError(e), 'error');
                    }
                    setRestaurar(null);
                }}
            />
        </>
    );
}
