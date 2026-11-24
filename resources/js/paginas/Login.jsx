import { useState } from 'react';
import { CalendarCheck, Eye, EyeOff, Loader2, LogIn, Monitor, ShieldCheck, Wrench } from 'lucide-react';
import { useAuth } from '../contexto/AuthContext';
import { useTema } from '../contexto/TemaContext';
import { mensajeError } from '../lib/api';
import { validar } from '../lib/validar';
import PanelAccesibilidad from '../componentes/PanelAccesibilidad';
import Footer from '../componentes/Footer';

const CAMPOS = [
    { name: 'email', label: 'Correo electrónico', tipo: 'email', requerido: true, max: 100 },
    { name: 'password', label: 'Contraseña', requerido: true, max: 100 },
];

// Cuentas de demostración (una por rol): las envía el servidor solo si APP_DEMO_LOGIN=true
const DEMO = window.APP?.demo ?? [];

export default function Login() {
    const { login } = useAuth();
    const tema = useTema();
    const [v, setV] = useState({ email: '', password: '' });
    const [errores, setErrores] = useState({});
    const [error, setError] = useState('');
    const [enviando, setEnviando] = useState(false);
    const [ver, setVer] = useState(false);

    const enviar = async (e) => {
        e.preventDefault();
        setError('');
        const errs = validar(v, CAMPOS);
        setErrores(errs);
        if (Object.keys(errs).length) return;
        setEnviando(true);
        try {
            const d = await login(v.email.trim(), v.password);
            tema.cargarDeUsuario(d.usuario.preferencias);
        } catch (err) {
            setError(mensajeError(err));
        } finally {
            setEnviando(false);
        }
    };

    return (
        <div className="flex min-h-screen flex-col">
            <div className="absolute top-3 right-3 z-10">
                <PanelAccesibilidad />
            </div>
            <div className="grid flex-1 lg:grid-cols-2">
                <section className="hidden flex-col justify-center gap-8 bg-primario p-12 text-sobre-primario lg:flex">
                    <div className="flex items-center gap-3">
                        <span className="grid size-14 place-items-center rounded-tema bg-sobre-primario/15">
                            <Monitor className="size-8" aria-hidden />
                        </span>
                        <div>
                            <p className="text-2xl font-bold">Laboratorios FICCT</p>
                            <p className="opacity-80">Universidad Autónoma Gabriel René Moreno</p>
                        </div>
                    </div>
                    <h1 className="max-w-md text-4xl leading-tight font-bold">Reservas, horarios e inventario de los laboratorios en un solo lugar.</h1>
                    <ul className="flex max-w-md flex-col gap-4 opacity-95">
                        <li className="flex gap-3"><CalendarCheck className="size-6 shrink-0" aria-hidden /> Consulte la disponibilidad y solicite reservas sin choques de horario.</li>
                        <li className="flex gap-3"><Wrench className="size-6 shrink-0" aria-hidden /> Reporte fallas de equipos y siga su atención.</li>
                        <li className="flex gap-3"><ShieldCheck className="size-6 shrink-0" aria-hidden /> Cada operación queda registrada con su responsable.</li>
                    </ul>
                </section>

                <section className="flex items-center justify-center p-6">
                    <div className="w-full max-w-md">
                        <h2 className="text-3xl font-bold">Iniciar sesión</h2>
                        <p className="mt-1 text-suave">Ingrese con su correo institucional.</p>

                        {error && (
                            <div className="mt-5 rounded-tema border border-peligro bg-peligro-tenue p-3 text-sm font-medium text-peligro" role="alert">
                                {error}
                            </div>
                        )}

                        <form onSubmit={enviar} noValidate className="mt-6 flex flex-col gap-4">
                            <div>
                                <label htmlFor="email" className="etiqueta">Correo electrónico</label>
                                <input
                                    id="email"
                                    type="email"
                                    autoComplete="username"
                                    className={`campo ${errores.email ? 'campo-error' : ''}`}
                                    value={v.email}
                                    onChange={(e) => setV({ ...v, email: e.target.value })}
                                    aria-invalid={!!errores.email}
                                    placeholder="usuario@ficct.edu.bo"
                                />
                                {errores.email && <p className="mt-1 text-sm text-peligro" role="alert">{errores.email}</p>}
                            </div>
                            <div>
                                <label htmlFor="password" className="etiqueta">Contraseña</label>
                                <div className="relative">
                                    <input
                                        id="password"
                                        type={ver ? 'text' : 'password'}
                                        autoComplete="current-password"
                                        className={`campo pr-11 ${errores.password ? 'campo-error' : ''}`}
                                        value={v.password}
                                        onChange={(e) => setV({ ...v, password: e.target.value })}
                                        aria-invalid={!!errores.password}
                                    />
                                    <button type="button" className="absolute top-1/2 right-2 -translate-y-1/2 p-1 text-suave" onClick={() => setVer(!ver)} aria-label={ver ? 'Ocultar contraseña' : 'Mostrar contraseña'}>
                                        {ver ? <EyeOff className="size-5" /> : <Eye className="size-5" />}
                                    </button>
                                </div>
                                {errores.password && <p className="mt-1 text-sm text-peligro" role="alert">{errores.password}</p>}
                            </div>
                            <button type="submit" className="btn btn-primario py-2.5" disabled={enviando}>
                                {enviando ? <Loader2 className="size-5 animate-spin" aria-hidden /> : <LogIn className="size-5" aria-hidden />} Ingresar
                            </button>
                        </form>

                        {DEMO.length > 0 && (
                        <div className="mt-8 rounded-tema border border-dashed border-borde p-4">
                            <p className="text-sm font-semibold">Cuentas de demostración</p>
                            <p className="text-xs text-suave">Contraseña inicial: <code className="font-mono">Ficct2026</code></p>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {DEMO.map(({ rol, email }) => (
                                    <button key={email} type="button" className="btn btn-secundario btn-sm" title={email} onClick={() => setV({ email, password: 'Ficct2026' })}>
                                        {rol}
                                    </button>
                                ))}
                            </div>
                        </div>
                        )}
                    </div>
                </section>
            </div>
            <Footer />
        </div>
    );
}
