import { useEffect, useState } from 'react';
import {
    Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { AlertTriangle, Building2, CalendarCheck, Clock, PackageSearch, Users, Wallet, Wrench } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { bs, fecha } from '../lib/formato';
import { useTema } from '../contexto/TemaContext';
import { Cargando, Encabezado, Tarjeta, useToast } from '../componentes/ui';

/** Lee los colores del tema actual (variables CSS) para que los gráficos cambien con el tema */
export function useColores() {
    const { tema, modoReal, contraste } = useTema();
    const [c, setC] = useState({});
    useEffect(() => {
        const s = getComputedStyle(document.documentElement);
        const v = (n) => s.getPropertyValue(`--c-${n}`).trim();
        setC({
            primario: v('primario'), acento: v('acento'), exito: v('exito'), alerta: v('alerta'), peligro: v('peligro'),
            info: v('info'), texto: v('texto'), suave: v('suave'), borde: v('borde'), superficie: v('superficie'),
        });
    }, [tema, modoReal, contraste]);
    return c;
}

const COLOR_ESTADO = { APROBADA: 'exito', PENDIENTE: 'alerta', RECHAZADA: 'peligro', CANCELADA: 'suave', RESUELTA: 'exito', EN_ATENCION: 'info', PUNTUAL: 'exito', ATRASO: 'alerta', FALTA: 'peligro', PERMISO: 'info' };

function Kpi({ icono: I, titulo, valor, tono = 'primario' }) {
    const fondo = { primario: 'bg-primario-tenue text-primario', exito: 'bg-exito-tenue text-exito', alerta: 'bg-alerta-tenue text-alerta', peligro: 'bg-peligro-tenue text-peligro', info: 'bg-info-tenue text-info' }[tono];
    return (
        <div className="tarjeta flex items-center gap-4 p-4">
            <span className={`grid size-12 shrink-0 place-items-center rounded-tema ${fondo}`}><I className="size-6" aria-hidden /></span>
            <div>
                <p className="text-sm text-suave">{titulo}</p>
                <p className="text-2xl font-bold">{valor}</p>
            </div>
        </div>
    );
}

export default function Dashboard() {
    const notificar = useToast();
    const c = useColores();
    const [rango, setRango] = useState({ desde: '', hasta: '' });
    const [d, setD] = useState(null);

    useEffect(() => {
        if (rango.desde && rango.hasta && rango.desde > rango.hasta) return; // rango inválido: no consultar
        let vigente = true; // ignora respuestas de un rango anterior si el usuario cambió las fechas
        api.get('/dashboard', { params: { desde: rango.desde || undefined, hasta: rango.hasta || undefined } })
            .then((r) => vigente && setD(r.data))
            .catch((e) => vigente && notificar(mensajeError(e), 'error'));
        return () => {
            vigente = false;
        };
    }, [rango.desde, rango.hasta, notificar]);

    if (!d || !c.primario) return <Cargando />;
    const t = d.tarjetas;
    const ejes = { stroke: c.suave, fontSize: 12 };
    const tooltip = { contentStyle: { background: c.superficie, border: `1px solid ${c.borde}`, borderRadius: 8, color: c.texto } };
    const pastel = (lista) => lista.map((x) => ({ name: x.estado.replace('_', ' '), value: Number(x.total), fill: c[COLOR_ESTADO[x.estado]] ?? c.info }));
    const corto = (s) => s.slice(5).split('-').reverse().join('/');

    return (
        <>
            <Encabezado titulo="Panel de estadísticas(Juan)" descripcion={`Actividad del ${fecha(d.rango.desde)} al ${fecha(d.rango.hasta)}`}>
                <label className="flex items-center gap-2 text-sm">Desde
                    <input type="date" className="campo w-auto py-1.5" value={rango.desde || d.rango.desde} max={rango.hasta || d.rango.hasta} onChange={(e) => setRango({ hasta: rango.hasta || d.rango.hasta, desde: e.target.value })} />
                </label>
                <label className="flex items-center gap-2 text-sm">Hasta
                    <input type="date" className="campo w-auto py-1.5" value={rango.hasta || d.rango.hasta} min={rango.desde || d.rango.desde} onChange={(e) => setRango({ desde: rango.desde || d.rango.desde, hasta: e.target.value })} />
                </label>
            </Encabezado>

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Kpi icono={Building2} titulo="Laboratorios disponibles" valor={t.laboratorios} />
                <Kpi icono={CalendarCheck} titulo="Reservas pendientes" valor={t.reservas_pendientes} tono="alerta" />
                <Kpi icono={Clock} titulo="Reservas aprobadas hoy" valor={t.reservas_hoy} tono="info" />
                <Kpi icono={Wallet} titulo="Ingresos del periodo" valor={bs(t.ingresos)} tono="exito" />
                <Kpi icono={AlertTriangle} titulo="Incidencias abiertas" valor={t.incidencias_abiertas} tono="peligro" />
                <Kpi icono={Wrench} titulo="Equipos en reparación" valor={t.equipos_reparacion} tono="alerta" />
                <Kpi icono={PackageSearch} titulo="Objetos en custodia" valor={t.objetos_custodia} tono="info" />
                <Kpi icono={Users} titulo="Usuarios activos" valor={t.usuarios_activos} />
            </div>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <Tarjeta titulo="Ocupación por laboratorio (% de las horas hábiles)">
                    <ResponsiveContainer width="100%" height={280}>
                        <BarChart data={d.ocupacion_laboratorios}>
                            <CartesianGrid strokeDasharray="3 3" stroke={c.borde} />
                            <XAxis dataKey="nombre" {...ejes} interval={0} angle={-30} textAnchor="end" height={50} />
                            <YAxis {...ejes} unit=" %" />
                            <Tooltip {...tooltip} />
                            <Legend />
                            <Bar dataKey="clase" name="Clases (%)" stackId="o" fill={c.primario} />
                            <Bar dataKey="reserva" name="Reservas (%)" stackId="o" fill={c.acento} radius={[4, 4, 0, 0]} />
                        </BarChart>
                    </ResponsiveContainer>
                </Tarjeta>

                <Tarjeta titulo="Reservas por estado">
                    <ResponsiveContainer width="100%" height={280}>
                        <PieChart>
                            <Pie data={pastel(d.reservas_por_estado)} dataKey="value" nameKey="name" innerRadius={60} outerRadius={100} paddingAngle={2} label>
                                {pastel(d.reservas_por_estado).map((x, i) => <Cell key={i} fill={x.fill} />)}
                            </Pie>
                            <Tooltip {...tooltip} />
                            <Legend />
                        </PieChart>
                    </ResponsiveContainer>
                </Tarjeta>

                <Tarjeta titulo="Ingresos por día (pagos aprobados)">
                    <ResponsiveContainer width="100%" height={260}>
                        <AreaChart data={d.ingresos_por_dia.map((x) => ({ dia: corto(x.dia), total: Number(x.total) }))}>
                            <CartesianGrid strokeDasharray="3 3" stroke={c.borde} />
                            <XAxis dataKey="dia" {...ejes} />
                            <YAxis {...ejes} />
                            <Tooltip {...tooltip} formatter={(v) => bs(v)} />
                            <Area type="monotone" dataKey="total" name="Ingresos" stroke={c.exito} fill={c.exito} fillOpacity={0.2} strokeWidth={2} />
                        </AreaChart>
                    </ResponsiveContainer>
                </Tarjeta>

                <div className="grid gap-5 sm:grid-cols-2">
                    <Tarjeta titulo="Incidencias">
                        <ResponsiveContainer width="100%" height={220}>
                            <PieChart>
                                <Pie data={pastel(d.incidencias_por_estado)} dataKey="value" nameKey="name" outerRadius={75} label>
                                    {pastel(d.incidencias_por_estado).map((x, i) => <Cell key={i} fill={x.fill} />)}
                                </Pie>
                                <Tooltip {...tooltip} />
                                <Legend />
                            </PieChart>
                        </ResponsiveContainer>
                    </Tarjeta>
                    <Tarjeta titulo="Asistencia de auxiliares">
                        <ResponsiveContainer width="100%" height={220}>
                            <PieChart>
                                <Pie data={pastel(d.asistencia_por_estado)} dataKey="value" nameKey="name" outerRadius={75} label>
                                    {pastel(d.asistencia_por_estado).map((x, i) => <Cell key={i} fill={x.fill} />)}
                                </Pie>
                                <Tooltip {...tooltip} />
                                <Legend />
                            </PieChart>
                        </ResponsiveContainer>
                    </Tarjeta>
                </div>
            </div>

            {d.accesos_por_dia && (
                <>
                    <h2 className="mt-8 mb-3 text-xl font-bold">Flujo de accesos al sitio</h2>
                    <div className="grid gap-5 lg:grid-cols-3">
                        <Tarjeta titulo="Accesos e inicios de sesión por día" className="lg:col-span-2">
                            <ResponsiveContainer width="100%" height={280}>
                                <LineChart data={d.accesos_por_dia.map((x) => ({ dia: corto(x.dia), accesos: Number(x.accesos), logins: Number(x.logins), fallidos: Number(x.fallidos) }))}>
                                    <CartesianGrid strokeDasharray="3 3" stroke={c.borde} />
                                    <XAxis dataKey="dia" {...ejes} />
                                    <YAxis {...ejes} />
                                    <Tooltip {...tooltip} />
                                    <Legend />
                                    <Line type="monotone" dataKey="accesos" name="Peticiones" stroke={c.primario} strokeWidth={2} dot={false} />
                                    <Line type="monotone" dataKey="logins" name="Logins exitosos" stroke={c.exito} strokeWidth={2} dot={false} />
                                    <Line type="monotone" dataKey="fallidos" name="Logins fallidos" stroke={c.peligro} strokeWidth={2} dot={false} />
                                </LineChart>
                            </ResponsiveContainer>
                        </Tarjeta>
                        <Tarjeta titulo="Recursos más accedidos">
                            <ResponsiveContainer width="100%" height={280}>
                                <BarChart layout="vertical" data={d.recursos_mas_accedidos.map((x) => ({ ...x, total: Number(x.total) }))} margin={{ left: 20 }}>
                                    <XAxis type="number" {...ejes} />
                                    <YAxis type="category" dataKey="recurso" {...ejes} width={90} />
                                    <Tooltip {...tooltip} />
                                    <Bar dataKey="total" name="Peticiones" fill={c.info} radius={[0, 4, 4, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </Tarjeta>
                    </div>
                    <Tarjeta titulo="Páginas más visitadas (contador del pie de página)" className="mt-5">
                        <ResponsiveContainer width="100%" height={240}>
                            <BarChart data={d.paginas_mas_visitadas.map((x) => ({ ...x, contador: Number(x.contador) }))}>
                                <CartesianGrid strokeDasharray="3 3" stroke={c.borde} />
                                <XAxis dataKey="ruta" {...ejes} />
                                <YAxis {...ejes} />
                                <Tooltip {...tooltip} />
                                <Bar dataKey="contador" name="Visitas" fill={c.acento} radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </Tarjeta>
                </>
            )}
        </>
    );
}
