import { useCallback, useEffect, useState } from 'react';
import { ArrowLeftRight, CalendarX2, FileCheck2, LogIn, LogOut, Trash2 } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import useListado from '../lib/useListado';
import { DIAS, fecha, fechaHora, hora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import { Badge, Cargando, Confirmar, Encabezado, Formulario, Modal, Paginacion, Tarjeta, useOpciones, useToast, Vacio } from '../componentes/ui';

/*
 * CU7 - Asistencia de auxiliares.
 * Reglas (las aplica el servidor, App\Services\ControlAsistencia):
 *   entrada desde 15 min antes · PUNTUAL hasta 10 min después · ATRASO de 11 a 30 min · pasados 30 min = FALTA
 *   Un permiso del Encargado justifica TODOS los turnos del auxiliar ese día (y convierte sus faltas en PERMISO).
 */
const CAMPOS_PERMISO = [
    { name: 'auxiliar_id', label: 'Auxiliar', tipo: 'select', opciones: 'auxiliares', requerido: true, ancho: 'completo' },
    { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true, ayuda: 'Justifica todos sus turnos de ese día. Puede ser una fecha pasada (hasta 60 días) o futura.' },
    { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo', placeholder: 'Ej: Examen final de Base de Datos II' },
];
const CAMPOS_FALTAS = [{ name: 'fecha', label: 'Fecha a revisar', tipo: 'date', requerido: true, noFuturo: true }];

/** Tarjeta de un turno de hoy: horario, ventana para marcar y lo que ya se registró */
function TurnoHoy({ t }) {
    return (
        <li className="rounded-tema border border-borde p-3">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
                {t.hora_inicio}–{t.hora_fin} · {t.laboratorio}
                {t.es_permuta && <span className="flex items-center gap-1 rounded-full bg-info-tenue px-2 py-0.5 text-xs text-info"><ArrowLeftRight className="size-3" aria-hidden /> Cubre por permuta</span>}
            </p>
            {t.asistencia ? (
                <p className="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <Badge valor={t.asistencia.estado} />
                    {t.asistencia.entrada && <span>Entrada {t.asistencia.entrada}</span>}
                    {t.asistencia.salida && <span>· Salida {t.asistencia.salida}</span>}
                </p>
            ) : (
                <p className="mt-1 text-sm text-suave">Marque entre las {t.desde} y las {t.limite} · puntual hasta las {t.puntual_hasta}</p>
            )}
        </li>
    );
}

export default function Asistencia() {
    const { puede } = useAuth();
    const notificar = useToast();
    const gestiona = puede('asistencia.gestionar');
    const marca = puede('asistencia.marcar');
    const auxiliares = useOpciones(gestiona ? 'auxiliares' : null);
    const [pestana, setPestana] = useState('asistencia');
    const [f, setF] = useState({ auxiliar_id: '', estado: '', desde: '', hasta: '' });
    const filtros = Object.fromEntries(Object.entries(f).filter(([, v]) => v));
    const asistencias = useListado('/asistencias', filtros);
    const permisos = useListado('/asistencias/permisos', f.auxiliar_id ? { auxiliar_id: f.auxiliar_id } : {});
    const [hoy, setHoy] = useState(null);
    const [modal, setModal] = useState(null); // 'permiso' | 'faltas'
    const [anular, setAnular] = useState(null);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [reloj, setReloj] = useState(new Date());

    useEffect(() => {
        const t = setInterval(() => setReloj(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    const cargarHoy = useCallback(() => {
        if (marca) api.get('/asistencias/hoy').then((r) => setHoy(r.data)).catch(() => setHoy([]));
    }, [marca]);
    useEffect(() => {
        cargarHoy();
        const t = setInterval(cargarHoy, 60000); // la ventana de marcado cambia con la hora
        return () => clearInterval(t);
    }, [cargarHoy]);

    const recargarTodo = () => {
        asistencias.cargar();
        permisos.cargar();
        cargarHoy();
    };

    const marcar = async (tipo) => {
        setEnviando(true);
        try {
            const { data } = await api.post(`/asistencias/${tipo}`);
            notificar(data.message, data.anticipada ? 'alerta' : undefined);
            recargarTodo();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const enviarModal = async () => {
        const campos = modal === 'permiso' ? CAMPOS_PERMISO : CAMPOS_FALTAS;
        const e = validar(v, campos);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            notificar((await api.post(`/asistencias/${modal}`, v)).data.message);
            setModal(null);
            recargarTodo();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const anularPermiso = async () => {
        try {
            notificar((await api.delete(`/asistencias/permisos/${anular.id}`)).data.message);
            setAnular(null);
            permisos.cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    const diaHoy = reloj.getDay() || 7;
    const puedeEntrar = hoy?.some((t) => t.puede_entrar);
    const puedeSalir = hoy?.some((t) => t.puede_salir);
    const listado = pestana === 'asistencia' ? asistencias : permisos;

    return (
        <>
            <Encabezado titulo="Asistencia de auxiliares" descripcion="Entrada desde 15 min antes del turno: puntual hasta 10 min después, atraso hasta 30 min. Pasados 30 min sin marcar se registra falta (o permiso, si el Encargado lo registró).">
                {gestiona && (
                    <>
                        <button className="btn btn-secundario" onClick={() => { setV({ fecha: hoyISO() }); setErrores({}); setModal('permiso'); }}>
                            <FileCheck2 className="size-4" aria-hidden /> Registrar permiso
                        </button>
                        <button className="btn btn-secundario" onClick={() => { setV({ fecha: hoyISO() }); setErrores({}); setModal('faltas'); }}>
                            <CalendarX2 className="size-4" aria-hidden /> Revisar faltas de un día
                        </button>
                    </>
                )}
            </Encabezado>

            {marca && (
                <Tarjeta className="mb-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="min-w-0 flex-1">
                            <p className="text-sm text-suave">{DIAS[diaHoy]} {fecha(hoyISO())}</p>
                            <p className="font-mono text-4xl font-bold" aria-live="off">{reloj.toLocaleTimeString('es-BO')}</p>
                            {hoy === null ? <p className="mt-2 text-sm text-suave">Cargando turnos…</p> : hoy.length ? (
                                <ul className="mt-3 grid gap-2 sm:grid-cols-2">{hoy.map((t) => <TurnoHoy key={t.turno_id} t={t} />)}</ul>
                            ) : <p className="mt-2 text-sm">No tiene turnos para hoy.</p>}
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <button className="btn btn-exito px-6 py-3 text-lg" disabled={enviando || !puedeEntrar} onClick={() => marcar('entrada')} title={puedeEntrar ? '' : 'Fuera de la ventana para marcar entrada'}>
                                <LogIn className="size-6" aria-hidden /> Marcar entrada
                            </button>
                            <button className="btn btn-secundario px-6 py-3 text-lg" disabled={enviando || !puedeSalir} onClick={() => marcar('salida')}>
                                <LogOut className="size-6" aria-hidden /> Marcar salida
                            </button>
                        </div>
                    </div>
                </Tarjeta>
            )}

            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap items-center gap-3 border-b border-borde p-3">
                    <div className="flex rounded-tema border border-borde p-0.5" role="tablist">
                        {[['asistencia', 'Registros'], ['permisos', 'Permisos']].map(([k, l]) => (
                            <button key={k} role="tab" aria-selected={pestana === k}
                                className={`rounded-tema px-3 py-1.5 text-sm font-medium ${pestana === k ? 'bg-primario text-white' : 'hover:bg-superficie-2'}`}
                                onClick={() => setPestana(k)}>{l}</button>
                        ))}
                    </div>
                    {gestiona && (
                        <select className="campo w-auto" aria-label="Auxiliar" value={f.auxiliar_id} onChange={(e) => setF({ ...f, auxiliar_id: e.target.value })}>
                            <option value="">Auxiliar: todos</option>
                            {auxiliares.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                        </select>
                    )}
                    {pestana === 'asistencia' && (
                        <>
                            <select className="campo w-auto" aria-label="Estado" value={f.estado} onChange={(e) => setF({ ...f, estado: e.target.value })}>
                                <option value="">Estado: todos</option>
                                {['PUNTUAL', 'ATRASO', 'FALTA', 'PERMISO'].map((x) => <option key={x}>{x}</option>)}
                            </select>
                            <label className="flex items-center gap-2 text-sm">Desde <input type="date" className="campo w-auto" value={f.desde} onChange={(e) => setF({ ...f, desde: e.target.value })} /></label>
                            <label className="flex items-center gap-2 text-sm">Hasta <input type="date" className="campo w-auto" value={f.hasta} onChange={(e) => setF({ ...f, hasta: e.target.value })} /></label>
                        </>
                    )}
                </div>
                {!listado.datos ? <Cargando /> : !listado.datos.data.length ? <Vacio /> : pestana === 'asistencia' ? (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Fecha</th><th>Auxiliar</th><th>Turno</th><th>Entrada</th><th>Salida</th><th>Estado</th><th>Observación</th></tr></thead>
                            <tbody>
                                {asistencias.datos.data.map((a) => (
                                    <tr key={a.id}>
                                        <td>{DIAS[new Date(`${a.fecha}T12:00`).getDay() || 7].slice(0, 3)} {fecha(a.fecha)}</td>
                                        <td>{a.auxiliar?.nombre_completo}</td>
                                        <td>{hora(a.turno?.hora_inicio)}–{hora(a.turno?.hora_fin)} <span className="text-suave">{a.turno?.laboratorio?.codigo}</span></td>
                                        <td>{hora(a.hora_entrada)}</td>
                                        <td>{hora(a.hora_salida)}</td>
                                        <td><Badge valor={a.estado} /></td>
                                        <td className="text-xs">{a.observacion ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Fecha</th><th>Auxiliar</th><th>Motivo</th><th>Registrado por</th>{gestiona && <th className="text-right">Acciones</th>}</tr></thead>
                            <tbody>
                                {permisos.datos.data.map((p) => (
                                    <tr key={p.id}>
                                        <td><strong>{DIAS[new Date(`${p.fecha}T12:00`).getDay() || 7].slice(0, 3)} {fecha(p.fecha)}</strong></td>
                                        <td>{p.auxiliar?.nombre_completo}</td>
                                        <td>{p.motivo}</td>
                                        <td>{p.registrador?.nombre_completo}<span className="block text-xs text-suave">{fechaHora(p.created_at)}</span></td>
                                        {gestiona && (
                                            <td className="text-right">
                                                {p.fecha > hoyISO() && (
                                                    <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setAnular(p)} aria-label={`Anular permiso del ${fecha(p.fecha)}`}>
                                                        <Trash2 className="size-4" aria-hidden /> Anular
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={listado.datos} onPagina={listado.setPagina} />
            </div>

            <Modal abierto={!!modal} titulo={modal === 'permiso' ? 'Registrar permiso justificado' : 'Revisar faltas de un día'} onCerrar={() => setModal(null)}>
                {modal === 'faltas' && <p className="mb-4 text-sm text-suave">Las faltas se registran solas. Use esto para un día en que nadie abrió el sistema: cada turno de esa fecha sin entrada quedará como FALTA (o PERMISO si tenía permiso). No cuenta feriados.</p>}
                <Formulario
                    campos={modal === 'permiso' ? CAMPOS_PERMISO : CAMPOS_FALTAS} valores={v} setValores={setV} errores={errores}
                    onEnviar={enviarModal} enviando={enviando} onCancelar={() => setModal(null)} textoBoton={modal === 'permiso' ? 'Registrar permiso' : 'Revisar faltas'}
                />
            </Modal>
            <Confirmar
                abierto={!!anular}
                titulo="Anular permiso"
                mensaje={anular ? `Se anulará el permiso de ${anular.auxiliar?.nombre_completo} para el ${fecha(anular.fecha)}.` : ''}
                textoBoton="Anular permiso"
                onConfirmar={anularPermiso}
                onCerrar={() => setAnular(null)}
            />
        </>
    );
}
