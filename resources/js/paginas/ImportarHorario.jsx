import { useEffect, useState } from 'react';
import { CheckCircle2, Download, FileSpreadsheet, Loader2, Upload, XCircle } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { DIAS } from '../lib/formato';
import { Modal, useOpciones, useToast } from '../componentes/ui';

/**
 * CU3 - Importar el horario semestral desde el Excel de la facultad (CARHOR del proyecto 1, ahora en la web).
 * Paso 1: elegir semestre y archivo → "Revisar" (el servidor valida fila por fila SIN guardar).
 * Paso 2: ver qué filas están bien y cuáles tienen error → "Importar" guarda solo las válidas.
 */
export default function ImportarHorario({ abierto, onCerrar, onImportado }) {
    const notificar = useToast();
    const semestres = useOpciones(abierto ? 'semestres' : null);
    const [semestre, setSemestre] = useState('');
    const [archivo, setArchivo] = useState(null);
    const [revision, setRevision] = useState(null);
    const [soloErrores, setSoloErrores] = useState(false);
    const [trabajando, setTrabajando] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!abierto) return;
        setArchivo(null);
        setRevision(null);
        setError('');
        setSoloErrores(false);
    }, [abierto]);

    useEffect(() => {
        if (!semestre && semestres.length) setSemestre(String((semestres.find((s) => s.label.includes('ACTIVO')) ?? semestres[0]).id));
    }, [semestres, semestre]);

    const enviar = async (guardar) => {
        setError('');
        if (!archivo) return setError('Elija el archivo Excel (.xlsx) o CSV.');
        setTrabajando(true);
        const datos = new FormData();
        datos.append('archivo', archivo);
        datos.append('semestre_id', semestre);
        datos.append('guardar', guardar ? '1' : '0');
        try {
            const { data } = await api.post('/horarios/importar', datos, { headers: { 'Content-Type': 'multipart/form-data' } });
            if (guardar) {
                notificar(data.message);
                onImportado();
                onCerrar();
            } else {
                setRevision(data);
                setSoloErrores(data.errores > 0 && data.validas === 0);
            }
        } catch (e) {
            setError(mensajeError(e));
        } finally {
            setTrabajando(false);
        }
    };

    const descargarPlantilla = async () => {
        try {
            const { data } = await api.get('/horarios/plantilla', { responseType: 'blob' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(data);
            a.download = 'plantilla_horario.xlsx';
            a.click();
            URL.revokeObjectURL(a.href);
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    const filas = revision ? revision.filas.filter((f) => !soloErrores || !f.ok) : [];

    return (
        <Modal abierto={abierto} titulo="Importar horario desde Excel" onCerrar={onCerrar} ancho="max-w-5xl">
            <div className="rounded-tema bg-info-tenue p-3 text-sm text-info">
                Una fila por clase con las columnas <strong>LAB · DIA · INICIO · FIN · SIGLA · MATERIA · GRUPO · DOCENTE</strong> (código o correo del docente).
                Las materias nuevas se registran solas. Se rechazan las filas que chocan con otra clase del laboratorio o del docente.
                <button type="button" className="ml-1 inline-flex items-center gap-1 font-semibold underline" onClick={descargarPlantilla}>
                    <Download className="size-4" aria-hidden /> Descargar plantilla
                </button>
            </div>

            <div className="mt-4 grid items-end gap-4 sm:grid-cols-[1fr_2fr_auto]">
                <div>
                    <label htmlFor="imp-semestre" className="etiqueta">Semestre</label>
                    <select id="imp-semestre" className="campo" value={semestre} onChange={(e) => { setSemestre(e.target.value); setRevision(null); }}>
                        {semestres.filter((s) => !s.label.includes('CERRADO')).map((s) => <option key={s.id} value={s.id}>{s.label}</option>)}
                    </select>
                </div>
                <div>
                    <label htmlFor="imp-archivo" className="etiqueta">Archivo (.xlsx o .csv)</label>
                    <input
                        id="imp-archivo"
                        type="file"
                        accept=".xlsx,.csv"
                        className="campo file:mr-3 file:rounded file:border-0 file:bg-primario-tenue file:px-3 file:py-1 file:font-medium file:text-primario"
                        onChange={(e) => { setArchivo(e.target.files[0] ?? null); setRevision(null); }}
                    />
                </div>
                <button className="btn btn-secundario" disabled={trabajando || !archivo} onClick={() => enviar(false)}>
                    {trabajando && !revision ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <FileSpreadsheet className="size-4" aria-hidden />} Revisar archivo
                </button>
            </div>

            {error && <p className="mt-4 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm font-medium text-peligro" role="alert">{error}</p>}

            {revision && (
                <>
                    <div className="mt-5 flex flex-wrap items-center gap-3" aria-live="polite">
                        <span className="flex items-center gap-1.5 rounded-full bg-exito-tenue px-3 py-1 text-sm font-semibold text-exito"><CheckCircle2 className="size-4" aria-hidden /> {revision.validas} lista(s) para importar</span>
                        <span className="flex items-center gap-1.5 rounded-full bg-peligro-tenue px-3 py-1 text-sm font-semibold text-peligro"><XCircle className="size-4" aria-hidden /> {revision.errores} con errores</span>
                        <span className="text-sm text-suave">Semestre {revision.semestre}</span>
                        {revision.errores > 0 && (
                            <label className="ml-auto flex items-center gap-2 text-sm">
                                <input type="checkbox" className="size-4 accent-[var(--c-primario)]" checked={soloErrores} onChange={(e) => setSoloErrores(e.target.checked)} /> Ver solo errores
                            </label>
                        )}
                    </div>
                    <div className="mt-3 max-h-80 overflow-auto rounded-tema border border-borde">
                        <table className="tabla w-full text-sm">
                            <thead className="sticky top-0"><tr><th>Fila</th><th>Lab</th><th>Día</th><th>Horario</th><th>Materia</th><th>Grupo</th><th>Docente</th><th>Resultado</th></tr></thead>
                            <tbody>
                                {filas.map((f) => (
                                    <tr key={f.fila} className={f.ok ? '' : 'bg-peligro-tenue/40'}>
                                        <td>{f.fila}</td>
                                        <td>{f.valores[0]}</td>
                                        <td>{f.ok ? DIAS[f.datos.dia] : f.valores[1]}</td>
                                        <td className="whitespace-nowrap">{f.ok ? `${f.datos.inicio}–${f.datos.fin}` : `${f.valores[2]}–${f.valores[3]}`}</td>
                                        <td>{f.valores[4]}<span className="block text-xs text-suave">{f.valores[5]}</span></td>
                                        <td>{f.valores[6]}</td>
                                        <td>{f.ok ? f.datos.docente : f.valores[7]}</td>
                                        <td className={f.ok ? 'text-exito' : 'font-medium text-peligro'}>
                                            <span className="flex gap-1">{f.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden /> : <XCircle className="mt-0.5 size-4 shrink-0" aria-hidden />} {f.mensaje}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="mt-5 flex flex-wrap items-center justify-end gap-2">
                        {revision.errores > 0 && revision.validas > 0 && <p className="mr-auto text-sm text-suave">Las filas con error no se importan. Puede corregirlas en el Excel y volver a subirlo.</p>}
                        <button className="btn btn-secundario" onClick={onCerrar}>Cancelar</button>
                        <button className="btn btn-primario" disabled={trabajando || revision.validas === 0} onClick={() => enviar(true)}>
                            {trabajando ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Upload className="size-4" aria-hidden />} Importar {revision.validas} clase(s)
                        </button>
                    </div>
                </>
            )}
        </Modal>
    );
}
