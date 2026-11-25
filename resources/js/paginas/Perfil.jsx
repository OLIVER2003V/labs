import { useCallback, useEffect, useState } from 'react';
import { LogOut, MonitorSmartphone } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { P, validar } from '../lib/validar';
import { useAuth } from '../contexto/AuthContext';
import { fechaHora } from '../lib/formato';
import { Cargando, Encabezado, Formulario, Tarjeta, useToast } from '../componentes/ui';

const CAMPOS_PERFIL = [
    { name: 'nombre', label: 'Nombre(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
    { name: 'apellido', label: 'Apellido(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
    { name: 'email', label: 'Correo electrónico', tipo: 'email', requerido: true, max: 100 },
    { name: 'telefono', label: 'Teléfono', patron: P.telefono, mensajePatron: 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.' },
];
const CAMPOS_PASS = [
    { name: 'password_actual', label: 'Contraseña actual', tipo: 'password', requerido: true, ancho: 'completo', autocompletar: 'current-password' },
    { name: 'password', label: 'Nueva contraseña', tipo: 'password', requerido: true, patron: P.password, mensajePatron: 'Mínimo 8 caracteres, con letras y números.', autocompletar: 'new-password' },
    { name: 'password_confirmation', label: 'Repita la nueva contraseña', tipo: 'password', requerido: true, igualA: 'password', mensajeIgual: 'Las contraseñas no coinciden.', autocompletar: 'new-password' },
];

function Seccion({ titulo, campos, inicial, enviar, textoBoton, limpiar }) {
    const notificar = useToast();
    const [v, setV] = useState(inicial);
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    return (
        <Tarjeta titulo={titulo}>
            <Formulario
                campos={campos} valores={v} setValores={setV} errores={errores} enviando={enviando} textoBoton={textoBoton}
                onEnviar={async () => {
                    const e = validar(v, campos);
                    setErrores(e);
                    if (Object.keys(e).length) return;
                    setEnviando(true);
                    try {
                        notificar((await enviar(v)).data.message);
                        if (limpiar) setV(inicial);
                    } catch (err) {
                        setErrores(erroresCampos(err));
                        notificar(mensajeError(err), 'error');
                    } finally {
                        setEnviando(false);
                    }
                }}
            />
        </Tarjeta>
    );
}

/** CU10: dispositivos donde la cuenta tiene la sesión abierta; se puede cerrar cualquiera o todas las demás */
function SesionesActivas() {
    const notificar = useToast();
    const [sesiones, setSesiones] = useState(null);
    const cargar = useCallback(() => {
        api.get('/perfil/sesiones').then((r) => setSesiones(r.data)).catch(() => setSesiones([]));
    }, []);
    useEffect(cargar, [cargar]);

    const cerrar = async (url) => {
        try {
            notificar((await api.delete(url)).data.message);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };
    const otras = sesiones?.filter((x) => !x.actual).length ?? 0;

    return (
        <Tarjeta
            titulo="Sesiones activas"
            acciones={otras > 0 && <button className="btn btn-secundario btn-sm" onClick={() => cerrar('/perfil/sesiones')}><LogOut className="size-4" aria-hidden /> Cerrar las demás ({otras})</button>}
        >
            <p className="mb-3 text-sm text-suave">Si ve un dispositivo que no reconoce, ciérrelo y cambie su contraseña (al cambiarla se cierran todas las demás sesiones).</p>
            {sesiones === null ? <Cargando /> : (
                <ul className="divide-y divide-borde">
                    {sesiones.map((x) => (
                        <li key={x.id} className="flex flex-wrap items-center gap-3 py-3">
                            <MonitorSmartphone className="size-6 shrink-0 text-primario" aria-hidden />
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">{x.dispositivo === 'spa' ? 'Sesión anterior' : x.dispositivo} {x.actual && <span className="ml-1 rounded-full bg-exito-tenue px-2 py-0.5 text-xs font-semibold text-exito">Este dispositivo</span>}</p>
                                <p className="text-xs text-suave">Inició: {fechaHora(x.iniciada)} · Último uso: {x.ultimo_uso ? fechaHora(x.ultimo_uso) : '—'} · Expira: {x.expira ? fechaHora(x.expira) : '—'}</p>
                            </div>
                            {!x.actual && <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => cerrar(`/perfil/sesiones/${x.id}`)}>Cerrar</button>}
                        </li>
                    ))}
                </ul>
            )}
        </Tarjeta>
    );
}

export default function Perfil() {
    const { usuario, refrescar } = useAuth();
    return (
        <>
            <Encabezado titulo="Mi perfil" descripcion={`${usuario.rol?.nombre} · Código ${usuario.codigo}`} />
            <div className="grid gap-5 lg:grid-cols-2">
                <Seccion
                    titulo="Datos personales"
                    campos={CAMPOS_PERFIL}
                    inicial={{ nombre: usuario.nombre, apellido: usuario.apellido, email: usuario.email, telefono: usuario.telefono ?? '' }}
                    textoBoton="Guardar datos"
                    enviar={async (v) => {
                        const r = await api.put('/perfil', v);
                        await refrescar();
                        return r;
                    }}
                />
                <Seccion
                    titulo="Cambiar contraseña"
                    campos={CAMPOS_PASS}
                    inicial={{ password_actual: '', password: '', password_confirmation: '' }}
                    textoBoton="Cambiar contraseña"
                    limpiar
                    enviar={(v) => api.put('/perfil/password', v)}
                />
                <div className="lg:col-span-2"><SesionesActivas /></div>
            </div>
        </>
    );
}
