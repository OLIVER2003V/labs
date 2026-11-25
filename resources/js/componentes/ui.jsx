import { createContext, useCallback, useContext, useEffect, useId, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, ChevronLeft, ChevronRight, Info, Loader2, X, XCircle } from 'lucide-react';
import api from '../lib/api';

/* ===================== Notificaciones (toast) ===================== */
const ToastContext = createContext(() => {});

export function ToastProvider({ children }) {
    const [lista, setLista] = useState([]);
    const notificar = useCallback((mensaje, tipo = 'exito') => {
        const id = Math.random();
        setLista((l) => [...l, { id, mensaje, tipo }]);
        setTimeout(() => setLista((l) => l.filter((t) => t.id !== id)), tipo === 'error' ? 7000 : 4000);
    }, []);
    const iconos = { exito: CheckCircle2, error: XCircle, alerta: AlertTriangle, info: Info };
    const colores = {
        exito: 'border-exito bg-exito-tenue text-exito',
        error: 'border-peligro bg-peligro-tenue text-peligro',
        alerta: 'border-alerta bg-alerta-tenue text-alerta',
        info: 'border-info bg-info-tenue text-info',
    };
    return (
        <ToastContext.Provider value={notificar}>
            {children}
            <div className="fixed right-4 bottom-4 z-[60] flex max-w-sm flex-col gap-2" role="status" aria-live="polite">
                {lista.map((t) => {
                    const I = iconos[t.tipo];
                    return (
                        <div key={t.id} className={`flex items-start gap-2 rounded-tema border-l-4 p-3 shadow-lg ${colores[t.tipo]}`}>
                            <I className="mt-0.5 size-5 shrink-0" aria-hidden />
                            <p className="text-sm font-medium text-texto">{t.mensaje}</p>
                        </div>
                    );
                })}
            </div>
        </ToastContext.Provider>
    );
}
export const useToast = () => useContext(ToastContext);

/* ===================== Modal ===================== */
export function Modal({ abierto, titulo, onCerrar, children, ancho = 'max-w-lg' }) {
    const ref = useRef(null);
    useEffect(() => {
        if (!abierto) return;
        const esc = (e) => {
            if (e.key === 'Escape') onCerrar();
            if (e.key === 'Tab' && ref.current) {
                const f = [...ref.current.querySelectorAll('button,input,select,textarea,a[href]')].filter((x) => !x.disabled);
                if (!f.length) return;
                if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
                else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
            }
        };
        document.addEventListener('keydown', esc);
        ref.current?.querySelector('input,select,textarea,button')?.focus();
        return () => document.removeEventListener('keydown', esc);
    }, [abierto, onCerrar]);
    if (!abierto) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center" onMouseDown={(e) => e.target === e.currentTarget && onCerrar()}>
            <div ref={ref} role="dialog" aria-modal="true" aria-label={titulo} className={`tarjeta w-full ${ancho} my-8`}>
                <div className="flex items-center justify-between border-b border-borde px-5 py-3">
                    <h2 className="text-lg font-semibold">{titulo}</h2>
                    <button className="btn btn-fantasma btn-sm" onClick={onCerrar} aria-label="Cerrar">
                        <X className="size-5" />
                    </button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}

/* ===================== Confirmación ===================== */
export function Confirmar({ abierto, titulo = '¿Está seguro?', mensaje, onConfirmar, onCerrar, textoBoton = 'Confirmar', peligro = true }) {
    const [enviando, setEnviando] = useState(false);
    return (
        <Modal abierto={abierto} titulo={titulo} onCerrar={onCerrar} ancho="max-w-md">
            <p className="text-suave">{mensaje}</p>
            <div className="mt-5 flex justify-end gap-2">
                <button className="btn btn-secundario" onClick={onCerrar}>Cancelar</button>
                <button
                    className={`btn ${peligro ? 'btn-peligro' : 'btn-primario'}`}
                    disabled={enviando}
                    onClick={async () => {
                        setEnviando(true);
                        try {
                            await onConfirmar();
                        } finally {
                            setEnviando(false);
                        }
                    }}
                >
                    {enviando && <Loader2 className="size-4 animate-spin" />} {textoBoton}
                </button>
            </div>
        </Modal>
    );
}

/* ===================== Campo de formulario ===================== */
const cacheOpciones = {};
/** Carga listas para selects desde /api/opciones/{tipo} */
export function useOpciones(tipo) {
    const [opciones, setOpciones] = useState(cacheOpciones[tipo] ?? []);
    useEffect(() => {
        if (!tipo) return;
        api.get(`/opciones/${tipo}`).then((r) => {
            cacheOpciones[tipo] = r.data;
            setOpciones(r.data);
        }).catch(() => {});
    }, [tipo]);
    return opciones;
}

export function Campo({ campo, valor, onChange, error, valores = {} }) {
    const id = useId();
    const remotas = useOpciones(typeof campo.opciones === 'string' ? campo.opciones : null);
    const opciones = typeof campo.opciones === 'string' ? remotas : campo.opciones ?? [];
    const requerido = typeof campo.requerido === 'function' ? campo.requerido(valores) : campo.requerido;
    const clase = `campo ${error ? 'campo-error' : ''}`;
    const comunes = {
        id,
        name: campo.name,
        'aria-invalid': !!error,
        'aria-describedby': error ? `${id}-error` : campo.ayuda ? `${id}-ayuda` : undefined,
        disabled: campo.deshabilitado?.(valores),
    };

    let control;
    if (campo.tipo === 'select') {
        control = (
            <select {...comunes} className={clase} value={valor ?? ''} onChange={(e) => onChange(e.target.value)}>
                <option value="">— Seleccione —</option>
                {opciones.map((o) => {
                    const [v, l] = typeof o === 'object' ? [o.id ?? o.value, o.label] : [o, o];
                    return <option key={v} value={v}>{l}</option>;
                })}
            </select>
        );
    } else if (campo.tipo === 'textarea') {
        control = <textarea {...comunes} rows={3} className={clase} value={valor ?? ''} maxLength={campo.max} onChange={(e) => onChange(e.target.value)} />;
    } else if (campo.tipo === 'casillas') {
        const marcados = Array.isArray(valor) ? valor.map(String) : [];
        const alternar = (v) => onChange(marcados.includes(String(v)) ? marcados.filter((x) => x !== String(v)) : [...marcados, String(v)]);
        control = (
            <div id={id} role="group" aria-describedby={comunes['aria-describedby']} className="flex flex-wrap gap-2">
                {opciones.map((o) => {
                    const [v, l] = typeof o === 'object' ? [o.id ?? o.value, o.label] : [o, o];
                    const activo = marcados.includes(String(v));
                    return (
                        <label key={v} className={`flex cursor-pointer items-center gap-2 rounded-tema border px-3 py-1.5 text-sm transition ${activo ? 'border-primario bg-primario-tenue text-primario' : 'border-borde hover:bg-superficie-2'}`}>
                            <input type="checkbox" className="size-4 accent-[var(--c-primario)]" checked={activo} onChange={() => alternar(v)} />
                            {campo.etiquetaCorta ? campo.etiquetaCorta(l) : l}
                        </label>
                    );
                })}
            </div>
        );
    } else if (campo.tipo === 'checkbox') {
        control = (
            <label className="flex items-center gap-2">
                <input {...comunes} type="checkbox" className="size-5 accent-[var(--c-primario)]" checked={!!valor} onChange={(e) => onChange(e.target.checked)} />
                <span>{campo.textoCheckbox ?? 'Sí'}</span>
            </label>
        );
    } else {
        const tipos = { number: 'number', date: 'date', time: 'time', email: 'email', password: 'password' };
        control = (
            <input
                {...comunes}
                type={tipos[campo.tipo] ?? 'text'}
                className={clase}
                value={valor ?? ''}
                placeholder={campo.placeholder}
                maxLength={campo.tipo === 'number' ? undefined : campo.max}
                step={campo.paso}
                autoComplete={campo.autocompletar ?? 'off'}
                onChange={(e) => onChange(campo.mayusculas ? e.target.value.toUpperCase() : e.target.value)}
            />
        );
    }

    return (
        <div className={campo.ancho === 'completo' ? 'sm:col-span-2' : ''}>
            {campo.tipo !== 'checkbox' || campo.label ? (
                <label htmlFor={id} className="etiqueta">
                    {campo.label} {requerido && <span className="text-peligro" aria-hidden>*</span>}
                </label>
            ) : null}
            {control}
            {error ? (
                <p id={`${id}-error`} className="mt-1 text-sm text-peligro" role="alert">{error}</p>
            ) : campo.ayuda ? (
                <p id={`${id}-ayuda`} className="mt-1 text-xs text-suave">{campo.ayuda}</p>
            ) : null}
        </div>
    );
}

/* ===================== Otros ===================== */
const COLORES_ESTADO = {
    exito: ['ACTIVO', 'DISPONIBLE', 'APROBADA', 'OPERATIVO', 'RESUELTA', 'ENTREGADO', 'PUNTUAL', 'PAGADA', 'PAGADO', 'LOGIN_OK', 'VIGENTE_OK', 'INSERT'],
    alerta: ['PENDIENTE', 'MANTENIMIENTO', 'EN_REPARACION', 'EN_ATENCION', 'ATRASO', 'PLANIFICADO', 'EN_CUSTODIA', 'VIGENTE', 'UPDATE'],
    peligro: ['EXPIRADA', 'INACTIVO', 'INHABILITADO', 'RECHAZADA', 'BAJA', 'FALTA', 'LOGIN_FALLIDO', 'DENEGADO', 'ANULADO', 'DELETE'],
    info: ['CANCELADA', 'CERRADO', 'PERMISO', 'ACCESO', 'LOGOUT'],
};
export function Badge({ valor, children }) {
    const texto = children ?? valor;
    const tipo = Object.entries(COLORES_ESTADO).find(([, l]) => l.includes(valor))?.[0] ?? 'info';
    const c = { exito: 'bg-exito-tenue text-exito', alerta: 'bg-alerta-tenue text-alerta', peligro: 'bg-peligro-tenue text-peligro', info: 'bg-info-tenue text-info' }[tipo];
    return <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap ${c}`}>{String(texto ?? '').replaceAll('_', ' ')}</span>;
}

export function Cargando({ texto = 'Cargando…' }) {
    return (
        <div className="flex items-center justify-center gap-2 p-10 text-suave" role="status">
            <Loader2 className="size-5 animate-spin" aria-hidden /> {texto}
        </div>
    );
}

export function Vacio({ texto = 'No hay registros para mostrar.' }) {
    return <p className="p-10 text-center text-suave">{texto}</p>;
}

export function Encabezado({ titulo, descripcion, children }) {
    return (
        <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 className="text-2xl font-bold">{titulo}</h1>
                {descripcion && <p className="mt-1 text-suave">{descripcion}</p>}
            </div>
            <div className="flex flex-wrap gap-2">{children}</div>
        </div>
    );
}

export function Paginacion({ datos, onPagina }) {
    if (!datos || datos.last_page <= 1) {
        return datos?.total ? <p className="px-4 py-3 text-sm text-suave">{datos.total} registro(s)</p> : null;
    }
    return (
        <nav className="flex flex-wrap items-center justify-between gap-2 border-t border-borde px-4 py-3 text-sm" aria-label="Paginación">
            <span className="text-suave">
                Mostrando {datos.from}–{datos.to} de {datos.total}
            </span>
            <div className="flex items-center gap-1">
                <button className="btn btn-secundario btn-sm" disabled={datos.current_page === 1} onClick={() => onPagina(datos.current_page - 1)} aria-label="Página anterior">
                    <ChevronLeft className="size-4" />
                </button>
                <span className="px-2">
                    Página {datos.current_page} de {datos.last_page}
                </span>
                <button className="btn btn-secundario btn-sm" disabled={datos.current_page === datos.last_page} onClick={() => onPagina(datos.current_page + 1)} aria-label="Página siguiente">
                    <ChevronRight className="size-4" />
                </button>
            </div>
        </nav>
    );
}

export function Tarjeta({ titulo, children, className = '', acciones }) {
    return (
        <section className={`tarjeta p-5 ${className}`}>
            {(titulo || acciones) && (
                <div className="mb-3 flex items-center justify-between gap-2">
                    {titulo && <h2 className="font-semibold">{titulo}</h2>}
                    {acciones}
                </div>
            )}
            {children}
        </section>
    );
}

/** Formulario genérico con validación de cliente + errores del servidor */
export function Formulario({ campos, valores, setValores, errores, onEnviar, enviando, textoBoton = 'Guardar', onCancelar }) {
    return (
        <form
            noValidate
            onSubmit={(e) => {
                e.preventDefault();
                onEnviar();
            }}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {campos.filter((c) => !c.oculto?.(valores)).map((c) => (
                    <Campo key={c.name} campo={c} valor={valores[c.name]} valores={valores} error={errores[c.name]} onChange={(v) => setValores((x) => ({ ...x, [c.name]: v }))} />
                ))}
            </div>
            <div className="mt-6 flex justify-end gap-2">
                {onCancelar && <button type="button" className="btn btn-secundario" onClick={onCancelar}>Cancelar</button>}
                <button type="submit" className="btn btn-primario" disabled={enviando}>
                    {enviando && <Loader2 className="size-4 animate-spin" aria-hidden />} {textoBoton}
                </button>
            </div>
        </form>
    );
}
