import { useEffect, useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { CalendarRange, ClipboardCheck, FileSpreadsheet, Loader2, Printer, Users, Wrench } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { excelDeReporte, imprimir } from '../lib/exportar';
import { Cargando, Encabezado, Tarjeta, useOpciones, useToast } from '../componentes/ui';
import { useColores } from './Dashboard';

/*
 * CU8 - Reportes (REPOCU, REPRES, REPINC, REPASI, ESTSEM del proyecto 1).
 * El servidor calcula todo (App\Services\Reportes) y devuelve: resumen (tarjetas) + secciones (tablas) + gráfico.
 * La misma respuesta se usa para la pantalla, el Excel y el PDF: lo que se ve es lo que se exporta.
 */
const REPORTES = [
    { tipo: 'ocupacion', titulo: 'Ocupación', icono: CalendarRange, ayuda: '% de uso de cada laboratorio: clases + reservas sobre 15 h hábiles por día, sin feriados.' },
    { tipo: 'reservas', titulo: 'Reservas', icono: ClipboardCheck, ayuda: 'Reservas por estado, tipo, laboratorio y principales solicitantes.' },
    { tipo: 'incidencias', titulo: 'Incidencias y objetos', icono: Wrench, ayuda: 'Fallas por laboratorio, tiempo de solución, equipos con más fallas y objetos encontrados.' },
    { tipo: 'asistencia', titulo: 'Asistencia', icono: Users, ayuda: 'Asistencia y puntualidad de cada auxiliar, turnos cubiertos por permuta y permisos.' },
    { tipo: 'semestre', titulo: 'Semestre', icono: CalendarRange, ayuda: 'Indicadores generales de un semestre: horario base, reservas, incidencias, permutas…' },
];
const SERIES_COLOR = ['primario', 'acento', 'peligro', 'info', 'alerta', 'exito'];
const COLOR_SERIE = { puntual: 'exito', atraso: 'alerta', falta: 'peligro', permiso: 'info', aprobadas: 'exito', resueltas: 'exito', abiertas: 'peligro', otras: 'suave' };

const esNumero = (v) => typeof v === 'number';
const formato = (v, columna) => (esNumero(v) ? `${v.toLocaleString('es-BO')}${columna?.startsWith('%') ? ' %' : ''}` : v);

function Tabla({ s }) {
    const numCol = s.columnas.map((_, i) => s.filas.length > 0 && s.filas.every((f) => esNumero(f[i]) || f[i] === '—'));
    const celdas = (f, negrita) => s.columnas.map((c, i) => (
        <td key={i} className={`${numCol[i] ? 'text-right tabular-nums' : ''} ${negrita ? 'font-bold' : ''}`}>{formato(f[i], c)}</td>
    ));
    return (
        <Tarjeta titulo={s.titulo}>
            <div className="-mx-5 overflow-x-auto">
                <table className="tabla w-full text-sm">
                    <thead><tr>{s.columnas.map((c, i) => <th key={c} scope="col" className={numCol[i] ? 'text-right' : ''}>{c}</th>)}</tr></thead>
                    <tbody>
                        {s.filas.length ? s.filas.map((f, i) => <tr key={i}>{celdas(f)}</tr>)
                            : <tr><td colSpan={s.columnas.length} className="py-6 text-center text-suave">Sin datos para el filtro elegido.</td></tr>}
                    </tbody>
                    {s.total && <tfoot><tr className="bg-primario-tenue">{celdas(s.total, true)}</tr></tfoot>}
                </table>
            </div>
        </Tarjeta>
    );
}

export default function Reportes() {
    const notificar = useToast();
    const c = useColores();
    const semestres = useOpciones('semestres');
    const [tipo, setTipo] = useState('ocupacion');
    const [filtro, setFiltro] = useState({ desde: '', hasta: '', semestre_id: '' });
    const [r, setR] = useState(null);
    const [cargando, setCargando] = useState(false);
    const [descargando, setDescargando] = useState(false);
    const actual = REPORTES.find((x) => x.tipo === tipo);
    const params = tipo === 'semestre'
        ? { semestre_id: filtro.semestre_id || undefined }
        : { desde: filtro.desde || undefined, hasta: filtro.hasta || undefined };
    const clave = JSON.stringify([tipo, params]);

    useEffect(() => {
        if (tipo !== 'semestre' && filtro.desde && filtro.hasta && filtro.desde > filtro.hasta) return;
        let vigente = true; // ignora respuestas viejas si el usuario cambió de reporte o de fechas
        setCargando(true);
        api.get(`/reportes/${tipo}`, { params })
            .then((res) => vigente && setR(res.data))
            .catch((e) => vigente && notificar(mensajeError(e), 'error'))
            .finally(() => vigente && setCargando(false));
        return () => {
            vigente = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [clave]);

    const excel = async () => {
        setDescargando(true);
        try {
            await excelDeReporte(tipo, params);
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setDescargando(false);
        }
    };

    const ejes = { stroke: c.suave, fontSize: 12 };
    const g = r?.grafico;

    return (
        <>
            <Encabezado titulo="Reportes" descripcion="Reportes con filtros, listos para exportar a Excel o imprimir en PDF.">
                <button className="btn btn-secundario" onClick={excel} disabled={!r || descargando}>
                    {descargando ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <FileSpreadsheet className="size-4" aria-hidden />} Excel
                </button>
                <button className="btn btn-primario" onClick={() => imprimir(r)} disabled={!r}>
                    <Printer className="size-4" aria-hidden /> PDF / Imprimir
                </button>
            </Encabezado>

            <div className="mb-4 flex flex-wrap gap-2" role="tablist" aria-label="Tipo de reporte">
                {REPORTES.map(({ tipo: t, titulo, icono: I }) => (
                    <button key={t} role="tab" aria-selected={tipo === t} onClick={() => { setR(null); setTipo(t); }}
                        className={`btn ${tipo === t ? 'btn-primario' : 'btn-secundario'}`}>
                        <I className="size-4" aria-hidden /> {titulo}
                    </button>
                ))}
            </div>

            <div className="tarjeta mb-5 flex flex-wrap items-end gap-4 p-4">
                <p className="w-full text-sm text-suave sm:w-auto sm:flex-1">{actual.ayuda}</p>
                {tipo === 'semestre' ? (
                    <label className="text-sm">
                        <span className="etiqueta">Semestre</span>
                        <select className="campo w-auto" value={filtro.semestre_id} onChange={(e) => setFiltro({ ...filtro, semestre_id: e.target.value })}>
                            <option value="">Semestre activo</option>
                            {semestres.map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                        </select>
                    </label>
                ) : (
                    <>
                        <label className="text-sm"><span className="etiqueta">Desde</span>
                            <input type="date" className="campo w-auto" value={filtro.desde} onChange={(e) => setFiltro({ ...filtro, desde: e.target.value })} />
                        </label>
                        <label className="text-sm"><span className="etiqueta">Hasta</span>
                            <input type="date" className="campo w-auto" value={filtro.hasta} onChange={(e) => setFiltro({ ...filtro, hasta: e.target.value })} />
                        </label>
                        {(filtro.desde || filtro.hasta) && <button className="btn btn-fantasma btn-sm" onClick={() => setFiltro({ ...filtro, desde: '', hasta: '' })}>Semestre activo</button>}
                    </>
                )}
            </div>

            {!r || !c.primario ? <Cargando /> : (
                <div className={`space-y-5 ${cargando ? 'opacity-60' : ''}`} aria-busy={cargando}>
                    <div>
                        <h2 className="text-xl font-bold">{r.titulo}</h2>
                        <p className="text-sm text-suave">{r.subtitulo}</p>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {r.resumen.map((x) => (
                            <div key={x.etiqueta} className="tarjeta border-l-4 border-l-acento p-4">
                                <p className="text-xs font-semibold tracking-wide text-suave uppercase">{x.etiqueta}</p>
                                <p className="mt-1 text-xl font-bold">{esNumero(x.valor) ? x.valor.toLocaleString('es-BO') : x.valor}</p>
                            </div>
                        ))}
                    </div>

                    {g?.datos?.length > 0 && (
                        <Tarjeta titulo="Gráfico">
                            <ResponsiveContainer width="100%" height={300}>
                                <BarChart data={g.datos}>
                                    <CartesianGrid strokeDasharray="3 3" stroke={c.borde} />
                                    <XAxis dataKey="nombre" {...ejes} interval={0} angle={g.datos.length > 6 ? -30 : 0} textAnchor={g.datos.length > 6 ? 'end' : 'middle'} height={g.datos.length > 6 ? 60 : 30} />
                                    <YAxis {...ejes} unit={g.unidad ? ` ${g.unidad}` : ''} />
                                    <Tooltip contentStyle={{ background: c.superficie, border: `1px solid ${c.borde}`, borderRadius: 8, color: c.texto }} />
                                    <Legend />
                                    {g.series.map(([clave, etiqueta], i) => (
                                        <Bar key={clave} dataKey={clave} name={etiqueta} stackId={g.apilado ? 'a' : undefined}
                                            fill={c[COLOR_SERIE[clave] ?? SERIES_COLOR[i % SERIES_COLOR.length]]} radius={g.apilado ? 0 : [4, 4, 0, 0]} />
                                    ))}
                                </BarChart>
                            </ResponsiveContainer>
                        </Tarjeta>
                    )}

                    <div className="grid gap-5 xl:grid-cols-2">
                        {r.secciones.map((s, i) => (
                            <div key={s.titulo} className={s.columnas.length > 5 || i === 0 ? 'xl:col-span-2' : ''}><Tabla s={s} /></div>
                        ))}
                    </div>
                </div>
            )}
        </>
    );
}
