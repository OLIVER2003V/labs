import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { AlertTriangle, Ban, CreditCard } from 'lucide-react';
import CrudPage from '../componentes/CrudPage';
import { Badge, Formulario, Modal, useToast } from '../componentes/ui';
import api, { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { bs, fecha, fechaHora } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';
import { ModalPago } from './Pagos';

/*
 * CU9 - Multas. Se generan solas:
 *   ATRASO  → el auxiliar marca entrada entre los 11 y 30 min (CU7)
 *   FALTA   → falta automática a los 30 min sin marcar (CU7); un permiso posterior la anula
 *   RESERVA → el auxiliar/Encargado registra que una reserva aprobada no se utilizó (CU4)
 * Se pagan con tarjeta en Stripe. Vencida (pasado el plazo) = no puede reservar ni pedir permutas.
 * Los montos son conceptos de cobro (MULTA-ATRASO, MULTA-FALTA, MULTA-RESERVA): el Encargado los cambia en "Conceptos de cobro".
 */
const TIPO = { ATRASO: 'Atraso', FALTA: 'Falta', RESERVA: 'Reserva no utilizada' };
const CAMPO_ANULAR = [{ name: 'observacion', label: 'Motivo de la anulación', tipo: 'textarea', requerido: true, min: 5, max: 200, ancho: 'completo' }];

function AccionesMulta({ fila, recargar, onPagar }) {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const [anular, setAnular] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    if (fila.estado !== 'PENDIENTE') return null;

    const enviar = async () => {
        const e = validar(v, CAMPO_ANULAR);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            notificar((await api.put(`/multas/${fila.id}/anular`, v)).data.message);
            setAnular(false);
            recargar();
        } catch (err) {
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    return (
        <>
            {fila.usuario_id === usuario.id && puede('pagos.pagar') && (
                <button className="btn btn-primario btn-sm" onClick={() => onPagar(fila)}>
                    <CreditCard className="size-4" aria-hidden /> Pagar
                </button>
            )}
            {puede('multas.gestionar') && (
                <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => { setV({}); setErrores({}); setAnular(true); }}>
                    <Ban className="size-4" aria-hidden /> Anular
                </button>
            )}
            <Modal abierto={anular} titulo={`Anular multa de ${fila.usuario?.nombre_completo}`} onCerrar={() => setAnular(false)}>
                <p className="mb-3 text-sm text-suave">{fila.motivo} · {bs(fila.monto)}</p>
                <Formulario campos={CAMPO_ANULAR} valores={v} setValores={setV} errores={errores} enviando={enviando} textoBoton="Anular multa"
                    onEnviar={enviar} onCancelar={() => setAnular(false)} />
            </Modal>
        </>
    );
}

export default function Multas() {
    const { puede, usuario } = useAuth();
    const gestiona = puede('multas.gestionar');
    const verTodas = gestiona || puede('multas.ver_todas'); // el Director consulta todas pero no anula
    const [resumen, setResumen] = useState(null);
    const [pago, setPago] = useState(null);
    const [recargar, setRecargar] = useState(0);

    const cargarResumen = useCallback(() => {
        api.get('/multas/resumen').then((r) => setResumen(r.data)).catch(() => {});
    }, []);
    useEffect(cargarResumen, [cargarResumen, recargar]);

    const m = resumen?.montos ?? {};
    const tarjeta = (titulo, valor, tono = '') => (
        <div className={`tarjeta border-l-4 p-4 ${tono || 'border-l-primario'}`}>
            <p className="text-xs font-semibold tracking-wide text-suave uppercase">{titulo}</p>
            <p className="mt-1 text-xl font-bold">{valor}</p>
        </div>
    );

    return (
        <>
            {resumen?.bloqueado && (
                <div className="mb-5 flex items-start gap-3 rounded-tema border border-peligro bg-peligro-tenue p-4 text-peligro" role="alert">
                    <AlertTriangle className="mt-0.5 size-5 shrink-0" aria-hidden />
                    <p><strong>Tiene multas vencidas por {bs(resumen.monto_vencido_propio)}.</strong> Mientras no las pague no puede solicitar reservas ni permutas de turno.</p>
                </div>
            )}
            {resumen && (
                <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {tarjeta(verTodas ? 'Multas pendientes (todos)' : 'Mis multas pendientes', `${resumen.pendientes} · ${bs(resumen.monto_pendiente)}`, 'border-l-alerta')}
                    {tarjeta('Vencidas', resumen.vencidas, resumen.vencidas ? 'border-l-peligro' : '')}
                    {tarjeta(verTodas ? 'Cobrado' : 'Pagado', bs(resumen.monto_pagado), 'border-l-exito')}
                    {tarjeta('Montos vigentes', [m.ATRASO && `Atraso ${bs(m.ATRASO.monto)}`, m.FALTA && `Falta ${bs(m.FALTA.monto)}`, m.RESERVA && `Reserva ${bs(m.RESERVA.monto)}`].filter(Boolean).join(' · '))}
                </div>
            )}

            <CrudPage
                titulo="Multas"
                descripcion={verTodas
                    ? <>Se generan solas por atraso o falta de un auxiliar y por reservas no utilizadas de docentes y auxiliares. Plazo de pago: {resumen?.dias_plazo ?? 7} días.{gestiona && <> Los montos se cambian en <Link to="/pagos/conceptos" className="font-semibold text-primario underline">Conceptos de cobro</Link> (MULTA-ATRASO, MULTA-FALTA, MULTA-RESERVA).</>}</>
                    : `Pague sus multas con tarjeta (Stripe). Tiene ${resumen?.dias_plazo ?? 7} días desde que se generan; si vencen, no podrá solicitar reservas ni permutas.`}
                endpoint="multas"
                singular="multa" femenino
                aviso={resumen && !verTodas && resumen.pendientes > 0
                    ? { texto: `Tiene ${resumen.pendientes} multa(s) por pagar (${bs(resumen.monto_pendiente)}).${resumen.vencidas ? ` ${resumen.vencidas} ya venció(eron).` : ''}`, filtro: { estado: 'PENDIENTE' } }
                    : null}
                resaltar={(f) => (f.estado === 'PENDIENTE' && f.usuario_id === usuario.id ? (f.vencida ? 'Vencida: páguela' : 'Por pagar') : null)}
                recargarClave={recargar}
                filtros={[
                    { name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'PAGADA', 'ANULADA'] },
                    { name: 'tipo', label: 'Tipo', opciones: Object.entries(TIPO).map(([value, label]) => ({ value, label })) },
                ]}
                columnas={[
                    { key: 'fecha', label: 'Fecha', render: (f) => <><strong>{fecha(f.fecha)}</strong><span className="block text-xs text-suave">{TIPO[f.tipo]}</span></> },
                    ...(verTodas ? [{ key: 'usuario', label: 'Usuario', render: (f) => f.usuario?.nombre_completo }] : []),
                    { key: 'motivo', label: 'Motivo' },
                    { key: 'monto', label: 'Monto', render: (f) => <strong>{bs(f.monto)}</strong> },
                    {
                        key: 'vence_en', label: 'Vence',
                        render: (f) => (f.estado === 'PENDIENTE'
                            ? <span className={f.vencida ? 'font-semibold text-peligro' : ''}>{fecha(f.vence_en)}{f.vencida && ' (vencida)'}</span>
                            : '—'),
                    },
                    {
                        key: 'estado', label: 'Estado',
                        render: (f) => <><Badge valor={f.estado === 'PENDIENTE' && f.vencida ? 'EXPIRADA' : f.estado}>{f.estado === 'PENDIENTE' && f.vencida ? 'VENCIDA' : f.estado}</Badge>
                            {f.pagada_en && <span className="block text-xs text-suave">{fechaHora(f.pagada_en)}</span>}
                            {f.observacion && <span className="mt-1 block max-w-56 text-xs text-suave">{f.observacion}</span>}</>,
                    },
                ]}
                acciones={(fila, rec) => (
                    <AccionesMulta fila={fila} recargar={() => { rec(); setRecargar((n) => n + 1); }} onPagar={(f) => setPago({
                        titulo: `Multa: ${TIPO[f.tipo]} del ${fecha(f.fecha)}`,
                        monto: f.monto,
                        objetivo: { multa_id: f.id },
                    })} />
                )}
            />

            <ModalPago abierto={!!pago} titulo={pago?.titulo} monto={pago?.monto} objetivo={pago?.objetivo} onCerrar={() => setPago(null)} />
        </>
    );
}
