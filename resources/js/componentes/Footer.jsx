import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Eye } from 'lucide-react';
import api from '../lib/api';

/**
 * Pie de página con CONTADOR DE VISITAS persistente por página.
 * Cada vez que se abre una ruta, se llama a POST /api/visitas, que ejecuta la función
 * registrar_visita() de PostgreSQL (INSERT ... ON CONFLICT DO UPDATE contador = contador + 1).
 */
export default function Footer() {
    const { pathname } = useLocation();
    const [visitas, setVisitas] = useState(null);

    useEffect(() => {
        const ruta = pathname.replace(/\/\d+(?=\/|$)/g, '') || '/';
        api.post('/visitas', { ruta }).then((r) => setVisitas(r.data.visitas)).catch(() => setVisitas(null));
    }, [pathname]);

    return (
        <footer className="border-t border-borde px-4 py-4 text-sm text-suave sm:px-6">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2">
                <p>© {new Date().getFullYear()} Laboratorios FICCT · UAGRM — Tecnología Web INF513 · Grupo 08 SA</p>
                <p className={`flex items-center gap-1.5 ${visitas === null ? 'invisible' : ''}`} aria-live="polite">
                    <Eye className="size-4" aria-hidden />
                    {visitas === null ? null : (
                        <>Esta página fue visitada <strong className="text-texto">{visitas.toLocaleString('es-BO')}</strong> {visitas === 1 ? 'vez' : 'veces'}</>
                    )}
                </p>
            </div>
        </footer>
    );
}
