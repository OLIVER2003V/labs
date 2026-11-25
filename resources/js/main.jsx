import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from './contexto/AuthContext';
import { TemaProvider } from './contexto/TemaContext';
import { Cargando, ToastProvider } from './componentes/ui';
import Layout from './componentes/Layout';
import Login from './paginas/Login';
import Registro from './paginas/Registro';
import Inicio from './paginas/Inicio';
import Dashboard from './paginas/Dashboard';
import Disponibilidad from './paginas/Disponibilidad';
import Reservas from './paginas/Reservas';
import Roles from './paginas/Roles';
import { Auditoria, Bitacora } from './paginas/Bitacora';
import Asistencia from './paginas/Asistencia';
import { MetodosPago, MisPagos, PlanesPago } from './paginas/Pagos';
import Perfil from './paginas/Perfil';
import Permutas from './paginas/Permutas';
import Mensajes from './paginas/Mensajes';
import {
    Conceptos, Equipos, FechasBloqueadas, Horarios, Incidencias, Laboratorios, Materias, Objetos, Semestres, Turnos, Usuarios,
} from './paginas/Modulos';
import { NoEncontrado, SinPermiso } from './paginas/Errores';
import '../css/app.css';

/** Protege una ruta: exige sesión y (opcionalmente) un permiso de la matriz de acceso */
function Protegida({ permiso, children }) {
    const { usuario, cargando, puede } = useAuth();
    if (cargando) return <Cargando texto="Verificando sesión…" />;
    if (!usuario) return <Navigate to="/login" replace />;
    if (permiso && ![].concat(permiso).some(puede)) return <SinPermiso />;
    return children;
}

function App() {
    const { usuario, cargando } = useAuth();
    // Rutas del frontend → permiso requerido (deben coincidir con la tabla menus)
    const rutas = [
        ['/dashboard', Dashboard, 'dashboard.ver'],
        ['/disponibilidad', Disponibilidad, 'disponibilidad.ver'],
        ['/reservas', Reservas, 'reservas.ver'],
        ['/horarios', Horarios, 'horarios.ver'],
        ['/materias', Materias, 'horarios.ver'],
        ['/equipos', Equipos, 'equipos.ver'],
        ['/incidencias', Incidencias, 'incidencias.ver'],
        ['/objetos', Objetos, 'objetos.ver'],
        ['/turnos', Turnos, 'turnos.ver'],
        ['/asistencia', Asistencia, 'asistencia.ver'],
        ['/permutas', Permutas, 'permutas.ver'],
        ['/mensajes', Mensajes, 'mensajes.usar'],
        ['/pagos', MisPagos, 'pagos.ver'],
        ['/pagos/metodos', MetodosPago, 'pagos.pagar'],
        ['/pagos/planes', PlanesPago, 'planes.gestionar'],
        ['/pagos/conceptos', Conceptos, 'conceptos.crear'],
        ['/laboratorios', Laboratorios, 'laboratorios.ver'],
        ['/semestres', Semestres, 'semestres.ver'],
        ['/fechas-bloqueadas', FechasBloqueadas, 'semestres.ver'],
        ['/usuarios', Usuarios, 'usuarios.ver'],
        ['/roles', Roles, 'roles.ver'],
        ['/bitacora', Bitacora, 'bitacora.ver'],
        ['/auditoria', Auditoria, 'auditoria.ver'],
    ];

    return (
        <Routes>
            <Route path="/login" element={cargando ? <Cargando /> : usuario ? <Navigate to="/" replace /> : <Login />} />
            <Route path="/registro" element={cargando ? <Cargando /> : usuario ? <Navigate to="/" replace /> : <Registro />} />
            <Route element={<Protegida><Layout /></Protegida>}>
                <Route index element={<Inicio />} />
                <Route path="perfil" element={<Perfil />} />
                {rutas.map(([ruta, Pagina, permiso]) => (
                    <Route key={ruta} path={ruta} element={<Protegida permiso={permiso}><Pagina /></Protegida>} />
                ))}
                <Route path="*" element={<NoEncontrado />} />
            </Route>
        </Routes>
    );
}

createRoot(document.getElementById('root')).render(
    <StrictMode>
        {/* basename: carpeta donde está publicada la app (ej: /inf513/grupo08sa/proyecto2) */}
        <BrowserRouter basename={window.APP?.base || '/'}>
            <TemaProvider>
                <ToastProvider>
                    <AuthProvider>
                        <App />
                    </AuthProvider>
                </ToastProvider>
            </TemaProvider>
        </BrowserRouter>
    </StrictMode>,
);
