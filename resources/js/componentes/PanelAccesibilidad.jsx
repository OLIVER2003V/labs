import { useEffect, useRef, useState } from 'react';
import { Baby, Briefcase, Contrast, Minus, Moon, Palette, Plus, RotateCcw, Sparkles, Sun, SunMoon } from 'lucide-react';
import { useTema } from '../contexto/TemaContext';

/**
 * Selector de tema (Niños / Jóvenes / Adultos), modo Día/Noche/Automático,
 * tamaño de letra (deslizador y botones) y alto contraste.
 */
export default function PanelAccesibilidad({ alineacion = 'right-0' }) {
    const t = useTema();
    const [abierto, setAbierto] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const fuera = (e) => !ref.current?.contains(e.target) && setAbierto(false);
        const esc = (e) => e.key === 'Escape' && setAbierto(false);
        document.addEventListener('mousedown', fuera);
        document.addEventListener('keydown', esc);
        return () => {
            document.removeEventListener('mousedown', fuera);
            document.removeEventListener('keydown', esc);
        };
    }, []);

    const temas = [
        { id: 'ninos', nombre: 'Niños', icono: Baby },
        { id: 'jovenes', nombre: 'Jóvenes', icono: Sparkles },
        { id: 'adultos', nombre: 'Adultos', icono: Briefcase },
    ];
    const modos = [
        { id: 'claro', nombre: 'Día', icono: Sun },
        { id: 'oscuro', nombre: 'Noche', icono: Moon },
        { id: 'auto', nombre: 'Auto', icono: SunMoon },
    ];
    const opcion = (activo) =>
        `flex flex-1 flex-col items-center gap-1 rounded-tema border px-2 py-2 text-xs font-medium transition ${
            activo ? 'border-primario bg-primario-tenue text-primario' : 'border-borde hover:bg-superficie-2'
        }`;

    return (
        <div ref={ref} className="relative">
            <button
                className={`group flex items-center gap-2 rounded-full border py-1.5 pr-3 pl-1.5 text-sm font-medium shadow-sm transition ${
                    abierto ? 'border-primario bg-primario-tenue text-primario' : 'border-borde bg-superficie text-texto hover:border-primario hover:text-primario'
                }`}
                onClick={() => setAbierto((v) => !v)}
                aria-expanded={abierto}
                aria-haspopup="dialog"
                aria-label="Apariencia y accesibilidad: tema, modo día o noche, tamaño de letra y contraste"
                title="Apariencia y accesibilidad"
            >
                {/* Muestra del tema activo: se ve qué colores están aplicados */}
                <span className="relative grid size-7 place-items-center overflow-hidden rounded-full bg-primario text-sobre-primario" aria-hidden>
                    <span className="absolute inset-y-0 right-0 w-1/2 bg-acento" />
                    <Palette className="relative size-4" />
                </span>
                
                <span className="flex items-center gap-0.5 text-suave group-hover:text-current" aria-hidden>
                    {t.modoReal === 'oscuro' ? <Moon className="size-3.5" /> : <Sun className="size-3.5" />}
                
                </span>
            </button>
            {abierto && (
                <div className={`tarjeta absolute ${alineacion} z-50 mt-2 w-80 p-4 shadow-xl`} role="dialog" aria-label="Apariencia y accesibilidad">
                    <p className="mb-3 font-titulo text-lg font-bold tracking-wide uppercase">Apariencia y accesibilidad</p>
                    <fieldset>
                        <legend className="etiqueta">Tema</legend>
                        <div className="flex gap-2">
                            {temas.map(({ id, nombre, icono: I }) => (
                                <button key={id} className={opcion(t.tema === id)} aria-pressed={t.tema === id} onClick={() => t.cambiar({ tema: id })}>
                                    <I className="size-5" aria-hidden /> {nombre}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset className="mt-4">
                        <legend className="etiqueta">Modo</legend>
                        <div className="flex gap-2">
                            {modos.map(({ id, nombre, icono: I }) => (
                                <button key={id} className={opcion(t.modo === id)} aria-pressed={t.modo === id} onClick={() => t.cambiar({ modo: id })}>
                                    <I className="size-5" aria-hidden /> {nombre}
                                </button>
                            ))}
                        </div>
                        {t.modo === 'auto' && <p className="mt-1 text-xs text-suave">Automático: día de 07:00 a 19:00 (ahora: {t.modoReal === 'claro' ? 'día' : 'noche'}).</p>}
                    </fieldset>

                    <div className="mt-4">
                        <label htmlFor="tam-fuente" className="etiqueta flex justify-between">
                            Tamaño de letra <span className="text-suave">{t.fuente}%</span>
                        </label>
                        <div className="flex items-center gap-2">
                            <button className="btn btn-secundario btn-sm" onClick={() => t.cambiar({ fuente: Math.max(80, t.fuente - 10) })} aria-label="Reducir letra">
                                <Minus className="size-4" />
                            </button>
                            <input
                                id="tam-fuente"
                                type="range"
                                min={80}
                                max={160}
                                step={10}
                                value={t.fuente}
                                onChange={(e) => t.cambiar({ fuente: Number(e.target.value) })}
                                className="flex-1 accent-[var(--c-primario)]"
                            />
                            <button className="btn btn-secundario btn-sm" onClick={() => t.cambiar({ fuente: Math.min(160, t.fuente + 10) })} aria-label="Aumentar letra">
                                <Plus className="size-4" />
                            </button>
                        </div>
                    </div>

                    <label className="mt-4 flex cursor-pointer items-center justify-between gap-2 rounded-tema border border-borde p-3">
                        <span className="flex items-center gap-2 text-sm font-medium">
                            <Contrast className="size-5" aria-hidden /> Alto contraste
                        </span>
                        <input type="checkbox" role="switch" className="size-5 accent-[var(--c-primario)]" checked={t.contraste} onChange={(e) => t.cambiar({ contraste: e.target.checked })} />
                    </label>

                    <button className="btn btn-fantasma btn-sm mt-3 w-full" onClick={() => t.cambiar({ tema: 'adultos', modo: 'auto', fuente: 100, contraste: false })}>
                        <RotateCcw className="size-4" aria-hidden /> Restablecer
                    </button>
                </div>
            )}
        </div>
    );
}
