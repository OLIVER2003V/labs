import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowLeft, Check, CheckCheck, MessageSquarePlus, Search, Send } from 'lucide-react';
import api, { mensajeError } from '../lib/api';
import { Encabezado, useToast } from '../componentes/ui';
import { useAuth } from '../contexto/AuthContext';

/*
 * CU5 - Chat interno. Dos paneles: conversaciones (izquierda) y mensajes (derecha).
 * No usa WebSockets: consulta al servidor cada 5 s mientras la pestaña está visible (polling),
 * que funciona en cualquier hosting con PHP. Enter envía; Shift+Enter hace un salto de línea.
 */
const INTERVALO = 5000;

const horaCorta = (f) => new Date(f).toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
const diaCorto = (f) => {
    const d = new Date(f);
    const hoy = new Date();
    if (d.toDateString() === hoy.toDateString()) return horaCorta(f);
    return d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit' });
};
const iniciales = (n) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase();

function Avatar({ nombre, enLinea }) {
    return (
        <span className="relative grid size-10 shrink-0 place-items-center rounded-full bg-primario-tenue text-sm font-bold text-primario" aria-hidden>
            {iniciales(nombre)}
            {enLinea && <span className="absolute right-0 bottom-0 size-3 rounded-full border-2 border-superficie bg-exito" />}
        </span>
    );
}

/** "En línea" o "últ. vez hoy 14:32" (el servidor guarda la última actividad de cada usuario) */
function presencia(u) {
    if (u?.en_linea) return 'En línea';
    if (!u?.ultima_vez) return '';
    const d = new Date(u.ultima_vez);
    const h = d.toLocaleTimeString('es-BO', { hour: '2-digit', minute: '2-digit' });
    const dias = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000);
    return `últ. vez ${dias === 0 ? 'hoy' : dias === 1 ? 'ayer' : d.toLocaleDateString('es-BO', { day: '2-digit', month: '2-digit' })} a las ${h}`;
}

export default function Mensajes() {
    const notificar = useToast();
    const { refrescarAvisos } = useAuth();
    const [conversaciones, setConversaciones] = useState([]);
    const [contactos, setContactos] = useState(null); // lista para "Nuevo mensaje"; null = cerrada
    const [filtro, setFiltro] = useState('');
    const [activo, setActivo] = useState(null);       // { id, nombre, rol }
    const [mensajes, setMensajes] = useState([]);
    const [texto, setTexto] = useState('');
    const [enviando, setEnviando] = useState(false);
    const finRef = useRef(null);
    const activoRef = useRef(null);
    activoRef.current = activo;

    const cargarConversaciones = useCallback(() => {
        api.get('/mensajes').then((r) => setConversaciones(r.data.data)).catch(() => {});
    }, []);

    const cargarChat = useCallback((usuario) => {
        api.get(`/mensajes/${usuario.id}`).then((r) => {
            if (activoRef.current?.id !== usuario.id) return; // llegó tarde: el usuario ya cambió de chat
            setMensajes((antes) => {
                // Solo se baja al final si llegaron mensajes nuevos
                if (r.data.mensajes.length !== antes.length) setTimeout(() => finRef.current?.scrollIntoView({ block: 'end' }), 0);
                return r.data.mensajes;
            });
        }).catch(() => {});
    }, []);

    // Polling: cada 5 s, solo con la pestaña visible
    useEffect(() => {
        cargarConversaciones();
        const t = setInterval(() => {
            if (document.visibilityState !== 'visible') return;
            cargarConversaciones();
            if (activoRef.current) cargarChat(activoRef.current);
        }, INTERVALO);
        return () => clearInterval(t);
    }, [cargarConversaciones, cargarChat]);

    const abrirChat = (usuario) => {
        setActivo(usuario);
        setMensajes([]);
        setContactos(null);
        setTexto('');
        activoRef.current = usuario;
        cargarChat(usuario);
        // al abrirlo se marcan como leídos: se actualizan la lista y el número del menú
        setTimeout(() => { cargarConversaciones(); refrescarAvisos?.(); }, 600);
    };

    const nuevo = async () => {
        try {
            setContactos((await api.get('/mensajes/contactos')).data);
            setFiltro('');
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    const enviar = async (e) => {
        e?.preventDefault();
        const contenido = texto.trim();
        if (!contenido || !activo) return;
        setEnviando(true);
        try {
            const { data } = await api.post('/mensajes', { destinatario_id: activo.id, contenido });
            setMensajes((m) => [...m, data.data]);
            setTexto('');
            setTimeout(() => finRef.current?.scrollIntoView({ block: 'end' }), 0);
            cargarConversaciones();
        } catch (err) {
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };

    const lista = (contactos ?? conversaciones.map((c) => c.usuario))
        .filter((u) => u.nombre.toLowerCase().includes(filtro.toLowerCase()));
    const datosDe = (id) => conversaciones.find((c) => c.usuario.id === id);
    // La presencia del chat abierto se actualiza con cada consulta (cada 5 s)
    const vivo = activo ? (datosDe(activo.id)?.usuario ?? activo) : null;

    return (
        <div>
            <Encabezado titulo="Mensajes" descripcion="Chat interno para coordinar turnos, permutas y avisos con el Encargado y los auxiliares." />
            <div className="tarjeta grid h-[calc(100vh-14rem)] min-h-[28rem] overflow-hidden md:grid-cols-[20rem_1fr]">
                {/* ------------------------------------------------ Conversaciones */}
                <aside className={`flex min-h-0 flex-col border-borde md:border-r ${activo ? 'hidden md:flex' : 'flex'}`} aria-label="Conversaciones">
                    <div className="flex items-center gap-2 border-b border-borde p-3">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-suave" aria-hidden />
                            <input className="campo pl-9" placeholder={contactos ? 'Buscar contacto…' : 'Buscar conversación…'} value={filtro} onChange={(e) => setFiltro(e.target.value)} aria-label="Buscar" />
                        </div>
                        {contactos ? (
                            <button className="btn btn-secundario btn-sm" onClick={() => { setContactos(null); setFiltro(''); }}>Volver</button>
                        ) : (
                            <button className="btn btn-primario btn-sm" onClick={nuevo} title="Nuevo mensaje" aria-label="Nuevo mensaje">
                                <MessageSquarePlus className="size-4" aria-hidden />
                            </button>
                        )}
                    </div>
                    {contactos && <p className="px-4 pt-3 text-xs font-semibold tracking-wide text-suave uppercase">Elija a quién escribir</p>}
                    <ul className="min-h-0 flex-1 overflow-y-auto">
                        {lista.map((u) => {
                            const c = contactos ? null : datosDe(u.id);
                            return (
                                <li key={u.id}>
                                    <button
                                        className={`flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-superficie-2 ${activo?.id === u.id ? 'bg-primario-tenue' : ''}`}
                                        onClick={() => abrirChat(u)}
                                    >
                                        <Avatar nombre={u.nombre} enLinea={u.en_linea} />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-baseline justify-between gap-2">
                                                <span className="truncate font-semibold">{u.nombre}</span>
                                                {c && <span className="shrink-0 text-xs text-suave">{diaCorto(c.ultimo.created_at)}</span>}
                                            </span>
                                            <span className="flex items-center justify-between gap-2">
                                                <span className="truncate text-sm text-suave">{c ? `${c.ultimo.mio ? 'Usted: ' : ''}${c.ultimo.contenido}` : u.rol}</span>
                                                {c?.no_leidos > 0 && <span className="shrink-0 rounded-full bg-acento px-2 py-0.5 text-xs font-bold text-white" aria-label={`${c.no_leidos} sin leer`}>{c.no_leidos}</span>}
                                            </span>
                                        </span>
                                    </button>
                                </li>
                            );
                        })}
                        {!lista.length && (
                            <li className="p-6 text-center text-sm text-suave">
                                {contactos ? 'No hay contactos con ese nombre.' : 'Aún no tiene conversaciones. Use el botón + para escribir a alguien.'}
                            </li>
                        )}
                    </ul>
                </aside>

                {/* ------------------------------------------------ Chat */}
                <section className={`min-h-0 flex-col ${activo ? 'flex' : 'hidden md:flex'}`} aria-label="Conversación">
                    {!activo ? (
                        <div className="grid flex-1 place-items-center p-6 text-center text-suave">
                            <p>Elija una conversación o inicie una nueva con el botón <MessageSquarePlus className="inline size-4" aria-label="nuevo mensaje" />.</p>
                        </div>
                    ) : (
                        <>
                            <header className="flex items-center gap-3 border-b border-borde px-4 py-3">
                                <button className="btn btn-fantasma btn-sm md:hidden" onClick={() => setActivo(null)} aria-label="Volver a las conversaciones">
                                    <ArrowLeft className="size-4" />
                                </button>
                                <Avatar nombre={activo.nombre} enLinea={vivo?.en_linea} />
                                <div className="min-w-0">
                                    <p className="truncate font-semibold">{activo.nombre}</p>
                                    <p className="text-xs text-suave">
                                        {activo.rol}
                                        {presencia(vivo) && <> · <span className={vivo?.en_linea ? 'font-semibold text-exito' : ''}>{presencia(vivo)}</span></>}
                                    </p>
                                </div>
                            </header>
                            <ol className="min-h-0 flex-1 space-y-2 overflow-y-auto bg-superficie-2/40 p-4" aria-live="polite">
                                {mensajes.map((m) => (
                                    <li key={m.id} className={`flex ${m.mio ? 'justify-end' : 'justify-start'}`}>
                                        <div className={`max-w-[80%] rounded-2xl px-4 py-2 shadow-sm ${m.mio ? 'rounded-br-sm bg-primario text-white' : 'rounded-bl-sm border border-borde bg-superficie'}`}>
                                            <p className="break-words whitespace-pre-wrap">{m.contenido}</p>
                                            <p className={`mt-1 flex items-center justify-end gap-1 text-[11px] ${m.mio ? 'text-white/75' : 'text-suave'}`}>
                                                {new Date(m.created_at).toLocaleString('es-BO', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })}
                                                {m.mio && (m.leido_en ? <CheckCheck className="size-3.5" aria-label="leído" /> : <Check className="size-3.5" aria-label="enviado" />)}
                                            </p>
                                        </div>
                                    </li>
                                ))}
                                {!mensajes.length && <li className="py-10 text-center text-sm text-suave">Escriba el primer mensaje.</li>}
                                <li ref={finRef} aria-hidden />
                            </ol>
                            <form onSubmit={enviar} className="flex items-end gap-2 border-t border-borde p-3">
                                <label htmlFor="msg-texto" className="sr-only">Mensaje</label>
                                <textarea
                                    id="msg-texto"
                                    rows={1}
                                    maxLength={1000}
                                    className="campo max-h-32 min-h-10 flex-1 resize-y"
                                    placeholder="Escriba un mensaje…"
                                    value={texto}
                                    onChange={(e) => setTexto(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) enviar(e); }}
                                />
                                <button className="btn btn-primario" disabled={enviando || !texto.trim()} aria-label="Enviar">
                                    <Send className="size-4" aria-hidden /> <span className="hidden sm:inline">Enviar</span>
                                </button>
                            </form>
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}
