import { useEffect, useState } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { AlertTriangle, Building2, CalendarCheck, Clock, PackageSearch, Users, Wallet, Wrench } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { bs, fecha } from '../lib/formato';
import { Cargando, Encabezado, Tarjeta, useToast } from '../componentes/ui';
import { BarraSegmentada, compacto, estilos, SinDatos, useColores } from '../componentes/Graficos';


/*
 * Panel de estadísticas. Un solo filtro de fechas arriba para todos los gráficos.
 * Partes de un total (reservas, incidencias, asistencia) → barra segmentada con números; magnitudes → barras;
 * evolución en el tiempo → área. Cada medida en su propio gráfico (nunca dos escalas en uno).
 */

function Kpi({ icono: I, titulo, valor, tono = 'primario' }) {
    const fondo = { primario: 'bg-primario-tenue text-primario', exito: 'bg-exito-tenue text-exito', alerta: 'bg-alerta-tenue text-alerta', peligro: 'bg-peligro-tenue text-peligro', info: 'bg-info-tenue text-info' }[tono];
    return (
        <div className="tarjeta flex flex-col items-start gap-2 p-3 sm:flex-row sm:items-center sm:gap-4 sm:p-4">
            <span className={`grid size-10 shrink-0 place-items-center rounded-tema sm:size-12 ${fondo}`}><I className="size-5 sm:size-6" aria-hidden /></span>
            <div className="min-w-0">
                <p className="text-sm text-suave">{titulo}</p>
                <p className="text-xl font-bold sm:text-2xl">{valor}</p>
            </div>
        </div>
    );
}

export default function Dashboard() {
    const notificar = useToast();
    const c = useColores();
    const [rango, setRango] = useState({ desde: '', hasta: '' });
    const [d, setD] = useState(null);
    const [cargando, setCargando] = useState(false);

    useEffect(() => {
        if (rango.desde && rango.hasta && rango.desde > rango.hasta) return; // rango inválido: no consultar
        let vigente = true; // ignora respuestas de un rango anterior si el usuario cambió las fechas
        setCargando(true);
        api.get('/dashboard', { params: { desde: rango.desde || undefined, hasta: rango.hasta || undefined } })
            .then((r) => vigente && setD(r.data))
            .catch((e) => vigente && notificar(mensajeError(e), 'error'))
            .finally(() => vigente && setCargando(false));
        return () => {
            vigente = false;
        };
    }, [rango.desde, rango.hasta, notificar]);

    if (!d || !c.serie1) return <Cargando />;
    const t = d.tarjetas;
    const { ejes, grilla, tooltip, leyenda } = estilos(c);
    const corto = (s) => s.slice(5).split('-').reverse().join('/');
    const ingresos = d.ingresos_por_dia.map((x) => ({ dia: corto(x.dia), total: Number(x.total) }));

    return (
        <>
            <Encabezado titulo="Panel de estadísticas" descripcion={`Actividad del ${fecha(d.rango.desde)} al ${fecha(d.rango.hasta)}`}>
                <label className="flex items-center gap-2 text-sm">Desde
                    <input type="date" className="campo w-auto py-1.5" value={rango.desde || d.rango.desde} max={rango.hasta || d.rango.hasta} onChange={(e) => setRango({ hasta: rango.hasta || d.rango.hasta, desde: e.target.value })} />
                </label>
                <label className="flex items-center gap-2 text-sm">Hasta
                    <input type="date" className="campo w-auto py-1.5" value={rango.hasta || d.rango.hasta} min={rango.desde || d.rango.desde} onChange={(e) => setRango({ desde: rango.desde || d.rango.desde, hasta: e.target.value })} />
                </label>
            </Encabezado>

            {/* Al cambiar el rango se mantiene lo anterior atenuado (sin saltos) hasta que llegan los datos nuevos */}
            <div className={`transition-opacity ${cargando ? 'opacity-60' : ''}`} aria-busy={cargando}>
                <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
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
                    <Tarjeta titulo="Ocupación por laboratorio (% de las horas hábiles)" className="lg:col-span-2">
                        <ResponsiveContainer width="100%" height={300}>
                            <BarChart data={d.ocupacion_laboratorios} margin={{ top: 8, right: 8, left: -8, bottom: 0 }} barCategoryGap="28%">
                                <CartesianGrid {...grilla} />
                                <XAxis dataKey="nombre" {...ejes} interval={0} angle={d.ocupacion_laboratorios.length > 6 ? -30 : 0} textAnchor={d.ocupacion_laboratorios.length > 6 ? 'end' : 'middle'} height={d.ocupacion_laboratorios.length > 6 ? 56 : 30} />
                                <YAxis {...ejes} unit=" %" width={52} />
                                <Tooltip {...tooltip} formatter={(v, n) => [`${v} %`, n]} />
                                <Legend {...leyenda} />
                                <Bar dataKey="clase" name="Clases" stackId="o" fill={c.serie1} stroke={c.superficie} strokeWidth={1} />
                                <Bar dataKey="reserva" name="Reservas" stackId="o" fill={c.serie2} stroke={c.superficie} strokeWidth={1} radius={[4, 4, 0, 0]} />
                            </BarChart>
                        </ResponsiveContainer>
                    </Tarjeta>

                    <Tarjeta titulo="Reservas por estado">
                        <BarraSegmentada datos={d.reservas_por_estado} c={c} etiqueta="reservas en el periodo" />
                    </Tarjeta>

                    <Tarjeta titulo="Ingresos por día (pagos aprobados)">
                        {ingresos.length ? (
                            <ResponsiveContainer width="100%" height={220}>
                                <AreaChart data={ingresos} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                                    <CartesianGrid {...grilla} />
                                    <XAxis dataKey="dia" {...ejes} minTickGap={16} />
                                    <YAxis {...ejes} width={60} tickFormatter={(v) => `Bs ${compacto(v)}`} />
                                    <Tooltip {...tooltip} formatter={(v) => [bs(v), 'Ingresos']} />
                                    <Area type="monotone" dataKey="total" name="Ingresos" stroke={c.serie1} fill={c.serie1} fillOpacity={0.15} strokeWidth={2} activeDot={{ r: 5 }} />
                                </AreaChart>
                            </ResponsiveContainer>
                        ) : <SinDatos texto="No hubo pagos aprobados en el periodo." />}
                    </Tarjeta>

                    <Tarjeta titulo="Incidencias por estado">
                        <BarraSegmentada datos={d.incidencias_por_estado} c={c} etiqueta="incidencias reportadas en el periodo" vacio="No se reportaron incidencias en el periodo." />
                    </Tarjeta>
                    <Tarjeta titulo="Asistencia de auxiliares">
                        <BarraSegmentada datos={d.asistencia_por_estado} c={c} etiqueta="registros de asistencia" vacio="Sin registros de asistencia en el periodo." />
                    </Tarjeta>
                </div>

                {d.accesos_por_dia && (
                    <>
                        <h2 className="mt-8 mb-3 text-xl font-bold">Flujo de accesos al sitio</h2>
                        <div className="grid gap-5 lg:grid-cols-2">
                            {/* Peticiones (cientos) e inicios de sesión (unidades) van en gráficos separados: en uno solo, los logins quedarían aplastados */}
                            <Tarjeta titulo="Peticiones a la API por día">
                                <ResponsiveContainer width="100%" height={240}>
                                    <AreaChart data={d.accesos_por_dia.map((x) => ({ dia: corto(x.dia), accesos: Number(x.accesos) }))} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                                        <CartesianGrid {...grilla} />
                                        <XAxis dataKey="dia" {...ejes} minTickGap={16} />
                                        <YAxis {...ejes} width={44} tickFormatter={compacto} />
                                        <Tooltip {...tooltip} formatter={(v) => [v, 'Peticiones']} />
                                        <Area type="monotone" dataKey="accesos" stroke={c.serie1} fill={c.serie1} fillOpacity={0.15} strokeWidth={2} activeDot={{ r: 5 }} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </Tarjeta>
                            <Tarjeta titulo="Inicios de sesión por día">
                                <ResponsiveContainer width="100%" height={240}>
                                    <BarChart data={d.accesos_por_dia.map((x) => ({ dia: corto(x.dia), logins: Number(x.logins), fallidos: Number(x.fallidos) }))} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                                        <CartesianGrid {...grilla} />
                                        <XAxis dataKey="dia" {...ejes} minTickGap={16} />
                                        <YAxis {...ejes} width={40} allowDecimals={false} />
                                        <Tooltip {...tooltip} />
                                        <Legend {...leyenda} />
                                        <Bar dataKey="logins" name="Exitosos" stackId="l" fill={c.exito} stroke={c.superficie} strokeWidth={1} />
                                        <Bar dataKey="fallidos" name="Fallidos" stackId="l" fill={c.peligro} stroke={c.superficie} strokeWidth={1} radius={[3, 3, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </Tarjeta>
                            <Tarjeta titulo="Recursos más accedidos">
                                <ResponsiveContainer width="100%" height={Math.max(160, d.recursos_mas_accedidos.length * 32)}>
                                    <BarChart layout="vertical" data={d.recursos_mas_accedidos.map((x) => ({ ...x, total: Number(x.total) }))} margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
                                        <CartesianGrid {...grilla} vertical horizontal={false} />
                                        <XAxis type="number" {...ejes} tickFormatter={compacto} />
                                        <YAxis type="category" dataKey="recurso" {...ejes} width={110} />
                                        <Tooltip {...tooltip} formatter={(v) => [v, 'Peticiones']} />
                                        <Bar dataKey="total" fill={c.serie1} radius={[0, 4, 4, 0]} barSize={16} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </Tarjeta>
                            <Tarjeta titulo="Páginas más visitadas (contador del pie de página)">
                                <ResponsiveContainer width="100%" height={Math.max(160, d.paginas_mas_visitadas.length * 32)}>
                                    <BarChart layout="vertical" data={d.paginas_mas_visitadas.map((x) => ({ ...x, contador: Number(x.contador) }))} margin={{ top: 0, right: 16, left: 0, bottom: 0 }}>
                                        <CartesianGrid {...grilla} vertical horizontal={false} />
                                        <XAxis type="number" {...ejes} tickFormatter={compacto} />
                                        <YAxis type="category" dataKey="ruta" {...ejes} width={130} />
                                        <Tooltip {...tooltip} formatter={(v) => [v, 'Visitas']} />
                                        <Bar dataKey="contador" fill={c.serie2} radius={[0, 4, 4, 0]} barSize={16} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </Tarjeta>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}
