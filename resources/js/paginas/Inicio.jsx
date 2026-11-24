import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { useAuth } from '../contexto/AuthContext';
import { Icono } from '../componentes/Layout';
import { Isotipo } from '../componentes/Logo';

/** Página de inicio: accesos directos generados desde el mismo menú dinámico del rol */
export default function Inicio() {
    const { usuario, menu } = useAuth();
    const h = new Date().getHours();
    const saludo = h >= 5 && h < 12 ? 'Buenos días' : h >= 12 && h < 19 ? 'Buenas tardes' : 'Buenas noches';
    const accesos = menu.filter((m) => m.ruta !== '/');

    return (
        <>
            <section className="tarjeta fondo-circuito relative mb-6 overflow-hidden border-0 p-6 text-white sm:p-8">
                <Isotipo variante="claro" className="pointer-events-none absolute -right-6 -bottom-8 h-56 w-auto opacity-15" titulo="" />
                <p className="font-[Montserrat] text-xs font-semibold tracking-[0.25em] text-[#7FC3E0] uppercase">Laboratorios 4to Piso · FICCT</p>
                <p className="mt-3 opacity-90">{saludo},</p>
                <h1 className="!text-4xl !text-white">{usuario.nombre_completo}</h1>
                <p className="relative mt-2 max-w-2xl opacity-90">
                    Ingresó como <strong>{usuario.rol?.nombre}</strong>. Estas son las opciones habilitadas para su rol.
                </p>
            </section>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                {accesos.map((m) => (
                    <Link key={m.ruta} to={m.ruta} className="tarjeta group flex items-center gap-4 p-4 transition hover:border-primario hover:shadow-md">
                        <span className="grid size-12 shrink-0 place-items-center rounded-tema bg-primario-tenue text-primario">
                            <Icono nombre={m.icono} className="size-6" />
                        </span>
                        <span className="flex-1">
                            <span className="block font-semibold">{m.titulo}</span>
                            <span className="block text-xs text-suave">{m.grupo}</span>
                        </span>
                        <ArrowRight className="size-5 text-suave transition group-hover:translate-x-1 group-hover:text-primario" aria-hidden />
                    </Link>
                ))}
            </div>
        </>
    );
}
