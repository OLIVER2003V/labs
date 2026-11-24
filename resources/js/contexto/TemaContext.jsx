import { createContext, useContext, useEffect, useState } from 'react';
import api from '../lib/api';

/**
 * Temas y accesibilidad.
 *  tema:      'ninos' | 'jovenes' | 'adultos'   (segmento de usuarios)
 *  modo:      'claro' | 'oscuro' | 'auto'       (auto = día de 07:00 a 19:00, noche el resto)
 *  fuente:    80 a 160 (% del tamaño de letra)
 *  contraste: true = alto contraste (WCAG)
 * Se guardan en localStorage (funciona sin sesión) y, si hay sesión, también en el servidor.
 */
const TemaContext = createContext(null);
const PREDETERMINADO = { tema: 'adultos', modo: 'auto', fuente: 100, contraste: false };

const leer = () => {
    try {
        return { ...PREDETERMINADO, ...JSON.parse(localStorage.getItem('preferencias') || '{}') };
    } catch {
        return PREDETERMINADO;
    }
};

export const esDeDia = () => {
    const h = new Date().getHours();
    return h >= 7 && h < 19;
};

export function TemaProvider({ children }) {
    const [pref, setPref] = useState(leer);
    const [modoReal, setModoReal] = useState('claro');

    // Aplica las preferencias al elemento <html>
    useEffect(() => {
        const calcular = () => (pref.modo === 'auto' ? (esDeDia() ? 'claro' : 'oscuro') : pref.modo);
        const aplicar = () => {
            const r = document.documentElement;
            const modo = calcular();
            r.dataset.tema = pref.tema;
            r.dataset.modo = modo;
            if (pref.contraste) r.dataset.contraste = 'alto';
            else delete r.dataset.contraste;
            r.style.fontSize = `${pref.fuente}%`;
            setModoReal(modo);
        };
        aplicar();
        localStorage.setItem('preferencias', JSON.stringify(pref));
        // En modo automático se revisa cada minuto si ya cambió de día a noche
        const t = pref.modo === 'auto' ? setInterval(aplicar, 60000) : null;
        return () => t && clearInterval(t);
    }, [pref]);

    const cambiar = (cambios, guardarEnServidor = true) => {
        setPref((p) => {
            const nuevo = { ...p, ...cambios };
            if (guardarEnServidor && localStorage.getItem('token')) {
                api.put('/perfil/preferencias', nuevo).catch(() => {});
            }
            return nuevo;
        });
    };

    /** Al iniciar sesión se cargan las preferencias guardadas del usuario */
    const cargarDeUsuario = (p) => p && setPref({ ...PREDETERMINADO, ...p });

    return <TemaContext.Provider value={{ ...pref, modoReal, cambiar, cargarDeUsuario }}>{children}</TemaContext.Provider>;
}

export const useTema = () => useContext(TemaContext);
