export const DIAS = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

/** "2026-11-25" → "25/11/2026" (sin problemas de zona horaria) */
export function fecha(valor) {
    if (!valor) return '—';
    const s = String(valor);
    if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
        const [a, m, d] = s.split('-');
        return `${d}/${m}/${a}`;
    }
    return new Date(s).toLocaleDateString('es-BO');
}

export function fechaHora(valor) {
    if (!valor) return '—';
    return new Date(valor).toLocaleString('es-BO', { dateStyle: 'short', timeStyle: 'short' });
}

export const hora = (v) => (v ? String(v).slice(0, 5) : '—');

export const bs = (v) => `Bs ${Number(v ?? 0).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const hoyISO = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
