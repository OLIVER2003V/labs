import { useEffect, useState } from 'react';
import { useTema } from '../contexto/TemaContext';

/*
 * Kit de gráficos (Recharts) compartido por el Panel y Reportes. Reglas:
 *  - Series con colores propios (--c-serie-1..3) validados para daltonismo y contraste; estados con los colores de estado.
 *  - Cuadrícula y ejes finos y sólidos (no punteados), solo líneas horizontales.
 *  - Partes de un total → barra segmentada con leyenda numérica (no tortas: los ángulos no se comparan bien).
 */

/** Lee los colores del tema actual (variables CSS): cambian con el tema, el modo día/noche y el alto contraste */
export function useColores() {
    const { tema, modoReal, contraste } = useTema();
    const [c, setC] = useState({});
    useEffect(() => {
        const s = getComputedStyle(document.documentElement);
        const v = (n) => s.getPropertyValue(`--c-${n}`).trim();
        setC({
            primario: v('primario'), acento: v('acento'), exito: v('exito'), alerta: v('alerta'), peligro: v('peligro'),
            info: v('info'), texto: v('texto'), suave: v('suave'), borde: v('borde'), superficie: v('superficie'),
            serie1: v('serie-1'), serie2: v('serie-2'), serie3: v('serie-3'),
        });
    }, [tema, modoReal, contraste]);
    return c;
}

/** Color de cada estado del sistema (significan algo: bien / atención / mal / neutro) */
export const COLOR_ESTADO = {
    APROBADA: 'exito', PENDIENTE: 'alerta', RECHAZADA: 'peligro', CANCELADA: 'suave',
    RESUELTA: 'exito', EN_ATENCION: 'info',
    PUNTUAL: 'exito', ATRASO: 'alerta', FALTA: 'peligro', PERMISO: 'info',
};

/** Props comunes: ejes, cuadrícula y tooltip con los colores del tema */
export function estilos(c) {
    return {
        ejes: { stroke: c.borde, tick: { fill: c.suave, fontSize: 12 }, tickLine: false },
        grilla: { stroke: c.borde, vertical: false },               // líneas finas y sólidas, solo horizontales
        tooltip: {
            contentStyle: { background: c.superficie, border: `1px solid ${c.borde}`, borderRadius: 8, color: c.texto, fontSize: 13 },
            labelStyle: { color: c.texto, fontWeight: 600 },
            cursor: { fill: c.borde, fillOpacity: 0.35 },
        },
        leyenda: { wrapperStyle: { fontSize: 13, color: c.texto } },
    };
}

/** 1234 → "1,2k" para ejes con números grandes (corto: entra en ejes angostos del celular) */
export const compacto = (n) => (Math.abs(n) >= 1000 ? `${(n / 1000).toLocaleString('es-BO', { maximumFractionDigits: 1 })}k` : n.toLocaleString('es-BO'));

/**
 * Barra segmentada: partes de un total (ej: reservas por estado). Reemplaza a la torta.
 * datos = [{ clave, valor }] · colores por estado · debajo, cada parte con su número y % (equivale a una tabla).
 */
export function BarraSegmentada({ datos, c, etiqueta = 'registros', vacio = 'Sin datos en el periodo.' }) {
    const partes = datos.map((d) => ({ ...d, valor: Number(d.valor ?? d.total ?? 0), clave: d.clave ?? d.estado }))
        .filter((d) => d.valor > 0)
        .sort((a, b) => b.valor - a.valor);
    const total = partes.reduce((s, d) => s + d.valor, 0);
    if (!total) return <p className="py-8 text-center text-sm text-suave">{vacio}</p>;
    const color = (k) => c[COLOR_ESTADO[k]] ?? c.serie1;
    const pct = (v) => Math.round((v * 1000) / total) / 10;
    const nombre = (k) => k.replaceAll('_', ' ').toLowerCase().replace(/^./, (x) => x.toUpperCase());

    return (
        <figure>
            <p className="mb-2 text-sm text-suave"><span className="text-2xl font-bold text-texto">{total.toLocaleString('es-BO')}</span> {etiqueta}</p>
            <div className="flex h-8 w-full gap-0.5 overflow-hidden rounded-md" role="img"
                aria-label={partes.map((d) => `${nombre(d.clave)}: ${d.valor} (${pct(d.valor)} %)`).join(', ')}>
                {partes.map((d) => (
                    <div key={d.clave} className="flex min-w-1.5 items-center justify-center text-xs font-bold"
                        style={{ width: `${(d.valor * 100) / total}%`, background: color(d.clave), color: c.superficie }}
                        title={`${nombre(d.clave)}: ${d.valor} (${pct(d.valor)} %)`}>
                        {pct(d.valor) >= 12 && `${pct(d.valor)} %`}
                    </div>
                ))}
            </div>
            <figcaption>
                <ul className="mt-3 grid grid-cols-[repeat(auto-fill,minmax(10rem,1fr))] gap-x-4 gap-y-1.5 text-sm">
                    {partes.map((d) => (
                        <li key={d.clave} className="flex items-center gap-2">
                            <span className="size-3 shrink-0 rounded-sm" style={{ background: color(d.clave) }} aria-hidden />
                            <span className="min-w-0 flex-1 truncate">{nombre(d.clave)}</span>
                            <span className="font-semibold tabular-nums">{d.valor}</span>
                            <span className="w-12 text-right text-xs text-suave tabular-nums">{pct(d.valor)} %</span>
                        </li>
                    ))}
                </ul>
            </figcaption>
        </figure>
    );
}

/** Mensaje cuando un gráfico no tiene datos (en vez de un gráfico vacío) */
export const SinDatos = ({ texto = 'Sin datos en el periodo.' }) => <p className="py-16 text-center text-sm text-suave">{texto}</p>;
