import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCheck } from 'lucide-react';
import api from '../lib/api';

/** "hace 5 min", "hace 2 h", "hace 3 d" */
function hace(fecha) {
    const s = Math.max(0, (Date.now() - new Date(fecha).getTime()) / 1000);
    if (s < 60) return 'hace un momento';
    if (s < 3600) return `hace ${Math.floor(s / 60)} min`;
    if (s < 86400) return `hace ${Math.floor(s / 3600)} h`;
    return `hace ${Math.floor(s / 86400)} d`;
}

/**
 * Campanita del encabezado: avisos del sistema para el usuario
 * (reserva aprobada/rechazada/cancelada, nueva solicitud para el Encargado, bloqueos, etc.).
 * Se actualiza cada 60 s y al volver a la pestaña.
 */
export default function Notificaciones() {
    const [datos, setDatos] = useState({ no_leidas: 0, data: [] });
    const [abierto, setAbierto] = useState(false);
    const ref = useRef(null);
    const navegar = useNavigate();

    const cargar = useCallback(() => {
        api.get('/notificaciones').then((r) => setDatos(r.data)).catch(() => {});
    }, []);

    useEffect(() => {
        cargar();
        const t = setInterval(cargar, 60000);
        const alVolver = () => document.visibilityState === 'visible' && cargar();
        document.addEventListener('visibilitychange', alVolver);
        const fuera = (e) => !ref.current?.contains(e.target) && setAbierto(false);
        document.addEventListener('mousedown', fuera);
        return () => {
            clearInterval(t);
            document.removeEventListener('visibilitychange', alVolver);
            document.removeEventListener('mousedown', fuera);
        };
    }, [cargar]);

    const abrir = async (n) => {
        if (!n.leida_en) await api.put(`/notificaciones/${n.id}/leer`).catch(() => {});
        setAbierto(false);
        cargar();
        if (n.url) navegar(n.url);
    };

    const leerTodas = async () => {
        await api.put('/notificaciones/leer-todas').catch(() => {});
        cargar();
    };

    return (
        <div ref={ref} className="relative">
            <button
                className="relative grid size-10 place-items-center rounded-full border border-borde bg-superficie text-texto shadow-sm transition hover:border-primario hover:text-primario"
                onClick={() => {
                    setAbierto((v) => !v);
                    cargar();
                }}
                aria-expanded={abierto}
                aria-label={datos.no_leidas ? `Notificaciones: ${datos.no_leidas} sin leer` : 'Notificaciones'}
                title="Notificaciones"
            >
                <Bell className="size-5" aria-hidden />
                {datos.no_leidas > 0 && (
                    <span className="absolute -top-1 -right-1 grid min-w-5 place-items-center rounded-full bg-acento px-1 text-[0.65rem] font-bold text-white ring-2 ring-superficie">
                        {datos.no_leidas > 99 ? '99+' : datos.no_leidas}
                    </span>
                )}
            </button>

            {abierto && (
                <div className="tarjeta absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] shadow-xl" role="dialog" aria-label="Notificaciones">
                    <div className="flex items-center justify-between border-b border-borde px-4 py-3">
                        <p className="font-titulo text-lg font-bold tracking-wide uppercase">Notificaciones</p>
                        {datos.no_leidas > 0 && (
                            <button className="btn btn-fantasma btn-sm text-xs" onClick={leerTodas}>
                                <CheckCheck className="size-4" aria-hidden /> Marcar todas
                            </button>
                        )}
                    </div>
                    <ul className="max-h-96 overflow-y-auto">
                        {!datos.data.length && <li className="p-6 text-center text-sm text-suave">No tiene notificaciones.</li>}
                        {datos.data.map((n) => (
                            <li key={n.id}>
                                <button
                                    className={`flex w-full gap-3 border-b border-borde px-4 py-3 text-left transition hover:bg-superficie-2 ${n.leida_en ? '' : 'bg-primario-tenue/50'}`}
                                    onClick={() => abrir(n)}
                                >
                                    <span className={`mt-1.5 size-2 shrink-0 rounded-full ${n.leida_en ? 'bg-transparent' : 'bg-acento'}`} aria-hidden />
                                    <span className="min-w-0">
                                        <span className={`block text-sm ${n.leida_en ? '' : 'font-semibold'}`}>{n.titulo}</span>
                                        <span className="mt-0.5 line-clamp-3 block text-xs text-suave">{n.mensaje}</span>
                                        <span className="mt-1 block text-[0.7rem] text-suave">{hace(n.created_at)}</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
