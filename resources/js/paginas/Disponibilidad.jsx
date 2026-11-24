import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { CalendarClock, CheckCircle2, ChevronLeft, ChevronRight, Search, XCircle } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { DIAS, fecha as fmtFecha, hoyISO } from '../lib/formato';
import { Campo, Cargando, Encabezado, Tarjeta, useToast } from '../componentes/ui';
import { useAuth } from '../contexto/AuthContext';

const CAMPOS = [
    { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true },
    { name: 'hora_inicio', label: 'Desde', tipo: 'time', requerido: true },
    { name: 'hora_fin', label: 'Hasta', tipo: 'time', requerido: true, despuesDe: 'hora_inicio' },
];
const INICIO = 7;
const FIN = 22;
const minutos = (h) => Number(h.slice(0, 2)) * 60 + Number(h.slice(3, 5));
const COLOR = {
    CLASE: 'bg-info-tenue border-info text-info',
    RESERVA: 'bg-exito-tenue border-exito text-exito',
    PENDIENTE: 'bg-alerta-tenue border-alerta text-alerta border-dashed',
};

/** Agenda visual del día: una fila por laboratorio, bloques ubicados según la hora */
function Agenda() {
    const notificar = useToast();
    const [dia, setDia] = useState(hoyISO());
    const [datos, setDatos] = useState(null);

    useEffect(() => {
        setDatos(null);
        api.get('/disponibilidad/agenda', { params: { fecha: dia } }).then((r) => setDatos(r.data)).catch((e) => notificar(mensajeError(e), 'error'));
    }, [dia, notificar]);

    const mover = (n) => {
        const d = new Date(`${dia}T12:00`);
        d.setDate(d.getDate() + n);
        setDia(d.toISOString().slice(0, 10));
    };
    const total = (FIN - INICIO) * 60;
    const diaSemana = new Date(`${dia}T12:00`).getDay() || 7;

    return (
        <Tarjeta
            titulo={`Agenda del ${DIAS[diaSemana].toLowerCase()} ${fmtFecha(dia)}`}
            acciones={
                <div className="flex items-center gap-1">
                    <button className="btn btn-secundario btn-sm" onClick={() => mover(-1)} aria-label="Día anterior"><ChevronLeft className="size-4" /></button>
                    <input type="date" className="campo w-auto py-1" value={dia} onChange={(e) => e.target.value && setDia(e.target.value)} aria-label="Fecha de la agenda" />
                    <button className="btn btn-secundario btn-sm" onClick={() => mover(1)} aria-label="Día siguiente"><ChevronRight className="size-4" /></button>
                </div>
            }
        >
            <div className="mb-3 flex flex-wrap gap-3 text-xs">
                <span className="flex items-center gap-1"><span className="size-3 rounded border border-info bg-info-tenue" /> Clase (horario base)</span>
                <span className="flex items-center gap-1"><span className="size-3 rounded border border-exito bg-exito-tenue" /> Reserva aprobada</span>
                <span className="flex items-center gap-1"><span className="size-3 rounded border border-dashed border-alerta bg-alerta-tenue" /> Reserva pendiente</span>
            </div>
            {!datos ? <Cargando /> : (
                <div className="overflow-x-auto">
                    <div className="min-w-[760px]">
                        <div className="ml-24 flex text-[0.7rem] text-suave">
                            {Array.from({ length: FIN - INICIO }, (_, i) => (
                                <span key={i} className="flex-1 border-l border-borde pl-1">{String(INICIO + i).padStart(2, '0')}:00</span>
                            ))}
                        </div>
                        {datos.map((fila) => (
                            <div key={fila.laboratorio.id} className="flex items-stretch border-t border-borde">
                                <div className="w-24 shrink-0 py-2 pr-2 text-sm font-semibold">
                                    {fila.laboratorio.codigo}
                                    {fila.laboratorio.estado !== 'DISPONIBLE' && <span className="block text-[0.65rem] font-normal text-peligro">{fila.laboratorio.estado}</span>}
                                </div>
                                <div
                                    className="relative h-14 flex-1"
                                    style={{ backgroundImage: 'linear-gradient(to right, var(--c-borde) 1px, transparent 1px)', backgroundSize: `${100 / (FIN - INICIO)}% 100%` }}
                                >
                                    {fila.bloqueo ? (
                                        <div className="absolute inset-1 flex items-center justify-center rounded border border-peligro bg-peligro-tenue text-xs font-semibold text-peligro">
                                            Bloqueado · {fila.bloqueo}
                                        </div>
                                    ) : (
                                        fila.bloques.map((b, i) => {
                                            const izq = ((minutos(b.inicio) - INICIO * 60) / total) * 100;
                                            const ancho = ((minutos(b.fin) - minutos(b.inicio)) / total) * 100;
                                            return (
                                                <div
                                                    key={i}
                                                    className={`absolute top-1 bottom-1 overflow-hidden rounded border px-1.5 py-0.5 text-[0.7rem] leading-tight ${COLOR[b.tipo]}`}
                                                    style={{ left: `${Math.max(izq, 0)}%`, width: `${ancho}%` }}
                                                    title={`${b.inicio}–${b.fin} · ${b.titulo} · ${b.detalle}`}
                                                >
                                                    <strong className="block truncate">{b.titulo}</strong>
                                                    <span className="block truncate">{b.inicio}–{b.fin}</span>
                                                </div>
                                            );
                                        })
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </Tarjeta>
    );
}

export default function Disponibilidad() {
    const { puede } = useAuth();
    const notificar = useToast();
    const [v, setV] = useState({ fecha: hoyISO(), hora_inicio: '08:00', hora_fin: '10:00' });
    const [errores, setErrores] = useState({});
    const [resultado, setResultado] = useState(null);
    const [buscando, setBuscando] = useState(false);

    const consultar = async (e) => {
        e?.preventDefault();
        const errs = validar(v, CAMPOS);
        setErrores(errs);
        if (Object.keys(errs).length) return;
        setBuscando(true);
        try {
            setResultado((await api.get('/disponibilidad', { params: v })).data);
        } catch (err) {
            notificar(mensajeError(err), 'error');
        } finally {
            setBuscando(false);
        }
    };

    useEffect(() => {
        consultar();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const libres = resultado?.filter((r) => r.libre).length ?? 0;

    return (
        <>
            <Encabezado titulo="Disponibilidad de laboratorios" descripcion="Cruza el horario base, las reservas aprobadas y las fechas bloqueadas." />

            <Tarjeta className="mb-5">
                <form onSubmit={consultar} noValidate className="grid items-end gap-4 sm:grid-cols-4">
                    {CAMPOS.map((c) => (
                        <Campo key={c.name} campo={c} valor={v[c.name]} error={errores[c.name]} valores={v} onChange={(x) => setV({ ...v, [c.name]: x })} />
                    ))}
                    <button className="btn btn-primario" disabled={buscando}>
                        <Search className="size-4" aria-hidden /> Consultar
                    </button>
                </form>
            </Tarjeta>

            {resultado && (
                <section className="mb-6" aria-live="polite">
                    <h2 className="mb-3 font-semibold">
                        {libres} de {resultado.length} laboratorios libres el {fmtFecha(v.fecha)} de {v.hora_inicio} a {v.hora_fin}
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {resultado.map(({ laboratorio: l, libre, conflictos }) => (
                            <article key={l.id} className={`tarjeta border-l-4 p-4 ${libre ? 'border-l-exito' : 'border-l-peligro'}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 className="font-bold">{l.codigo}</h3>
                                        <p className="text-sm text-suave">{l.nombre} · {l.capacidad} equipos</p>
                                    </div>
                                    {libre ? (
                                        <span className="flex items-center gap-1 text-sm font-semibold text-exito"><CheckCircle2 className="size-5" aria-hidden /> Libre</span>
                                    ) : (
                                        <span className="flex items-center gap-1 text-sm font-semibold text-peligro"><XCircle className="size-5" aria-hidden /> Ocupado</span>
                                    )}
                                </div>
                                {conflictos.map((c, i) => (
                                    <p key={i} className="mt-2 flex gap-1.5 text-xs text-suave">
                                        <CalendarClock className="size-4 shrink-0" aria-hidden />
                                        {c.hora_inicio && `${c.hora_inicio}–${c.hora_fin} · `}{c.detalle}
                                    </p>
                                ))}
                                {libre && puede('reservas.crear') && (
                                    <Link to="/reservas" className="btn btn-secundario btn-sm mt-3">Solicitar reserva</Link>
                                )}
                            </article>
                        ))}
                    </div>
                </section>
            )}

            <Agenda />
        </>
    );
}
