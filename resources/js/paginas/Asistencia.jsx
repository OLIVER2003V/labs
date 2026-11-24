import { useEffect, useState } from 'react';
import { CalendarX2, FileCheck2, LogIn, LogOut } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import useListado from '../lib/useListado';
import { DIAS, fecha, hora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import { Badge, Cargando, Encabezado, Formulario, Modal, Paginacion, Tarjeta, useOpciones, useToast, Vacio } from '../componentes/ui';

const CAMPOS_PERMISO = [
    { name: 'turno_id', label: 'Turno', tipo: 'select', opciones: 'turnos', requerido: true, ancho: 'completo' },
    { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true },
    { name: 'observacion', label: 'Motivo del permiso', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo' },
];
const CAMPOS_FALTAS = [{ name: 'fecha', label: 'Fecha a revisar', tipo: 'date', requerido: true, noFuturo: true }];

export default function Asistencia() {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const gestiona = puede('asistencia.gestionar');
    const auxiliares = useOpciones(gestiona ? 'auxiliares' : null);
    const [f, setF] = useState({ auxiliar_id: '', estado: '', desde: '', hasta: '' });
    const { datos, setPagina, cargar } = useListado('/asistencias', Object.fromEntries(Object.entries(f).filter(([, v]) => v)));
    const [misTurnos, setMisTurnos] = useState(null);
    const [modal, setModal] = useState(null); // 'permiso' | 'faltas'
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [reloj, setReloj] = useState(new Date());

    useEffect(() => {
        const t = setInterval(() => setReloj(new Date()), 1000);
        return () => clearInterval(t);
    }, []);

    useEffect(() => {
        if (puede('asistencia.marcar')) {
            api.get('/turnos', { params: { per_page: 50 } }).then((r) => setMisTurnos(r.data.data)).catch(() => setMisTurnos([]));
        }
    }, [puede]);

    const marcar = async (tipo) => {
        setEnviando(true);
        try {
            notificar((await api.post(`/asistencias/${tipo}`)).data.message);
            cargar();
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
            cargar();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const diaHoy = reloj.getDay() || 7;
    const turnosHoy = misTurnos?.filter((t) => t.dia_semana === diaHoy && t.auxiliar_id === usuario.id) ?? [];

    return (
        <>
            <Encabezado titulo="Asistencia de auxiliares" descripcion="Entrada puntual hasta 10 minutos después del inicio del turno; luego se registra como atraso.">
                {gestiona && (
                    <>
                        <button className="btn btn-secundario" onClick={() => { setV({ fecha: hoyISO() }); setErrores({}); setModal('permiso'); }}>
                            <FileCheck2 className="size-4" aria-hidden /> Registrar permiso
                        </button>
                        <button className="btn btn-secundario" onClick={() => { setV({ fecha: hoyISO() }); setErrores({}); setModal('faltas'); }}>
                            <CalendarX2 className="size-4" aria-hidden /> Marcar faltas
                        </button>
                    </>
                )}
            </Encabezado>

            {puede('asistencia.marcar') && (
                <Tarjeta className="mb-5">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <p className="text-sm text-suave">{DIAS[diaHoy]} {fecha(hoyISO())}</p>
                            <p className="font-mono text-4xl font-bold" aria-live="off">{reloj.toLocaleTimeString('es-BO')}</p>
                            <p className="mt-1 text-sm">
                                {misTurnos === null ? 'Cargando turnos…' : turnosHoy.length
                                    ? `Turno(s) de hoy: ${turnosHoy.map((t) => `${hora(t.hora_inicio)}–${hora(t.hora_fin)} ${t.laboratorio?.codigo ?? ''}`).join(', ')}`
                                    : 'No tiene turnos asignados para hoy.'}
                            </p>
                        </div>
                        <div className="flex gap-3">
                            <button className="btn btn-exito px-6 py-3 text-lg" disabled={enviando} onClick={() => marcar('entrada')}>
                                <LogIn className="size-6" aria-hidden /> Marcar entrada
                            </button>
                            <button className="btn btn-secundario px-6 py-3 text-lg" disabled={enviando} onClick={() => marcar('salida')}>
                                <LogOut className="size-6" aria-hidden /> Marcar salida
                            </button>
                        </div>
                    </div>
                </Tarjeta>
            )}

            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    {gestiona && (
                        <select className="campo w-auto" aria-label="Auxiliar" value={f.auxiliar_id} onChange={(e) => setF({ ...f, auxiliar_id: e.target.value })}>
                            <option value="">Auxiliar: todos</option>
                            {auxiliares.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                        </select>
                    )}
                    <select className="campo w-auto" aria-label="Estado" value={f.estado} onChange={(e) => setF({ ...f, estado: e.target.value })}>
                        <option value="">Estado: todos</option>
                        {['PUNTUAL', 'ATRASO', 'FALTA', 'PERMISO'].map((x) => <option key={x}>{x}</option>)}
                    </select>
                    <label className="flex items-center gap-2 text-sm">Desde <input type="date" className="campo w-auto" value={f.desde} onChange={(e) => setF({ ...f, desde: e.target.value })} /></label>
                    <label className="flex items-center gap-2 text-sm">Hasta <input type="date" className="campo w-auto" value={f.hasta} onChange={(e) => setF({ ...f, hasta: e.target.value })} /></label>
                </div>
                {!datos ? <Cargando /> : !datos.data.length ? <Vacio /> : (
                    <div className="overflow-x-auto">
                        <table className="tabla w-full text-sm">
                            <thead><tr><th>Fecha</th><th>Auxiliar</th><th>Turno</th><th>Entrada</th><th>Salida</th><th>Estado</th><th>Observación</th></tr></thead>
                            <tbody>
                                {datos.data.map((a) => (
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
                )}
                <Paginacion datos={datos} onPagina={setPagina} />
            </div>

            <Modal abierto={!!modal} titulo={modal === 'permiso' ? 'Registrar permiso justificado' : 'Marcar faltas del día'} onCerrar={() => setModal(null)}>
                {modal === 'faltas' && <p className="mb-4 text-sm text-suave">Se registrará FALTA a cada turno de esa fecha que ya terminó y no tiene registro de entrada ni permiso.</p>}
                <Formulario
                    campos={modal === 'permiso' ? CAMPOS_PERMISO : CAMPOS_FALTAS} valores={v} setValores={setV} errores={errores}
                    onEnviar={enviarModal} enviando={enviando} onCancelar={() => setModal(null)} textoBoton={modal === 'permiso' ? 'Registrar permiso' : 'Marcar faltas'}
                />
            </Modal>
        </>
    );
}
