import { useEffect, useRef, useState } from 'react';
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import {
    ArrowLeftRight, BarChart3, BookOpen, Building2, CalendarCheck, CalendarDays, CalendarRange, CalendarSearch, CalendarX, Circle, Clock,
    CreditCard, FileSpreadsheet, Gavel, History, Home, ListChecks, LogOut, Menu as MenuIcon, MessagesSquare, Monitor, PackageSearch, ScrollText, Search, ShieldCheck,
    Tags, UserCheck, UserCircle2, Users, Wallet, Wrench, X,
} from 'lucide-react';
import api from '../lib/api';
import { useAuth } from '../contexto/AuthContext';
import PanelAccesibilidad from './PanelAccesibilidad';
import Footer from './Footer';
import ErrorBoundary from './ErrorBoundary';
import { LogoHorizontal } from './Logo';
import Notificaciones from './Notificaciones';

// Íconos disponibles para el menú (el nombre se guarda en la columna menus.icono)
const ICONOS = {
    ArrowLeftRight, BarChart3, BookOpen, Building2, CalendarCheck, CalendarDays, CalendarRange, CalendarSearch, CalendarX, Clock, CreditCard,
    FileSpreadsheet, Gavel, History, Home, ListChecks, MessagesSquare, Monitor, PackageSearch, ScrollText, ShieldCheck, Tags, UserCheck, Users, Wallet, Wrench,
};

/** Ícono por nombre (el nombre viene de la tabla menus) */
export const Icono = ({ nombre, ...p }) => {
    const I = ICONOS[nombre] ?? Circle;
    return <I aria-hidden {...p} />;
};

/** Qué significa el número rojo de cada opción del menú (se muestra al pasar el mouse y lo lee el lector de pantalla) */
export const SIGNIFICADO_AVISO = {
    '/usuarios': 'solicitud(es) de cuenta por aprobar',
    '/mensajes': 'mensaje(s) sin leer',
    '/permutas': 'permuta(s) esperan su respuesta o aprobación',
    '/multas': 'multa(s) por pagar',
    '/auditoria': 'cambio(s) hechos directamente en la base de datos (últimas 24 h)',
};

/** Barra lateral: se arma con el menú que devuelve la API según los permisos del rol */
function MenuLateral({ onNavegar }) {
    const { menu, avisos } = useAuth();
    const grupos = menu.reduce((acc, m) => ((acc[m.grupo] ??= []).push(m), acc), {});
    return (
        <nav aria-label="Menú principal" className="flex flex-col gap-5 p-4">
            {Object.entries(grupos).map(([grupo, items]) => (
                <div key={grupo}>
                    <p className="mb-1.5 px-3 text-xs font-semibold tracking-wider text-suave uppercase">{grupo}</p>
                    <ul className="flex flex-col gap-0.5">
                        {items.map((m) => (
                            <li key={m.ruta}>
                                <NavLink
                                    to={m.ruta}
                                    end={m.ruta === '/' || m.ruta === '/pagos'}
                                    onClick={onNavegar}
                                    className={({ isActive }) =>
                                        `flex items-center gap-3 rounded-tema px-3 py-2 text-sm font-medium transition ${
                                            isActive ? 'bg-primario text-sobre-primario shadow-sm [box-shadow:inset_3px_0_0_var(--marca-rojo)]' : 'text-texto hover:bg-superficie-2'
                                        }`
                                    }
                                >
                                    <Icono nombre={m.icono} className="size-[1.15rem] shrink-0" /> <span className="flex-1">{m.titulo}</span>
                                    {avisos[m.ruta] > 0 && (
                                        <span
                                            className="rounded-full bg-acento px-2 py-0.5 text-xs font-bold text-white"
                                            title={`${avisos[m.ruta]} ${SIGNIFICADO_AVISO[m.ruta] ?? 'pendiente(s)'}`}
                                        >
                                            {avisos[m.ruta]}<span className="sr-only"> {SIGNIFICADO_AVISO[m.ruta] ?? 'pendiente(s)'}</span>
                                        </span>
                                    )}
                                </NavLink>
                            </li>
                        ))}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

/** Búsqueda global predictiva (encabezado) */
function BuscadorGlobal() {
    const [q, setQ] = useState('');
    const [res, setRes] = useState([]);
    const [abierto, setAbierto] = useState(false);
    const [activo, setActivo] = useState(-1);
    const [buscando, setBuscando] = useState(false);
    const navegar = useNavigate();
    const ref = useRef(null);

    useEffect(() => {
        if (q.trim().length < 2) {
            setRes([]);
            return;
        }
        setBuscando(true);
        const t = setTimeout(() => {
            api.get('/buscar', { params: { q } })
                .then((r) => {
                    setRes(r.data);
                    setAbierto(true);
                    setActivo(-1);
                })
                .catch(() => setRes([]))
                .finally(() => setBuscando(false));
        }, 300);
        return () => clearTimeout(t);
    }, [q]);

    useEffect(() => {
        const fuera = (e) => !ref.current?.contains(e.target) && setAbierto(false);
        document.addEventListener('mousedown', fuera);
        return () => document.removeEventListener('mousedown', fuera);
    }, []);

    const ir = (r) => {
        navegar(r.ruta);
        setAbierto(false);
        setQ('');
    };

    return (
        <div ref={ref} className="relative w-full max-w-xl" role="search">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
            <input
                type="search"
                className="campo rounded-full pl-9"
                placeholder="Buscar laboratorios, equipos, materias, reservas…"
                aria-label="Búsqueda global"
                aria-expanded={abierto}
                aria-controls="resultados-busqueda"
                role="combobox"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                onFocus={() => res.length && setAbierto(true)}
                onKeyDown={(e) => {
                    if (e.key === 'ArrowDown') setActivo((a) => Math.min(a + 1, res.length - 1));
                    else if (e.key === 'ArrowUp') setActivo((a) => Math.max(a - 1, 0));
                    else if (e.key === 'Enter' && res[activo]) ir(res[activo]);
                    else if (e.key === 'Escape') setAbierto(false);
                }}
            />
            {abierto && q.length >= 2 && (
                <ul id="resultados-busqueda" role="listbox" className="tarjeta absolute top-full right-0 left-0 z-40 mt-2 max-h-96 overflow-y-auto p-1 shadow-xl">
                    {buscando && !res.length && <li className="p-3 text-sm text-suave">Buscando…</li>}
                    {!buscando && !res.length && <li className="p-3 text-sm text-suave">Sin resultados para “{q}”.</li>}
                    {res.map((r, i) => (
                        <li key={i} role="option" aria-selected={i === activo}>
                            <button
                                className={`flex w-full items-start gap-3 rounded-tema p-2 text-left ${i === activo ? 'bg-primario-tenue' : 'hover:bg-superficie-2'}`}
                                onClick={() => ir(r)}
                                onMouseEnter={() => setActivo(i)}
                            >
                                <span className="mt-0.5 rounded bg-primario-tenue px-1.5 py-0.5 text-[0.7rem] font-semibold text-primario">{r.tipo}</span>
                                <span>
                                    <span className="block text-sm font-medium">{r.titulo}</span>
                                    <span className="block text-xs text-suave">{r.detalle}</span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Layout() {
    const { usuario, logout } = useAuth();
    const [menuMovil, setMenuMovil] = useState(false);
    const [menuUsuario, setMenuUsuario] = useState(false);
    const ubicacion = useLocation();

    useEffect(() => {
        setMenuMovil(false);
        setMenuUsuario(false);
        document.getElementById('contenido')?.focus({ preventScroll: true });
        window.scrollTo(0, 0);
    }, [ubicacion.pathname]);

    return (
        <div className="min-h-screen lg:pl-64">
            <a href="#contenido" className="solo-lector focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[70] focus:rounded focus:bg-primario focus:p-2 focus:text-sobre-primario">
                Saltar al contenido
            </a>

            {/* Barra lateral */}
            <aside className={`fixed inset-y-0 left-0 z-40 w-64 overflow-y-auto border-r border-borde bg-superficie transition-transform lg:translate-x-0 ${menuMovil ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="barra-marca" aria-hidden />
                <div className="flex h-[4.5rem] items-center justify-between border-b border-borde px-4">
                    <Link to="/" aria-label="Laboratorios 4to Piso - inicio">
                        <LogoHorizontal />
                    </Link>
                    <button className="btn btn-fantasma btn-sm lg:hidden" onClick={() => setMenuMovil(false)} aria-label="Cerrar menú">
                        <X className="size-5" />
                    </button>
                </div>
                <MenuLateral onNavegar={() => setMenuMovil(false)} />
            </aside>
            {menuMovil && <div className="fixed inset-0 z-30 bg-black/40 lg:hidden" onClick={() => setMenuMovil(false)} />}

            {/* Encabezado */}
            <header className="sticky top-0 z-20 flex h-[4.5rem] items-center gap-3 border-b border-borde bg-superficie/95 px-4 backdrop-blur">
                <button className="btn btn-fantasma btn-sm lg:hidden" onClick={() => setMenuMovil(true)} aria-label="Abrir menú">
                    <MenuIcon className="size-5" />
                </button>
                <BuscadorGlobal />
                <div className="ml-auto flex items-center gap-1">
                    <Notificaciones />
                    <PanelAccesibilidad />
                    <div className="relative">
                        <button className="btn btn-fantasma btn-sm" onClick={() => setMenuUsuario((v) => !v)} aria-expanded={menuUsuario} aria-haspopup="menu">
                            <UserCircle2 className="size-6" aria-hidden />
                            <span className="hidden text-left md:block">
                                <span className="block text-sm leading-tight font-semibold text-texto">{usuario.nombre}</span>
                                <span className="block text-xs leading-tight">{usuario.rol?.nombre}</span>
                            </span>
                        </button>
                        {menuUsuario && (
                            <div role="menu" className="tarjeta absolute right-0 mt-2 w-56 p-1 shadow-xl">
                                <p className="truncate px-3 py-2 text-xs text-suave">{usuario.email}</p>
                                <Link role="menuitem" to="/perfil" className="flex items-center gap-2 rounded-tema px-3 py-2 text-sm hover:bg-superficie-2">
                                    <UserCircle2 className="size-4" aria-hidden /> Mi perfil
                                </Link>
                                <button role="menuitem" onClick={logout} className="flex w-full items-center gap-2 rounded-tema px-3 py-2 text-sm text-peligro hover:bg-peligro-tenue">
                                    <LogOut className="size-4" aria-hidden /> Cerrar sesión
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </header>

            <main id="contenido" tabIndex={-1} className="mx-auto max-w-7xl p-4 outline-none sm:p-6">
                <ErrorBoundary ruta={ubicacion.pathname}>
                    <Outlet />
                </ErrorBoundary>
            </main>
            <Footer />
        </div>
    );
}
