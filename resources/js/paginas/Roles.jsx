import { Fragment, useEffect, useState } from 'react';
import { Lock, Pencil, Plus, Save, Trash2 } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import { useAuth } from '../contexto/AuthContext';
import { Cargando, Confirmar, Encabezado, Formulario, Modal, useToast } from '../componentes/ui';

const CAMPOS = [
    { name: 'codigo', label: 'Código', requerido: true, patron: /^[A-Za-z]{2,10}$/, mensajePatron: 'De 2 a 10 letras (ej: SEC).', mayusculas: true },
    { name: 'nombre', label: 'Nombre', requerido: true, min: 3, max: 60 },
    { name: 'descripcion', label: 'Descripción', tipo: 'textarea', max: 200, ancho: 'completo' },
];

/**
 * MATRIZ DE ACCESO: filas = permisos (recurso.acción), columnas = roles.
 * Marcar/desmarcar y guardar actualiza la tabla rol_permiso. El menú y las rutas se ajustan solos.
 */
export default function Roles() {
    const { puede, refrescar } = useAuth();
    const notificar = useToast();
    const [datos, setDatos] = useState(null);
    const [matriz, setMatriz] = useState({}); // rolId → Set(permisoId)
    const [cambiados, setCambiados] = useState(new Set());
    const [form, setForm] = useState(null);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [borrar, setBorrar] = useState(null);
    const editable = puede('roles.editar');

    const cargar = async () => {
        const { data } = await api.get('/roles');
        setDatos(data);
        setMatriz(Object.fromEntries(data.roles.map((r) => [r.id, new Set(r.permisos)])));
        setCambiados(new Set());
    };
    useEffect(() => {
        cargar().catch((e) => notificar(mensajeError(e), 'error'));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const alternar = (rolId, permisoId) => {
        setMatriz((m) => {
            const s = new Set(m[rolId]);
            s.has(permisoId) ? s.delete(permisoId) : s.add(permisoId);
            return { ...m, [rolId]: s };
        });
        setCambiados((c) => new Set(c).add(rolId));
    };

    const guardar = async () => {
        try {
            for (const rolId of cambiados) {
                const { data } = await api.put(`/roles/${rolId}/permisos`, { permisos: [...matriz[rolId]] });
                notificar(data.message);
            }
            await cargar();
            await refrescar(); // por si cambió el menú del propio usuario
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    const guardarRol = async () => {
        const e = validar(v, CAMPOS);
        setErrores(e);
        if (Object.keys(e).length) return;
        try {
            const { data } = form.id ? await api.put(`/roles/${form.id}`, v) : await api.post('/roles', v);
            notificar(data.message);
            setForm(null);
            cargar();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        }
    };

    if (!datos) return <Cargando />;

    return (
        <>
            <Encabezado titulo="Roles y matriz de acceso" descripcion="Defina qué puede hacer cada rol. Los cambios afectan el menú y las rutas protegidas de la API.">
                {editable && (
                    <>
                        <button className="btn btn-secundario" onClick={() => { setV({}); setErrores({}); setForm({}); }}>
                            <Plus className="size-4" aria-hidden /> Nuevo rol
                        </button>
                        <button className="btn btn-primario" disabled={!cambiados.size} onClick={guardar}>
                            <Save className="size-4" aria-hidden /> Guardar cambios {cambiados.size ? `(${cambiados.size})` : ''}
                        </button>
                    </>
                )}
            </Encabezado>

            <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                {datos.roles.map((r) => (
                    <div key={r.id} className="tarjeta p-3">
                        <div className="flex items-start justify-between gap-1">
                            <p className="font-semibold">{r.nombre}</p>
                            {r.es_sistema ? <Lock className="size-4 text-suave" aria-label="Rol de sistema" /> : editable && (
                                <span className="flex">
                                    <button className="btn btn-fantasma btn-sm p-1" aria-label={`Editar rol ${r.nombre}`} onClick={() => { setV({ codigo: r.codigo, nombre: r.nombre, descripcion: r.descripcion ?? '' }); setErrores({}); setForm(r); }}><Pencil className="size-4" /></button>
                                    <button className="btn btn-fantasma btn-sm p-1 text-peligro" aria-label={`Eliminar rol ${r.nombre}`} onClick={() => setBorrar(r)}><Trash2 className="size-4" /></button>
                                </span>
                            )}
                        </div>
                        <p className="text-xs text-suave">{r.codigo} · {r.usuarios_count} usuario(s) · {matriz[r.id]?.size ?? 0} permisos</p>
                    </div>
                ))}
            </div>

            <div className="tarjeta overflow-x-auto">
                <table className="tabla w-full text-sm">
                    <caption className="solo-lector">Matriz de permisos por rol</caption>
                    <thead>
                        <tr>
                            <th scope="col">Permiso</th>
                            {datos.roles.map((r) => <th key={r.id} scope="col" className="text-center">{r.codigo}</th>)}
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(datos.permisos).map(([recurso, lista]) => (
                            <Fragment key={recurso}>
                                <tr>
                                    <th colSpan={datos.roles.length + 1} scope="colgroup" className="!bg-primario-tenue !text-primario">{recurso}</th>
                                </tr>
                                {lista.map((p) => (
                                    <tr key={p.id}>
                                        <td>
                                            <code className="text-xs font-semibold">{p.codigo}</code>
                                            <span className="block text-xs text-suave">{p.descripcion}</span>
                                        </td>
                                        {datos.roles.map((r) => (
                                            <td key={r.id} className="text-center">
                                                <input
                                                    type="checkbox"
                                                    className="size-5 accent-[var(--c-primario)]"
                                                    checked={matriz[r.id]?.has(p.id) ?? false}
                                                    disabled={!editable || r.es_sistema}
                                                    onChange={() => alternar(r.id, p.id)}
                                                    aria-label={`${p.codigo} para ${r.nombre}`}
                                                />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <Modal abierto={!!form} titulo={form?.id ? 'Editar rol' : 'Nuevo rol'} onCerrar={() => setForm(null)}>
                <Formulario campos={CAMPOS} valores={v} setValores={setV} errores={errores} onEnviar={guardarRol} onCancelar={() => setForm(null)} />
            </Modal>
            <Confirmar
                abierto={!!borrar}
                titulo="Eliminar rol"
                mensaje={`¿Eliminar el rol ${borrar?.nombre}? Solo es posible si ningún usuario lo tiene asignado.`}
                textoBoton="Eliminar"
                onCerrar={() => setBorrar(null)}
                onConfirmar={async () => {
                    try {
                        notificar((await api.delete(`/roles/${borrar.id}`)).data.message);
                        cargar();
                    } catch (e) {
                        notificar(mensajeError(e), 'error');
                    }
                    setBorrar(null);
                }}
            />
        </>
    );
}
