import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, CheckCircle2, Clock, UserPlus } from 'lucide-react';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { P, validar } from '../lib/validar';
import { Formulario } from '../componentes/ui';
import { Isotipo } from '../componentes/Logo';
import PanelAccesibilidad from '../componentes/PanelAccesibilidad';
import Footer from '../componentes/Footer';

/**
 * CU1 - Registro público de estudiantes de la FICCT (ej: auxiliares de cátedra).
 * La cuenta queda PENDIENTE: el Encargado de Laboratorios la aprueba desde Usuarios.
 */
const CARRERAS = ['Ingeniería Informática', 'Ingeniería de Sistemas', 'Ingeniería en Redes y Telecomunicaciones', 'Ingeniería en Robótica'];

const CAMPOS = [
    { name: 'codigo', label: 'Registro universitario', requerido: true, patron: /^\d{6,12}$/, mensajePatron: 'Solo números, entre 6 y 12 dígitos.', placeholder: '222058730' },
    { name: 'carrera', label: 'Carrera', tipo: 'select', requerido: true, opciones: CARRERAS },
    { name: 'nombre', label: 'Nombre(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
    { name: 'apellido', label: 'Apellido(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
    { name: 'email', label: 'Correo electrónico', tipo: 'email', requerido: true, max: 100, autocompletar: 'email' },
    { name: 'telefono', label: 'Celular', requerido: true, patron: P.telefono, mensajePatron: 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.' },
    {
        name: 'detalle_registro', label: '¿Para qué necesita los laboratorios?', tipo: 'textarea', requerido: true, min: 10, max: 300, ancho: 'completo',
        placeholder: 'Ej: Soy auxiliar de cátedra de INF513 grupo SA y necesito reservar laboratorio para las prácticas.',
    },
    { name: 'password', label: 'Contraseña', tipo: 'password', requerido: true, patron: P.password, mensajePatron: 'Mínimo 8 caracteres, con letras y números.', autocompletar: 'new-password' },
    { name: 'password_confirmation', label: 'Repita la contraseña', tipo: 'password', requerido: true, igualA: 'password', mensajeIgual: 'Las contraseñas no coinciden.', autocompletar: 'new-password' },
];

export default function Registro() {
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [error, setError] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [enviado, setEnviado] = useState(null);

    const enviar = async () => {
        setError('');
        const e = validar(v, CAMPOS);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            setEnviado((await api.post('/registro', v)).data.message);
        } catch (err) {
            setErrores(erroresCampos(err));
            setError(mensajeError(err));
        } finally {
            setEnviando(false);
        }
    };

    return (
        <div className="flex min-h-screen flex-col">
            <div className="barra-marca" aria-hidden />
            <header className="flex items-center justify-between px-4 py-3 sm:px-8">
                <Link to="/login" className="flex items-center gap-3" aria-label="Volver al inicio de sesión">
                    <Isotipo className="h-11 w-auto" />
                    <span className="font-titulo text-xl tracking-wide uppercase">
                        Laboratorios <span className="text-acento">4<sup className="text-[0.55em]">TO</sup></span> Piso
                    </span>
                </Link>
                <PanelAccesibilidad />
            </header>

            <main className="flex flex-1 items-start justify-center px-4 py-6 sm:py-10">
                <div className="tarjeta w-full max-w-2xl p-6 sm:p-8">
                    {enviado ? (
                        <div className="flex flex-col items-center gap-3 py-6 text-center" role="status">
                            <CheckCircle2 className="size-14 text-exito" aria-hidden />
                            <h1 className="font-titulo text-3xl font-bold uppercase">Solicitud enviada</h1>
                            <p className="max-w-md text-suave">{enviado}</p>
                            <p className="flex items-center gap-2 rounded-tema bg-info-tenue px-3 py-2 text-sm text-info">
                                <Clock className="size-4" aria-hidden /> Use su correo y contraseña para ingresar una vez aprobada.
                            </p>
                            <Link to="/login" className="btn btn-primario mt-2">Ir al inicio de sesión</Link>
                        </div>
                    ) : (
                        <>
                            <p className="font-[Montserrat] text-xs font-semibold tracking-[0.25em] text-acento uppercase">Estudiantes FICCT</p>
                            <h1 className="mt-1 font-titulo text-3xl font-bold uppercase sm:text-4xl">Solicitar una cuenta</h1>
                            <p className="mt-1 mb-6 text-suave">
                                Para estudiantes de la FICCT que necesitan reservar laboratorios (por ejemplo, auxiliares de cátedra).
                                El Encargado de Laboratorios revisará y aprobará su solicitud.
                            </p>
                            {error && <div className="mb-4 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm font-medium text-peligro" role="alert">{error}</div>}
                            <Formulario
                                campos={CAMPOS} valores={v} setValores={setV} errores={errores} onEnviar={enviar} enviando={enviando}
                                textoBoton={<>{enviando ? null : <UserPlus className="size-4" aria-hidden />} Enviar solicitud</>}
                            />
                            <p className="mt-4 text-center text-sm text-suave">
                                <Link to="/login" className="inline-flex items-center gap-1 font-medium text-primario underline">
                                    <ArrowLeft className="size-4" aria-hidden /> Ya tengo cuenta, iniciar sesión
                                </Link>
                            </p>
                        </>
                    )}
                </div>
            </main>
            <Footer />
        </div>
    );
}

