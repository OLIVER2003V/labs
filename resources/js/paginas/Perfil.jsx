import { useState } from 'react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { P, validar } from '../lib/validar';
import { useAuth } from '../contexto/AuthContext';
import { Encabezado, Formulario, Tarjeta, useToast } from '../componentes/ui';

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
            </div>
        </>
    );
}
