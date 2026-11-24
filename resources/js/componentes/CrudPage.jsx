import { isValidElement, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Download, Loader2, Pencil, Plus, Search, Trash2 } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
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
export default function CrudPage({
    titulo, descripcion, endpoint, permisos = {}, columnas, campos, filtros = [], inicial = {},
    aFormulario, acciones, botonesExtra, singular = 'registro', femenino = false, recargarClave,
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
    const [exportando, setExportando] = useState(false);

    /** Exporta a CSV (Excel) TODOS los registros del filtro actual, no solo la página visible */
    const exportar = async () => {
        setExportando(true);
        try {
            let filas = [];
            for (let p = 1, ultima = 1; p <= ultima && p <= 50; p++) {
                const { data } = await api.get(`/${endpoint}`, { params: { q: q || undefined, page: p, per_page: 100, ...filtro } });
                filas = filas.concat(data.data);
                ultima = data.last_page;
            }
            const celda = (v) => `"${String(v).replace(/"/g, '""').replace(/\s+/g, ' ').trim()}"`;
            const lineas = [columnas.map((c) => celda(c.label)).join(';')]
                .concat(filas.map((f) => columnas.map((c) => celda(aTexto(c.render ? c.render(f) : f[c.key] ?? ''))).join(';')));
            // ﻿ (BOM) para que Excel lea bien las tildes; separador ";" (configuración regional de Bolivia)
            const blob = new Blob(['﻿' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `${endpoint}_${new Date().toISOString().slice(0, 10)}.csv`;
            a.click();
            URL.revokeObjectURL(a.href);
            notificar(`Se exportaron ${filas.length} registro(s).`);
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
        setForm(fila ?? {});
        setValores(fila ? (aFormulario ? aFormulario(fila) : Object.fromEntries(campos.map((c) => [c.name, fila[c.name] ?? '']))) : { ...inicial });
    };

    const guardar = async () => {
        const e = validar(valores, camposForm);
        setErrores(e);
        if (Object.keys(e).length) {
            notificar('Revise los campos marcados en rojo.', 'alerta');
            return;
        }
        setEnviando(true);
        try {
            const datosEnvio = Object.fromEntries(Object.entries(valores).map(([k, v]) => [k, v === '' ? null : v]));
            const { data } = form.id ? await api.put(`/${endpoint}/${form.id}`, datosEnvio) : await api.post(`/${endpoint}`, datosEnvio);
            notificar(data.message);
            setForm(null);
            cargar();
        } catch (err) {
            setErrores(erroresCampos(err));
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
                <button className="btn btn-secundario" onClick={exportar} disabled={exportando || !datos?.total} title="Descargar en formato CSV (Excel)">
                    {exportando ? <Loader2 className="size-4 animate-spin" aria-hidden /> : <Download className="size-4" aria-hidden />} Exportar
                </button>
                {permisos.crear && puede(permisos.crear) && (
                    <button className="btn btn-primario" onClick={() => abrir()}>
                        <Plus className="size-4" aria-hidden /> {femenino ? 'Nueva' : 'Nuevo'} {singular}
                    </button>
                )}
            </Encabezado>

            <div className="tarjeta overflow-hidden">
                <div className="flex flex-wrap gap-3 border-b border-borde p-3">
                    <div className="relative min-w-56 flex-1">
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
                                {datos.data.map((fila) => (
                                    <tr key={fila.id}>
                                        {columnas.map((c) => <td key={c.key}>{c.render ? c.render(fila) : fila[c.key] ?? '—'}</td>)}
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
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Paginacion datos={datos} onPagina={setPagina} />
            </div>

            <Modal abierto={!!form} titulo={form?.id ? `Editar ${singular}` : `${femenino ? 'Nueva' : 'Nuevo'} ${singular}`} onCerrar={() => setForm(null)} ancho="max-w-2xl">
                <Formulario campos={camposForm} valores={valores} setValores={setValores} errores={errores} onEnviar={guardar} enviando={enviando} onCancelar={() => setForm(null)} />
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
