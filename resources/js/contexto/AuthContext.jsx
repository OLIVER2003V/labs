import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import api from '../lib/api';

/**
 * Sesión del usuario: datos, permisos (matriz de acceso) y menú que viene de la base de datos.
 * puede('reservas.crear') → true/false  (para mostrar u ocultar botones; el servidor igual valida)
 */
const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [usuario, setUsuario] = useState(null);
    const [permisos, setPermisos] = useState([]);
    const [menu, setMenu] = useState([]);
    const [avisos, setAvisos] = useState({}); // { '/usuarios': 3 } → número en el menú
    const [cargando, setCargando] = useState(true);

    const aplicar = (d) => {
        setUsuario(d.usuario);
        setPermisos(d.permisos);
        setMenu(d.menu);
        setAvisos(d.avisos ?? {});
    };

    // Vuelve a pedir los números del menú (mensajes sin leer, permutas por responder…)
    const refrescarAvisos = useCallback(() => {
        api.get('/notificaciones').then((r) => setAvisos(r.data.avisos ?? {})).catch(() => {});
    }, []);

    const limpiar = useCallback(() => {
        localStorage.removeItem('token');
        setUsuario(null);
        setPermisos([]);
        setMenu([]);
    }, []);

    // Al abrir la página, si hay token guardado, recupera la sesión
    useEffect(() => {
        if (!localStorage.getItem('token')) {
            setCargando(false);
            return;
        }
        api.get('/me').then((r) => aplicar(r.data)).catch(limpiar).finally(() => setCargando(false));
    }, [limpiar]);

    useEffect(() => {
        window.addEventListener('sesion-expirada', limpiar);
        return () => window.removeEventListener('sesion-expirada', limpiar);
    }, [limpiar]);

    const login = async (email, password) => {
        const { data } = await api.post('/login', { email, password });
        localStorage.setItem('token', data.token);
        aplicar(data);
        return data;
    };

    const logout = async () => {
        try {
            await api.post('/logout');
        } finally {
            limpiar();
        }
    };

    const refrescar = async () => aplicar((await api.get('/me')).data);
    const puede = useCallback((p) => permisos.includes(p), [permisos]);

    return (
        <AuthContext.Provider value={{ usuario, permisos, menu, avisos, setAvisos, refrescarAvisos, cargando, login, logout, refrescar, puede, setUsuario }}>
            {children}
        </AuthContext.Provider>
    );
}

export const useAuth = () => useContext(AuthContext);
