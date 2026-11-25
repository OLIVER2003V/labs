import { isValidElement, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BellRing, FileSpreadsheet, Loader2, Pencil, Plus, Printer, Search, Trash2 } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { excelDeTabla, imprimir } from '../lib/exportar';
import { validar } from '../lib/validar';
import { useAuth } from '../contexto/AuthContext';
import { Cargando, Confirmar, Encabezado, Formulario, Modal, Paginacion, useToast, Vacio } from './ui';

/** Convierte lo que muestra una celda (texto o JSX) en texto plano, para exportar */
function aTexto(nodo) {
    if (nodo === null || nodo === undefined || typeof nodo === 'boolean') return '';
    if (typeof nodo === 'string' || typeof nodo === 'number') return String(nodo);
    if (Array.isArray(nodo)) return nodo.map(aTexto).filter(Boolean).join(' ');
    if (isValidElement(nodo)) {
        if (typeof nodo.type === 'function') return aTexto(nodo.props.children ?? nodo.props.valor ?? '');
        return aTexto(nodo.props.children);
    }
    return '';
}

/**
 * Pantalla CRUD genérica: tabla con búsqueda, filtros y paginación + formulario en modal.
 * Cada módulo solo describe sus columnas y campos (ver paginas/Modulos.jsx).
 * Los botones se muestran según los permisos del rol (puede('x.crear')), y el servidor vuelve a verificarlos.
 */
/*
 * aviso    = { texto, filtro }  → cartel arriba de la tabla ("Tiene 1 permuta por aprobar") con el botón "Ver solo esas",
 *                                 que aplica ese filtro (ej: { accion: 1 }). Explica el número rojo del menú.
 * resaltar = (fila) => texto | null → si devuelve texto, la fila se resalta con esa etiqueta (ej: "Requiere su acción").
 */
export default function CrudPage({
    titulo, descripcion, endpoint, permisos = {}, columnas, campos, filtros = [], inicial = {},
    aFormulario, acciones, botonesExtra, singular = 'registro', femenino = false, recargarClave, aviso, resaltar,
}) {
    const { puede } = useAuth();
    const notificar = useToast();
    const [params, setParams] = useSearchParams();
    const [datos, setDatos] = useState(null);
    const [cargando, setCargando] = useState(true);
    const [q, setQ] = useState(params.get('q') ?? '');
    const [filtro, setFiltro] = useState({});
    const [pagina, setPagina] = useState(1);
    const [form, setForm] = useState(null); // null = cerrado; {} = nuevo; {id,...} = editar
    const [valores, setValores] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    const [borrar, setBorrar] = useState(null);
    const [confirmacion, setConfirmacion] = useState(null); // { message, detalle[] } cuando el servidor pide confirmar
    const [alternativas, setAlternativas] = useState(null); // ante un choque: otros laboratorios libres [{id, codigo, nombre, campo}]
    const [exportando, setExportando] = useState(false);

    /**
     * Exporta TODOS los registros del filtro actual (no solo la página visible), en cuadros:
     *   formato 'excel' → .xlsx con encabezado de color y bordes (lo arma el servidor)
     *   formato 'pdf'   → hoja de reporte para imprimir o "Guardar como PDF"
     */
    const exportar = async (formato) => {
        setExportando(formato);
        try {
            let filas = [];
            for (let p = 1, ultima = 1; p <= ultima && p <= 50; p++) {
                const { data } = await api.get(`/${endpoint}`, { params: { q: q || undefined, page: p, per_page: 100, ...filtro } });
                filas = filas.concat(data.data);
                ultima = data.last_page;
            }
            const tabla = {
                titulo,
                subtitulo: [
                    q && `Búsqueda: "${q}"`,
                    ...filtros.filter((f) => filtro[f.name]).map((f) => `${f.label}: ${String(filtro[f.name]).replaceAll('_', ' ')}`),
                    `${filas.length} registro(s)`,
                ].filter(Boolean).join(' · '),
                columnas: columnas.map((c) => c.label),
                filas: filas.map((f) => columnas.map((c) => aTexto(c.render ? c.render(f) : f[c.key] ?? '').replace(/\s+/g, ' ').trim())),
            };
            if (formato === 'excel') {
                await excelDeTabla(tabla);
                notificar(`Se exportaron ${filas.length} registro(s) a Excel.`);
            } else {
                imprimir({ titulo: tabla.titulo, subtitulo: tabla.subtitulo, secciones: [{ columnas: tabla.columnas, filas: tabla.filas }] });
            }
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setExportando(false);
        }
    };

    const cargar = useCallback(async () => {
        setCargando(true);
        try {
            const { data } = await api.get(`/${endpoint}`, { params: { q: q || undefined, page: pagina, ...filtro } });
            setDatos(data);
        } catch (e) {
            notificar(mensajeError(e), 'error');
        } finally {
            setCargando(false);
        }
    }, [endpoint, q, pagina, filtro, notificar]);

    // Búsqueda con retardo (no consulta en cada tecla)
    useEffect(() => {
        const t = setTimeout(cargar, 300);
        return () => clearTimeout(t);
    }, [cargar, recargarClave]);

    useEffect(() => {
        const nuevo = params.get('q');
        if (nuevo !== null && nuevo !== q) setQ(nuevo);
        // ?nueva=1&laboratorio_id=2&fecha=... abre el formulario ya completado
        if (params.get('nueva') && permisos.crear && puede(permisos.crear)) {
            const pre = Object.fromEntries([...params.entries()].filter(([k]) => campos.some((c) => c.name === k)));
            setErrores({});
            setForm({});
            setValores({ ...inicial, ...pre });
            setParams({}, { replace: true });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [params]);

    const camposForm = (form?.id ? campos.filter((c) => !c.soloCrear) : campos.filter((c) => !c.soloEditar)).map((c) =>
        form?.id && c.requeridoAlCrear ? { ...c, requerido: false, ayuda: c.ayudaEditar ?? c.ayuda } : c,
    );

    const abrir = (fila = null) => {
        setErrores({});
        setAlternativas(null);
        setForm(fila ?? {});
        setValores(fila ? (aFormulario ? aFormulario(fila) : Object.fromEntries(campos.map((c) => [c.name, fila[c.name] ?? '']))) : { ...inicial });
    };

    const guardar = async (confirmar = false) => {
        const e = validar(valores, camposForm);
        setErrores(e);
        if (Object.keys(e).length) {
            notificar('Revise los campos marcados en rojo.', 'alerta');
            return;
        }
        setEnviando(true);
        setAlternativas(null);
        try {
            const datosEnvio = Object.fromEntries(Object.entries(valores).map(([k, v]) => [k, v === '' ? null : v]));
            if (confirmar) datosEnvio.confirmar = true;
            const { data } = form.id ? await api.put(`/${endpoint}/${form.id}`, datosEnvio) : await api.post(`/${endpoint}`, datosEnvio);
            notificar(data.message);
            setForm(null);
            cargar();
        } catch (err) {
            // El servidor avisa que la acción tiene consecuencias (ej: cancelar reservas) y pide confirmar
            if (err.response?.status === 409 && err.response.data?.requiere_confirmacion) {
                setConfirmacion(err.response.data);
                return;
            }
            setErrores(erroresCampos(err));
            // Choque de horario: el servidor propone otros laboratorios libres → se muestran como botones
            if (err.response?.data?.alternativas) {
                setAlternativas({ mensaje: err.response.data.message, opciones: err.response.data.alternativas });
                return;
            }
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const eliminar = async () => {
        try {
            const { data } = await api.delete(`/${endpoint}/${borrar.id}`);
            notificar(data.message);
            setBorrar(null);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
            setBorrar(null);
        }
    };

    const puedeEditar = permisos.editar && puede(permisos.editar);
    const puedeEliminar = permisos.eliminar && puede(permisos.eliminar);

    return (
        <>
            <Encabezado titulo={titulo} descripcion={descripcion}>
                {botonesExtra?.(cargar)}
                <button className="btn btn-secundario" onClick={() => exportar('excel')} disabled={!!exportando || !datos?.total} title="Descargar en Excel (con formato)">
                    {exportando === 'excel' ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <FileSpreadsheet className="size-4" aria-hidden />} Excel
                </button>
                <button className="btn btn-secundario" onClick={() => exportar('pdf')} disabled={!!exportando || !datos?.total} title="Imprimir o guardar como PDF">
                    {exportando === 'pdf' ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Printer className="size-4" aria-hidden />} PDF
                </button>
                {permisos.crear && puede(permisos.crear) && (
                    <button className="btn btn-primario" onClick={() => abrir()}>
                        <Plus className="size-4" aria-hidden /> {femenino ? 'Nueva' : 'Nuevo'} {singular}
                    </button>
                )}
            </Encabezado>

            {aviso?.texto && (() => {
                const activo = Object.entries(aviso.filtro ?? {}).every(([k, v]) => String(filtro[k] ?? '') === String(v));
                return (
                    <div className="mb-4 flex flex-wrap items-center gap-3 rounded-tema border border-acento bg-acento/10 p-3 text-sm" role="status">
                        <BellRing className="size-5 shrink-0 text-acento" aria-hidden />
                        <p className="min-w-0 flex-1 font-medium">{aviso.texto}</p>
                        {aviso.filtro && (
                            <button
                                className={`btn btn-sm ${activo ? 'btn-secundario' : 'btn-primario'}`}
                                onClick={() => {
                                    setFiltro((x) => (activo
                                        ? Object.fromEntries(Object.entries(x).filter(([k]) => !(k in aviso.filtro)))
                                        : { ...x, ...aviso.filtro }));
                                    setPagina(1);
                                }}
                            >
                                {activo ? 'Ver todas' : 'Ver solo esas'}
                            </button>
                        )}
                    </div>
                );
            })()}

            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <div className="relative min-w-0 flex-1 basis-56">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
                        <input
                            className="campo pl-9"
                            placeholder="Buscar…"
                            aria-label={`Buscar en ${titulo}`}
                            value={q}
                            onChange={(e) => {
                                setQ(e.target.value);
                                setPagina(1);
                                if (params.get('q')) setParams({});
                            }}
                        />
                    </div>
                    {filtros.map((f) => (
                        <select
                            key={f.name}
                            className="campo w-auto"
                            aria-label={f.label}
                            value={filtro[f.name] ?? ''}
                            onChange={(e) => {
                                setFiltro((x) => ({ ...x, [f.name]: e.target.value || undefined }));
                                setPagina(1);
                            }}
                        >
                            <option value="">{f.textoTodos ?? `${f.label}: todos`}</option>
                            {f.opciones.map((o) => {
                                const [v, l] = typeof o === 'object' ? [o.value, o.label] : [o, o.replaceAll('_', ' ')];
                                return <option key={v} value={v}>{l}</option>;
                            })}
                        </select>
                    ))}
                </div>

                {cargando && !datos ? (
                    <Cargando />
                ) : !datos?.data?.length ? (
                    <Vacio texto={q ? `No se encontraron resultados para "${q}".` : undefined} />
                ) : (
                    <div className={`overflow-x-auto ${cargando ? 'opacity-60' : ''}`}>
                        <table className="tabla w-full text-sm">
                            <thead>
                                <tr>
                                    {columnas.map((c) => <th key={c.key} scope="col">{c.label}</th>)}
                                    {(puedeEditar || puedeEliminar || acciones) && <th scope="col" className="text-right">Acciones</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {datos.data.map((fila) => {
                                    const marca = resaltar?.(fila);
                                    return (
                                    <tr key={fila.id} className={marca ? 'bg-acento/10 [box-shadow:inset_3px_0_0_var(--c-acento)]' : ''}>
                                        {columnas.map((c, i) => (
                                            <td key={c.key}>
                                                {i === 0 && marca && <span className="mb-1 block w-max rounded-full bg-acento px-2 py-0.5 text-[11px] font-bold text-white">{marca}</span>}
                                                {c.render ? c.render(fila) : fila[c.key] ?? '—'}
                                            </td>
                                        ))}
                                        {(puedeEditar || puedeEliminar || acciones) && (
                                            <td>
                                                <div className="flex justify-end gap-1">
                                                    {acciones?.(fila, cargar)}
                                                    {puedeEditar && !fila.deleted_at && (
                                                        <button className="btn btn-fantasma btn-sm" onClick={() => abrir(fila)} title="Editar" aria-label={`Editar ${singular} ${fila.id}`}>
                                                            <Pencil className="size-4" />
                                                        </button>
                                                    )}
                                                    {puedeEliminar && !fila.deleted_at && (
                                                        <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setBorrar(fila)} title="Eliminar" aria-label={`Eliminar ${singular} ${fila.id}`}>
                                                            <Trash2 className="size-4" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={datos} onPagina={setPagina} />
            </div>

            <Modal abierto={!!form} titulo={form?.id ? `Editar ${singular}` : `${femenino ? 'Nueva' : 'Nuevo'} ${singular}`} onCerrar={() => setForm(null)} ancho="max-w-2xl">
                {alternativas && (
                    <div className="mb-4 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm" role="alert">
                        <p className="font-medium text-peligro">{alternativas.mensaje}</p>
                        {alternativas.opciones.length ? (
                            <>
                                <p className="mt-2">Estos laboratorios están libres en esa franja. Elija uno para usarlo:</p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {alternativas.opciones.map((o) => (
                                        <button
                                            key={o.id}
                                            type="button"
                                            className="btn btn-secundario btn-sm"
                                            title={o.nombre}
                                            onClick={() => {
                                                setValores((v) => ({ ...v, [o.campo]: String(o.id) }));
                                                setAlternativas(null);
                                                notificar(`Se eligió ${o.codigo}. Revise y presione Guardar.`);
                                            }}
                                        >
                                            {o.codigo}
                                        </button>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <p className="mt-2">No hay otros laboratorios libres en esa franja: pruebe otro horario.</p>
                        )}
                    </div>
                )}
                <Formulario campos={camposForm} valores={valores} setValores={setValores} errores={errores} onEnviar={() => guardar()} enviando={enviando} onCancelar={() => setForm(null)} />
            </Modal>

            <Modal abierto={!!confirmacion} titulo="Confirmar cambios" onCerrar={() => setConfirmacion(null)} ancho="max-w-2xl">
                <p className="rounded-tema border border-alerta bg-alerta-tenue p-3 text-sm font-medium">{confirmacion?.message}</p>
                <ul className="mt-3 max-h-64 list-disc overflow-y-auto pl-5 text-sm text-suave">
                    {confirmacion?.detalle?.map((d) => <li key={d} className="py-0.5">{d}</li>)}
                </ul>
                <div className="mt-5 flex justify-end gap-2">
                    <button className="btn btn-secundario" onClick={() => setConfirmacion(null)}>Volver</button>
                    <button
                        className="btn btn-peligro"
                        disabled={enviando}
                        onClick={async () => {
                            setConfirmacion(null);
                            await guardar(true);
                        }}
                    >
                        Continuar y notificar
                    </button>
                </div>
            </Modal>

            <Confirmar
                abierto={!!borrar}
                titulo={`Eliminar ${singular}`}
                mensaje="Esta acción no se puede deshacer desde esta pantalla. ¿Desea continuar?"
                textoBoton="Eliminar"
                onConfirmar={eliminar}
                onCerrar={() => setBorrar(null)}
            />
        </>
    );
}
