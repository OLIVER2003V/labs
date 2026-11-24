import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { useAuth } from '../contexto/AuthContext';
import { Icono } from '../componentes/Layout';

/** Página de inicio: accesos directos generados desde el mismo menú dinámico del rol */
export default function Inicio() {
    const { usuario, menu } = useAuth();
    const h = new Date().getHours();
    const saludo = h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches';
    const accesos = menu.filter((m) => m.ruta !== '/');

    return (
        <>
            <section className="tarjeta mb-6 overflow-hidden bg-primario p-6 text-sobre-primario sm:p-8">
                <p className="opacity-90">{saludo},</p>
                <h1 className="text-3xl font-bold">{usuario.nombre_completo}</h1>
                <p className="mt-2 max-w-2xl opacity-90">
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
