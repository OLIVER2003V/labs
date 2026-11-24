import { useState } from 'react';
import { Ban, Check, X } from 'lucide-react';
import CrudPage from '../componentes/CrudPage';
import { Badge, Confirmar, Formulario, Modal, useToast } from '../componentes/ui';
import api, { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { fecha, fechaHora, hora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';

const TIPOS = ['EXAMEN', 'RECUPERACION', 'PRACTICA', 'EVENTO', 'DEFENSA'];
const CAMPO_RECHAZO = [{ name: 'observacion', label: 'Motivo del rechazo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo' }];

/** Botones Aprobar / Rechazar (Encargado) y Cancelar (solicitante) */
function AccionesReserva({ fila, recargar }) {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const [rechazo, setRechazo] = useState(false);
    const [cancelar, setCancelar] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);

    const ejecutar = async (accion, datos) => {
        setEnviando(true);
        try {
            notificar((await api.put(`/reservas/${fila.id}/${accion}`, datos)).data.message);
            setRechazo(false);
            setCancelar(false);
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

    return (
        <>
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

export default function Reservas() {
    const { puede } = useAuth();
    return (
        <CrudPage
            titulo="Reservas"
            descripcion={puede('reservas.ver_todas')
                ? 'Solicitudes de uso puntual de los laboratorios. Al aprobar se verifica otra vez que no haya choques.'
                : 'Sus solicitudes de reserva. El Encargado de Laboratorios las aprueba o rechaza.'}
            endpoint="reservas"
            singular="reserva" femenino
            permisos={{ crear: 'reservas.crear' }}
            inicial={{ tipo: 'EXAMEN', fecha: hoyISO() }}
            filtros={[
                { name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'APROBADA', 'RECHAZADA', 'CANCELADA'] },
                { name: 'tipo', label: 'Tipo', opciones: TIPOS },
            ]}
            columnas={[
                { key: 'id', label: 'N°', render: (f) => `#${f.id}` },
                { key: 'fecha', label: 'Fecha y hora', render: (f) => <><strong>{fecha(f.fecha)}</strong><span className="block text-xs text-suave">{hora(f.hora_inicio)} – {hora(f.hora_fin)}</span></> },
                { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo },
                { key: 'motivo', label: 'Motivo', render: (f) => <>{f.motivo}<span className="block text-xs text-suave">{f.tipo}</span></> },
                { key: 'solicitante', label: 'Solicitante', render: (f) => <>{f.solicitante?.nombre_completo}<span className="block text-xs text-suave">{fechaHora(f.created_at)}</span></> },
                {
                    key: 'estado', label: 'Estado',
                    render: (f) => <><Badge valor={f.estado} />{f.observacion && <span className="mt-1 block max-w-56 text-xs text-suave">{f.observacion}</span>}{f.resolutor && <span className="block text-xs text-suave">por {f.resolutor.nombre}</span>}</>,
                },
            ]}
            acciones={(fila, recargar) => <AccionesReserva fila={fila} recargar={recargar} />}
            campos={[
                { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
                { name: 'tipo', label: 'Tipo', tipo: 'select', opciones: TIPOS, requerido: true },
                { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true, noPasado: true, validar: (v) => (new Date(`${v}T12:00`).getDay() === 0 ? 'Los laboratorios no atienden los domingos.' : null) },
                { name: 'hora_inicio', label: 'Hora de inicio', tipo: 'time', requerido: true, validar: (v) => (v < '07:00' ? 'Los laboratorios abren a las 07:00.' : null) },
                { name: 'hora_fin', label: 'Hora de fin', tipo: 'time', requerido: true, despuesDe: 'hora_inicio', validar: (v) => (v > '22:00' ? 'Los laboratorios cierran a las 22:00.' : null) },
                { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo', placeholder: 'Ej: Primer parcial INF513 grupo SA' },
            ]}
        />
    );
}
