import { useEffect, useState } from 'react';
import { Ban, Check, CheckCheck, Info, UserPlus, Users, UserX, X } from 'lucide-react';
import CrudPage from '../componentes/CrudPage';
import { Badge, Cargando, Confirmar, Formulario, Modal, useToast } from '../componentes/ui';
import api, { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { fecha, fechaHora, hora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';

/*
 * CU4 - Jerarquía de reservas (igual que App\Support\Prioridad en el servidor), de mayor a menor prioridad.
 * Un tipo de mayor prioridad puede ocupar el lugar de uno menor (incluidas las clases) pidiéndolo con 4 días de anticipación.
 */
export const TIPOS = [
    { value: 'HACKATHON', label: 'Hackathon', nivel: 1 },
    { value: 'CUP', label: 'CUP (curso preuniversitario)', nivel: 1 },
    { value: 'PSA', label: 'PSA (prueba de suficiencia académica)', nivel: 1 },
    { value: 'EXAMEN', label: 'Examen', nivel: 2 },
    { value: 'CAPACITACION', label: 'Capacitación externa', nivel: 3 },
    { value: 'CONFERENCIA', label: 'Conferencia', nivel: 3 },
    { value: 'PRACTICA', label: 'Práctica de auxiliar de cátedra', nivel: 5 },
];
export const nombreTipo = (t) => TIPOS.find((x) => x.value === t)?.label ?? t;
const AYUDA_JERARQUIA = 'Prioridad: 1) Hackathon, CUP, PSA · 2) Examen · 3) Capacitación, Conferencia · 4) Clases · 5) Prácticas de auxiliares. '
    + 'Si el horario está libre puede pedirlo cuando quiera; para ocupar el lugar de una clase o de algo de menor prioridad, pídalo con al menos 4 días de anticipación.';
const CAMPO_RECHAZO = [{ name: 'observacion', label: 'Motivo del rechazo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo' }];

/** Botones Aprobar / Rechazar (Encargado) y Cancelar (solicitante) */
function AccionesReserva({ fila, recargar }) {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const [rechazo, setRechazo] = useState(false);
    const [cancelar, setCancelar] = useState(false);
    const [noUso, setNoUso] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);

    const ejecutar = async (accion, datos) => {
        setEnviando(true);
        try {
            notificar((await api.put(`/reservas/${fila.id}/${accion}`, datos)).data.message);
            setRechazo(false);
            setCancelar(false);
            setNoUso(false);
            recargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const pendiente = fila.estado === 'PENDIENTE';
    const cancelable = ['PENDIENTE', 'APROBADA'].includes(fila.estado) && fila.fecha >= hoyISO()
        && (fila.solicitante_id === usuario.id || puede('reservas.aprobar')) && puede('reservas.cancelar');

    // CU9: después de la hora de inicio, el auxiliar/Encargado registra si se usó (si no se usó, multa al solicitante)
    const empezo = fila.estado === 'APROBADA' && new Date(`${fila.fecha}T${hora(fila.hora_inicio)}`) <= new Date();
    const registraUso = empezo && puede('reservas.registrar_uso') && fila.uso !== 'UTILIZADA';

    const conAuxiliares = fila.estado === 'APROBADA' && fila.auxiliares_solicitados > 0 && puede('reservas.aprobar') && fila.fecha >= hoyISO();
    const [verAux, setVerAux] = useState(false);

    return (
        <>
            {conAuxiliares && (
                <button className="btn btn-fantasma btn-sm" onClick={() => setVerAux(true)} title="Ver y asignar auxiliares">
                    <Users className="size-4" aria-hidden /> Auxiliares
                </button>
            )}
            {verAux && <ModalAuxiliares reservaId={fila.id} onCerrar={() => { setVerAux(false); recargar(); }} />}
            {registraUso && (
                <>
                    <button className="btn btn-fantasma btn-sm text-exito" disabled={enviando} onClick={() => ejecutar('uso', { uso: 'UTILIZADA' })} title="La reserva se utilizó">
                        <CheckCheck className="size-4" aria-hidden /> Se usó
                    </button>
                    {fila.uso !== 'NO_UTILIZADA' && (
                        <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setNoUso(true)} title="Nadie se presentó: genera multa">
                            <UserX className="size-4" aria-hidden /> No se presentó
                        </button>
                    )}
                </>
            )}
            {pendiente && puede('reservas.aprobar') && (
                <>
                    <button className="btn btn-fantasma btn-sm text-exito" disabled={enviando} onClick={() => ejecutar('aprobar')} title="Aprobar">
                        <Check className="size-4" aria-hidden /> Aprobar
                    </button>
                    <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => { setV({}); setErrores({}); setRechazo(true); }} title="Rechazar">
                        <X className="size-4" aria-hidden /> Rechazar
                    </button>
                </>
            )}
            {cancelable && (
                <button className="btn btn-fantasma btn-sm" onClick={() => setCancelar(true)} title="Cancelar reserva">
                    <Ban className="size-4" aria-hidden /> Cancelar
                </button>
            )}
            <Modal abierto={rechazo} titulo={`Rechazar reserva #${fila.id}`} onCerrar={() => setRechazo(false)}>
                <Formulario
                    campos={CAMPO_RECHAZO} valores={v} setValores={setV} errores={errores} enviando={enviando} textoBoton="Rechazar reserva"
                    onCancelar={() => setRechazo(false)}
                    onEnviar={() => {
                        const e = validar(v, CAMPO_RECHAZO);
                        setErrores(e);
                        if (!Object.keys(e).length) ejecutar('rechazar', v);
                    }}
                />
            </Modal>
            <Confirmar
                abierto={noUso}
                titulo={`Reserva #${fila.id} no utilizada`}
                mensaje={`Se registrará que nadie usó el ${fila.laboratorio?.codigo} el ${fecha(fila.fecha)} de ${hora(fila.hora_inicio)} a ${hora(fila.hora_fin)}, y se generará una multa a ${fila.solicitante?.nombre_completo}.`}
                textoBoton="Registrar y multar"
                onConfirmar={() => ejecutar('uso', { uso: 'NO_UTILIZADA' })}
                onCerrar={() => setNoUso(false)}
            />
            <Confirmar
                abierto={cancelar}
                titulo={`Cancelar reserva #${fila.id}`}
                mensaje={`Se liberará el ${fila.laboratorio?.codigo} el ${fecha(fila.fecha)} de ${hora(fila.hora_inicio)} a ${hora(fila.hora_fin)}.`}
                textoBoton="Cancelar reserva"
                onConfirmar={() => ejecutar('cancelar')}
                onCerrar={() => setCancelar(false)}
            />
        </>
    );
}

/** Cuántos auxiliares se pueden pedir en la fecha y horario del formulario (lo último consultado, para validar) */
const disponiblesAux = { clave: '', n: null };

function AyudaAuxiliares({ valores }) {
    const { fecha: f, hora_inicio: ini, hora_fin: fin } = valores;
    const clave = f && ini && fin && ini < fin ? `${f}|${ini}|${fin}` : '';
    const [n, setN] = useState(disponiblesAux.clave === clave ? disponiblesAux.n : null);
    useEffect(() => {
        if (!clave) return undefined;
        let vigente = true;
        const t = setTimeout(() => {
            api.get('/reservas/auxiliares-disponibles', { params: { fecha: f, hora_inicio: ini, hora_fin: fin } })
                .then((r) => {
                    disponiblesAux.clave = clave;
                    disponiblesAux.n = r.data.disponibles;
                    if (vigente) setN(r.data.disponibles);
                })
                .catch(() => {});
        }, 300);
        return () => {
            vigente = false;
            clearTimeout(t);
        };
    }, [clave]); // eslint-disable-line react-hooks/exhaustive-deps
    return (
        <>
            {clave && n !== null && <strong className="text-texto">Disponibles en ese horario: {n}. </strong>}
            {!clave && 'Elija primero la fecha y el horario para ver cuántos hay disponibles. '}
            Se asignan solos los que tienen turno a esa hora (si pide más de 5, se asignan 4 y el resto lo asigna el Encargado).
        </>
    );
}

/** CU4 - El Encargado ve los auxiliares asignados a una reserva y asigna los que faltan */
function ModalAuxiliares({ reservaId, onCerrar }) {
    const notificar = useToast();
    const [d, setD] = useState(null);
    const [elegido, setElegido] = useState('');
    const [enviando, setEnviando] = useState(false);
    const cargar = () => api.get(`/reservas/${reservaId}/auxiliares`).then((r) => setD(r.data)).catch((e) => notificar(mensajeError(e), 'error'));
    useEffect(() => {
        cargar();
    }, [reservaId]); // eslint-disable-line react-hooks/exhaustive-deps

    const accion = async (fn) => {
        setEnviando(true);
        try {
            notificar((await fn()).data.message);
            setElegido('');
            await cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setEnviando(false);
        }
    };
    const faltan = d ? Math.max(0, d.reserva.solicitados - d.asignados.length) : 0;

    return (
        <Modal abierto titulo={`Auxiliares de la reserva #${reservaId}`} onCerrar={onCerrar} ancho="max-w-xl">
            {!d ? <Cargando /> : (
                <>
                    <p className="text-sm text-suave">{d.reserva.descripcion}</p>
                    <p className={`mt-2 rounded-tema border p-2 text-sm font-medium ${faltan ? 'border-alerta bg-alerta-tenue' : 'border-exito bg-exito-tenue'}`}>
                        Pidió {d.reserva.solicitados} · asignados {d.asignados.length}{faltan ? ` · faltan ${faltan}` : ' · completo'}
                    </p>

                    <h3 className="mt-4 mb-1 text-sm font-semibold">Asignados</h3>
                    {!d.asignados.length ? <p className="text-sm text-suave">Ninguno todavía.</p> : (
                        <ul className="divide-y divide-borde rounded-tema border border-borde">
                            {d.asignados.map((a) => (
                                <li key={a.id} className="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                    <span className="min-w-0">{a.nombre} <span className="text-xs text-suave">({a.codigo}) · {a.automatico ? 'por turno (automático)' : 'asignado a mano'}</span></span>
                                    <button className="btn btn-fantasma btn-sm text-peligro" disabled={enviando} onClick={() => accion(() => api.delete(`/reservas/${reservaId}/auxiliares/${a.id}`))} title={`Quitar a ${a.nombre}`}>
                                        <X className="size-4" aria-hidden /> Quitar
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    <h3 className="mt-4 mb-1 text-sm font-semibold">Asignar otro auxiliar</h3>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <select className="campo min-w-0 flex-1" aria-label="Auxiliar a asignar" value={elegido} onChange={(e) => setElegido(e.target.value)}>
                            <option value="">— Seleccione —</option>
                            {d.con_turno.length > 0 && (
                                <optgroup label="Con turno a esa hora (recomendados)">
                                    {d.con_turno.map((a) => <option key={a.id} value={a.id}>{a.nombre} · turno {a.turno}{a.cubre ? '' : ' (parcial)'}</option>)}
                                </optgroup>
                            )}
                            <optgroup label="Otros auxiliares">
                                {d.otros.map((a) => <option key={a.id} value={a.id} disabled={a.ocupado}>{a.nombre}{a.ocupado ? ' (apoya otra reserva a esa hora)' : ''}</option>)}
                            </optgroup>
                        </select>
                        <button className="btn btn-primario" disabled={!elegido || enviando} onClick={() => accion(() => api.post(`/reservas/${reservaId}/auxiliares`, { auxiliar_id: Number(elegido) }))}>
                            <UserPlus className="size-4" aria-hidden /> Asignar
                        </button>
                    </div>
                    <p className="mt-2 text-xs text-suave">Al auxiliar se le envía una notificación con la fecha, el laboratorio y el horario.</p>
                </>
            )}
        </Modal>
    );
}

/** CU4 - Interruptor del Encargado: aprobar a mano o automáticamente las solicitudes de docentes y director */
function InterruptorAprobacion() {
    const notificar = useToast();
    const [auto, setAuto] = useState(null);
    useEffect(() => {
        api.get('/reservas/configuracion').then((r) => setAuto(r.data.aprobacion_automatica)).catch(() => {});
    }, []);
    if (auto === null) return null;
    const cambiar = async (valor) => {
        try {
            const { data } = await api.put('/reservas/configuracion', { aprobacion_automatica: valor });
            setAuto(data.aprobacion_automatica);
            notificar(data.message);
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };
    return (
        <label className="flex cursor-pointer items-center gap-2 rounded-tema border border-borde px-3 py-2 text-sm" title="Docentes y director: aprobación manual o automática (siempre respetando la jerarquía)">
            <input type="checkbox" role="switch" className="size-5 accent-[var(--c-primario)]" checked={auto} onChange={(e) => cambiar(e.target.checked)} />
            <span>Aprobación automática <span className="block text-xs text-suave">{auto ? 'Activa: docentes y director' : 'Manual: usted aprueba'}</span></span>
        </label>
    );
}

export default function Reservas() {
    const { puede, avisos } = useAuth();
    const aprueba = puede('reservas.aprobar');
    const pendientes = avisos?.['/reservas'] ?? 0;
    return (
        <CrudPage
            titulo="Reservas"
            descripcion={puede('reservas.ver_todas')
                ? 'Solicitudes de uso puntual de los laboratorios. Al aprobar se aplica la jerarquía: lo de mayor prioridad desplaza a las clases y reservas menores (se avisa a cada uno).'
                : 'Sus solicitudes de reserva y las que le toca apoyar como auxiliar. El Encargado de Laboratorios las aprueba o rechaza.'}
            botonesExtra={aprueba ? () => <InterruptorAprobacion /> : undefined}
            aviso={aprueba && pendientes > 0 ? { texto: `${pendientes} reserva(s) esperan su acción (solicitudes por aprobar o auxiliares por asignar).`, filtro: { estado: 'PENDIENTE' } } : null}
            resaltar={aprueba ? (f) => (f.estado === 'PENDIENTE' ? 'Por aprobar' : f.estado === 'APROBADA' && f.auxiliares_solicitados > (f.auxiliares?.length ?? 0) && f.fecha >= hoyISO() ? 'Faltan auxiliares' : null) : undefined}
            endpoint="reservas"
            singular="reserva" femenino
            permisos={{ crear: 'reservas.crear' }}
            inicial={{ tipo: 'EXAMEN', fecha: hoyISO() }}
            filtros={[
                { name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'APROBADA', 'RECHAZADA', 'CANCELADA'] },
                { name: 'uso', label: 'Uso', opciones: [{ value: 'UTILIZADA', label: 'Utilizada' }, { value: 'NO_UTILIZADA', label: 'No utilizada' }] },
                { name: 'tipo', label: 'Tipo', opciones: TIPOS },
            ]}
            columnas={[
                { key: 'id', label: 'N°', render: (f) => `#${f.id}` },
                { key: 'fecha', label: 'Fecha y hora', render: (f) => <><strong>{fecha(f.fecha)}</strong><span className="block text-xs text-suave">{hora(f.hora_inicio)} – {hora(f.hora_fin)}</span></> },
                { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo },
                { key: 'motivo', label: 'Motivo', render: (f) => <>{f.motivo}<span className="block text-xs text-suave">{nombreTipo(f.tipo)}</span></> },
                {
                    key: 'auxiliares', label: 'Auxiliares',
                    render: (f) => (f.auxiliares_solicitados > 0 ? (
                        <>
                            <span className={`text-xs font-semibold ${(f.auxiliares?.length ?? 0) < f.auxiliares_solicitados ? 'text-alerta' : 'text-exito'}`}>{f.auxiliares?.length ?? 0} de {f.auxiliares_solicitados}</span>
                            {f.auxiliares?.map((a) => <span key={a.id} className="block text-xs text-suave">{a.nombre} {a.apellido}</span>)}
                        </>
                    ) : <span className="text-xs text-suave">—</span>),
                },
                { key: 'solicitante', label: 'Solicitante', render: (f) => <>{f.solicitante?.nombre_completo}<span className="block text-xs text-suave">{fechaHora(f.created_at)}</span></> },
                {
                    key: 'estado', label: 'Estado',
                    render: (f) => <><Badge valor={f.estado} />{f.uso && <span className={`ml-1 text-xs font-semibold ${f.uso === 'UTILIZADA' ? 'text-exito' : 'text-peligro'}`}>{f.uso === 'UTILIZADA' ? '✓ utilizada' : '✗ no utilizada'}</span>}{f.observacion && <span className="mt-1 block max-w-56 text-xs text-suave">{f.observacion}</span>}{f.resolutor && <span className="block text-xs text-suave">por {f.resolutor.nombre}</span>}</>,
                },
            ]}
            acciones={(fila, recargar) => <AccionesReserva fila={fila} recargar={recargar} />}
            campos={[
                { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
                { name: 'tipo', label: 'Tipo (prioridad)', tipo: 'select', opciones: TIPOS.map((t) => ({ value: t.value, label: `${t.nivel}. ${t.label}` })), requerido: true, ancho: 'completo', ayuda: AYUDA_JERARQUIA },
                { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true, noPasado: true, validar: (v) => (new Date(`${v}T12:00`).getDay() === 0 ? 'Los laboratorios no atienden los domingos.' : null) },
                { name: 'hora_inicio', label: 'Hora de inicio', tipo: 'time', requerido: true, validar: (v) => (v < '07:00' ? 'Los laboratorios abren a las 07:00.' : null) },
                { name: 'hora_fin', label: 'Hora de fin', tipo: 'time', requerido: true, despuesDe: 'hora_inicio', validar: (v) => (v > '22:00' ? 'Los laboratorios cierran a las 22:00.' : null) },
                { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo', placeholder: 'Ej: Primer parcial INF513 grupo SA' },
                ...(puede('reservas.pedir_auxiliares') ? [{
                    name: 'auxiliares_solicitados', label: 'Auxiliares de apoyo (opcional)', tipo: 'number', min: 0, max: 20, entero: true,
                    ayuda: (valores) => <AyudaAuxiliares valores={valores} />,
                    validar: (v, valores) => {
                        const clave = `${valores.fecha}|${valores.hora_inicio}|${valores.hora_fin}`;
                        return Number(v) > 0 && disponiblesAux.clave === clave && Number(v) > disponiblesAux.n
                            ? `Solo hay ${disponiblesAux.n} auxiliar(es) disponible(s) en ese horario.` : null;
                    },
                }] : []),
            ]}
        />
    );
}
