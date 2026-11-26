import { Fragment, isValidElement, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BellRing, FileSpreadsheet, LayoutGrid, List, Loader2, Pencil, Plus, Printer, Search, Trash2 } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { excelDeTabla, imprimir } from '../lib/exportar';
import { validar } from '../lib/validar';
import { useAuth } from '../contexto/AuthContext';
import { Cargando, Confirmar, Encabezado, Formulario, Modal, Paginacion, useMedia, useToast, Vacio } from './ui';

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
 * tarjeta  = (fila) => JSX       → además de la tabla, vista en TARJETAS (ej: objetos con foto). Botón Lista / Tarjetas.
 * alGuardar = async (registro, valores) → después de guardar (ej: subir la foto elegida en el formulario).
 * columnas[].exportar = false    → la columna no va al Excel/PDF (ej: la miniatura de la foto).
 */
const leerVista = (clave) => {
    try {
        return localStorage.getItem(clave);
    } catch {
        return null;
    }
};
export default function CrudPage({
    titulo, descripcion, endpoint, permisos = {}, columnas: columnasTodas, campos = [], filtros = [], inicial = {},
    aFormulario, acciones, botonesExtra, singular = 'registro', femenino = false, recargarClave, aviso, resaltar, tarjeta, alGuardar,
}) {
    const claveVista = `vista:${endpoint}`;
    const [vista, setVista] = useState(() => leerVista(claveVista) ?? 'tarjetas');
    const cambiarVista = (v) => {
        setVista(v);
        try {
            localStorage.setItem(claveVista, v);
        } catch {
            /* sin almacenamiento: solo dura esta visita */
        }
    };
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
        const columnas = columnasTodas.filter((c) => c.exportar !== false);
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
            // Los archivos (ej: una foto) no van en el JSON: los sube alGuardar
            const datosEnvio = Object.fromEntries(Object.entries(valores).filter(([, v]) => !(v instanceof File)).map(([k, v]) => [k, v === '' ? null : v]));
            if (confirmar) datosEnvio.confirmar = true;
            const { data } = form.id ? await api.put(`/${endpoint}/${form.id}`, datosEnvio) : await api.post(`/${endpoint}`, datosEnvio);
            let mensaje = data.message;
            if (alGuardar) {
                try {
                    mensaje = (await alGuardar(data.data, valores)) ?? mensaje;
                } catch (e) {
                    notificar(`Se guardó, pero: ${mensajeError(e)}`, 'alerta');
                }
            }
            notificar(mensaje);
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
    const hayAcciones = puedeEditar || puedeEliminar || acciones;
    const celular = useMedia('(max-width: 767px)'); // en celular cada fila se muestra como tarjeta (la tabla no entra)
    const columnas = columnasTodas;
    const enTarjetas = !!tarjeta && (vista === 'tarjetas' || celular);
    const etiquetaMarca = (marca) => marca && <span className="mb-1 block w-max rounded-full bg-acento px-2 py-0.5 text-[11px] font-bold text-white">{marca}</span>;
    const botonesDe = (fila) => (
        <>
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
        </>
    );

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
                    {tarjeta && !celular && (
                        <div className="flex rounded-tema border border-borde" role="group" aria-label="Forma de ver">
                            {[['tarjetas', LayoutGrid, 'Tarjetas'], ['lista', List, 'Lista']].map(([v, Icono, nombre]) => (
                                <button
                                    key={v}
                                    type="button"
                                    className={`flex items-center gap-1.5 px-3 text-sm ${vista === v ? 'bg-primario-tenue font-semibold text-primario' : 'text-suave hover:bg-superficie-2'}`}
                                    aria-pressed={vista === v}
                                    onClick={() => cambiarVista(v)}
                                >
                                    <Icono className="size-4" aria-hidden /> {nombre}
                                </button>
                            ))}
                        </div>
                    )}
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
                ) : enTarjetas ? (
                    <ul className={`grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4 ${cargando ? 'opacity-60' : ''}`}>
                        {datos.data.map((fila) => {
                            const marca = resaltar?.(fila);
                            return (
                                <li key={fila.id} className={`flex flex-col overflow-hidden rounded-tema border bg-superficie ${marca ? 'border-acento' : 'border-borde'}`}>
                                    {marca && <div className="px-3 pt-3">{etiquetaMarca(marca)}</div>}
                                    <div className="flex-1">{tarjeta(fila)}</div>
                                    {hayAcciones && <div className="flex flex-wrap justify-end gap-1 border-t border-borde p-2 empty:hidden">{botonesDe(fila)}</div>}
                                </li>
                            );
                        })}
                    </ul>
                ) : celular ? (
                    <ul className={`divide-y divide-borde ${cargando ? 'opacity-60' : ''}`}>
                        {datos.data.map((fila) => {
                            const marca = resaltar?.(fila);
                            return (
                                <li key={fila.id} className={`p-4 ${marca ? 'bg-acento/10 [box-shadow:inset_3px_0_0_var(--c-acento)]' : ''}`}>
                                    {etiquetaMarca(marca)}
                                    <dl className="grid grid-cols-[6.5rem_1fr] gap-x-3 gap-y-2 text-sm">
                                        {columnas.map((c) => (
                                            <Fragment key={c.key}>
                                                <dt className="pt-0.5 text-xs font-semibold tracking-wide text-suave uppercase">{c.label}</dt>
                                                <dd className="min-w-0 break-words">{c.render ? c.render(fila) : fila[c.key] ?? '—'}</dd>
                                            </Fragment>
                                        ))}
                                    </dl>
                                    {hayAcciones && <div className="mt-3 flex flex-wrap justify-end gap-1 border-t border-borde pt-2">{botonesDe(fila)}</div>}
                                </li>
                            );
                        })}
                    </ul>
                ) : (
                    <div className={`overflow-x-auto ${cargando ? 'opacity-60' : ''}`}>
                        <table className="tabla w-full text-sm">
                            <thead>
                                <tr>
                                    {columnas.map((c) => <th key={c.key} scope="col">{c.label}</th>)}
                                    {hayAcciones && <th scope="col" className="text-right">Acciones</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {datos.data.map((fila) => {
                                    const marca = resaltar?.(fila);
                                    return (
                                    <tr key={fila.id} className={marca ? 'bg-acento/10 [box-shadow:inset_3px_0_0_var(--c-acento)]' : ''}>
                                        {columnas.map((c, i) => (
                                            <td key={c.key}>
                                                {i === 0 && etiquetaMarca(marca)}
                                                {c.render ? c.render(fila) : fila[c.key] ?? '—'}
                                            </td>
                                        ))}
                                        {hayAcciones && (
                                            <td>
                                                <div className="flex justify-end gap-1">{botonesDe(fila)}</div>
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
