import { useState } from 'react';
import { ArrowRight, Ban, Check, CheckCheck, X } from 'lucide-react';
import CrudPage from '../componentes/CrudPage';
import { Badge, Confirmar, Formulario, Modal, useOpciones, useToast } from '../componentes/ui';
import api, { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { DIAS, fecha, fechaHora, hora, hoyISO } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';

/*
 * CU5 - Permutas de turno. Flujo de 3 fases:
 *   1. el titular solicita (PENDIENTE) → 2. el reemplazante acepta (ACEPTADA) → 3. el Encargado aprueba (APROBADA)
 * Los botones de cada fila dependen de QUIÉN mira y del ESTADO; el servidor vuelve a validar todo.
 */
const CAMPO_MOTIVO = (requerido) => [{ name: 'observacion', label: requerido ? 'Motivo del rechazo' : 'Comentario (opcional)', tipo: 'textarea', requerido, min: requerido ? 5 : undefined, max: 200, ancho: 'completo' }];

function AccionesPermuta({ fila, recargar }) {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const [dialogo, setDialogo] = useState(null); // 'rechazar-reemplazante' | 'rechazar-encargado' | 'cancelar'
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);

    const ejecutar = async (accion, datos) => {
        setEnviando(true);
        try {
            notificar((await api.put(`/permutas/${fila.id}/${accion}`, datos)).data.message);
            setDialogo(null);
            recargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const futura = fila.fecha >= hoyISO();
    const soyReemplazante = fila.reemplazante_id === usuario.id;
    const soySolicitante = fila.solicitante_id === usuario.id;
    const encargado = puede('permutas.aprobar');
    const abrir = (d) => { setV({}); setErrores({}); setDialogo(d); };

    const puedeResponder = futura && soyReemplazante && fila.estado === 'PENDIENTE';
    const puedeAprobar = futura && encargado && fila.estado === 'ACEPTADA';
    const puedeRechazar = encargado && ['PENDIENTE', 'ACEPTADA'].includes(fila.estado);
    const puedeCancelar = futura && ((soySolicitante && ['PENDIENTE', 'ACEPTADA'].includes(fila.estado)) || (encargado && fila.estado === 'APROBADA'));
    const rechazo = dialogo?.startsWith('rechazar');
    const camposRechazo = CAMPO_MOTIVO(dialogo === 'rechazar-encargado');

    return (
        <>
            {puedeResponder && (
                <>
                    <button className="btn btn-fantasma btn-sm text-exito" disabled={enviando} onClick={() => ejecutar('responder', { aceptar: true })} title="Aceptar cubrir el turno">
                        <Check className="size-4" aria-hidden /> Aceptar
                    </button>
                    <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => abrir('rechazar-reemplazante')} title="No puedo cubrirlo">
                        <X className="size-4" aria-hidden /> No puedo
                    </button>
                </>
            )}
            {puedeAprobar && (
                <button className="btn btn-fantasma btn-sm text-exito" disabled={enviando} onClick={() => ejecutar('aprobar')} title="Aprobar permuta">
                    <CheckCheck className="size-4" aria-hidden /> Aprobar
                </button>
            )}
            {puedeRechazar && (
                <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => abrir('rechazar-encargado')} title="Rechazar permuta">
                    <X className="size-4" aria-hidden /> Rechazar
                </button>
            )}
            {puedeCancelar && (
                <button className="btn btn-fantasma btn-sm" onClick={() => setDialogo('cancelar')} title="Cancelar permuta">
                    <Ban className="size-4" aria-hidden /> Cancelar
                </button>
            )}

            <Modal abierto={rechazo} titulo={dialogo === 'rechazar-encargado' ? `Rechazar permuta #${fila.id}` : 'No puedo cubrir este turno'} onCerrar={() => setDialogo(null)}>
                <Formulario
                    campos={camposRechazo} valores={v} setValores={setV} errores={errores} enviando={enviando} textoBoton="Rechazar"
                    onCancelar={() => setDialogo(null)}
                    onEnviar={() => {
                        const e = validar(v, camposRechazo);
                        setErrores(e);
                        if (Object.keys(e).length) return;
                        if (dialogo === 'rechazar-encargado') ejecutar('rechazar', v);
                        else ejecutar('responder', { aceptar: false, observacion: v.observacion || null });
                    }}
                />
            </Modal>
            <Confirmar
                abierto={dialogo === 'cancelar'}
                titulo={`Cancelar permuta #${fila.id}`}
                mensaje={`El turno del ${fecha(fila.fecha)} vuelve a su titular (${fila.solicitante?.nombre_completo}). Se avisará a los involucrados.`}
                textoBoton="Cancelar permuta"
                onConfirmar={() => ejecutar('cancelar')}
                onCerrar={() => setDialogo(null)}
            />
        </>
    );
}

export default function Permutas() {
    const { puede } = useAuth();
    const solicita = puede('permutas.solicitar');
    const misTurnos = useOpciones(solicita ? 'mis_turnos' : null);
    const diaDelTurno = (valores) => misTurnos.find((t) => String(t.id) === String(valores.turno_id))?.dia_semana;

    return (
        <CrudPage
            titulo="Permutas de turno"
            descripcion={puede('permutas.aprobar')
                ? 'Un auxiliar pide que otro cubra su turno en una fecha. Cuando el reemplazante acepta, usted la aprueba o rechaza.'
                : 'Pida a otro auxiliar que cubra su turno en una fecha. Flujo: usted solicita → el reemplazante acepta → el Encargado aprueba. Ese día la asistencia la marca el reemplazante.'}
            endpoint="permutas"
            singular="permuta" femenino
            permisos={{ crear: 'permutas.solicitar' }}
            inicial={{ fecha: '' }}
            filtros={[{ name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'ACEPTADA', 'APROBADA', 'RECHAZADA', 'CANCELADA'] }]}
            columnas={[
                { key: 'id', label: 'N°', render: (f) => `#${f.id}` },
                {
                    key: 'fecha', label: 'Fecha y turno',
                    render: (f) => <><strong>{DIAS[f.turno?.dia_semana]} {fecha(f.fecha)}</strong><span className="block text-xs text-suave">{hora(f.turno?.hora_inicio)} – {hora(f.turno?.hora_fin)} · {f.turno?.laboratorio?.codigo ?? 'Módulo'}</span></>,
                },
                {
                    key: 'quien', label: 'Titular → Reemplazante',
                    render: (f) => <span className="flex flex-wrap items-center gap-1">{f.solicitante?.nombre_completo}<ArrowRight className="size-4 text-suave" aria-label="lo cubre" />{f.reemplazante?.nombre_completo}</span>,
                },
                { key: 'motivo', label: 'Motivo', render: (f) => <>{f.motivo}<span className="block text-xs text-suave">{fechaHora(f.created_at)}</span></> },
                {
                    key: 'estado', label: 'Estado',
                    render: (f) => <><Badge valor={f.estado} />{f.observacion && <span className="mt-1 block max-w-56 text-xs text-suave">{f.observacion}</span>}{f.aprobador && <span className="block text-xs text-suave">por {f.aprobador.nombre}</span>}</>,
                },
            ]}
            acciones={(fila, recargar) => <AccionesPermuta fila={fila} recargar={recargar} />}
            campos={[
                { name: 'turno_id', label: 'Mi turno', tipo: 'select', opciones: 'mis_turnos', requerido: true, ancho: 'completo', ayuda: 'Solo aparecen sus turnos del semestre activo.' },
                {
                    name: 'fecha', label: 'Fecha a cubrir', tipo: 'date', requerido: true, noPasado: true,
                    validar: (v, valores) => {
                        const dia = diaDelTurno(valores);
                        const elegido = new Date(`${v}T12:00`).getDay() || 7; // 1 = lunes … 7 = domingo
                        return dia && elegido !== dia ? `El turno es los ${DIAS[dia].toLowerCase()}: elija una fecha que sea ${DIAS[dia].toLowerCase()}.` : null;
                    },
                },
                { name: 'reemplazante_id', label: 'Reemplazante', tipo: 'select', opciones: 'auxiliares', requerido: true },
                { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo', placeholder: 'Ej: Examen final de INF412 a esa hora' },
            ]}
        />
    );
}
