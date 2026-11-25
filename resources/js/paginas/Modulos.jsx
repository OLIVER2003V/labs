import { useState } from 'react';
import { Check, HandHelping, Lock, PlayCircle, RotateCcw, X } from 'lucide-react';
import CrudPage from '../componentes/CrudPage';
import { Badge, Confirmar, Formulario, Modal, useToast } from '../componentes/ui';
import api, { erroresCampos, mensajeError } from '../lib/api';
import { P, validar } from '../lib/validar';
import { bs, DIAS, fecha, fechaHora, hora } from '../lib/formato';
import { useAuth } from '../contexto/AuthContext';

/*
 * Pantallas CRUD de cada módulo. Cada una solo define:
 *  - columnas de la tabla
 *  - campos del formulario con sus reglas de validación del cliente
 *  - permisos (deben coincidir con los de routes/api.php)
 */

const OPC_DIAS = [1, 2, 3, 4, 5, 6].map((d) => ({ id: d, label: DIAS[d] }));
const permisosDe = (r) => ({ crear: `${r}.crear`, editar: `${r}.editar`, eliminar: `${r}.eliminar` });

// ----------------------------------------------------------------- Laboratorios
export const Laboratorios = () => (
    <CrudPage
        titulo="Laboratorios"
        descripcion="Ambientes de computación de la facultad. Un laboratorio en mantenimiento no acepta reservas."
        endpoint="laboratorios"
        singular="laboratorio"
        permisos={permisosDe('laboratorios')}
        filtros={[{ name: 'estado', label: 'Estado', opciones: ['DISPONIBLE', 'MANTENIMIENTO', 'INHABILITADO'] }]}
        inicial={{ estado: 'DISPONIBLE', tarifa_hora: 150 }}
        columnas={[
            { key: 'codigo', label: 'Código', render: (f) => <strong>{f.codigo}</strong> },
            { key: 'nombre', label: 'Nombre' },
            { key: 'capacidad', label: 'Capacidad', render: (f) => `${f.capacidad} equipos` },
            { key: 'ubicacion', label: 'Ubicación' },
            { key: 'tarifa_hora', label: 'Tarifa/hora', render: (f) => bs(f.tarifa_hora) },
            { key: 'estado', label: 'Estado', render: (f) => <Badge valor={f.estado} /> },
        ]}
        campos={[
            { name: 'codigo', label: 'Código', requerido: true, patron: /^[A-Za-z0-9-]{2,10}$/, mensajePatron: 'Use de 2 a 10 letras, números o guiones (ej: LAB-41).', mayusculas: true, placeholder: 'LAB-41' },
            { name: 'nombre', label: 'Nombre', requerido: true, min: 3, max: 60 },
            { name: 'capacidad', label: 'Capacidad', tipo: 'number', requerido: true, min: 1, max: 200, entero: true },
            { name: 'tarifa_hora', label: 'Tarifa por hora (Bs)', tipo: 'number', requerido: true, min: 0, max: 10000, paso: '0.01' },
            { name: 'ubicacion', label: 'Ubicación', max: 100 },
            { name: 'estado', label: 'Estado', tipo: 'select', requerido: true, opciones: ['DISPONIBLE', 'MANTENIMIENTO', 'INHABILITADO'] },
        ]}
    />
);

// ----------------------------------------------------------------- Materias
export const Materias = () => (
    <CrudPage
        titulo="Materias"
        descripcion="Materias que usan los laboratorios en el horario base."
        endpoint="materias"
        singular="materia" femenino
        permisos={permisosDe('horarios')}
        columnas={[
            { key: 'sigla', label: 'Sigla', render: (f) => <strong>{f.sigla}</strong> },
            { key: 'nombre', label: 'Nombre' },
        ]}
        campos={[
            { name: 'sigla', label: 'Sigla', requerido: true, patron: /^[A-Za-z]{3}[0-9]{3}$/, mensajePatron: 'La sigla debe tener 3 letras y 3 números (ej: INF513).', mayusculas: true, max: 6 },
            { name: 'nombre', label: 'Nombre', requerido: true, min: 3, max: 100 },
        ]}
    />
);

// ----------------------------------------------------------------- Semestres
function AccionesSemestre({ fila, recargar }) {
    const { puede } = useAuth();
    const notificar = useToast();
    if (!puede('semestres.editar')) return null;
    const accion = async (que) => {
        try {
            notificar((await api.put(`/semestres/${fila.id}/${que}`)).data.message);
            recargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };
    return (
        <>
            {fila.estado === 'PLANIFICADO' && (
                <button className="btn btn-fantasma btn-sm text-exito" onClick={() => accion('activar')} title="Activar semestre">
                    <PlayCircle className="size-4" aria-hidden /> Activar
                </button>
            )}
            {fila.estado === 'ACTIVO' && (
                <button className="btn btn-fantasma btn-sm text-alerta" onClick={() => accion('cerrar')} title="Cerrar semestre">
                    <Lock className="size-4" aria-hidden /> Cerrar
                </button>
            )}
        </>
    );
}

export const Semestres = () => (
    <CrudPage
        titulo="Semestres"
        descripcion="Solo puede haber un semestre activo; el horario y las reservas trabajan sobre él."
        endpoint="semestres"
        singular="semestre"
        permisos={permisosDe('semestres')}
        columnas={[
            { key: 'gestion', label: 'Gestión', render: (f) => <strong>{f.gestion}</strong> },
            { key: 'fecha_inicio', label: 'Inicio', render: (f) => fecha(f.fecha_inicio) },
            { key: 'fecha_fin', label: 'Fin', render: (f) => fecha(f.fecha_fin) },
            { key: 'estado', label: 'Estado', render: (f) => <Badge valor={f.estado === 'ACTIVO' ? 'ACTIVO' : f.estado} /> },
        ]}
        acciones={(fila, recargar) => <AccionesSemestre fila={fila} recargar={recargar} />}
        campos={[
            { name: 'gestion', label: 'Gestión', requerido: true, patron: /^[1-4]-20\d{2}$/, mensajePatron: 'Formato: periodo-año (ej: 2-2026).', placeholder: '2-2026' },
            { name: 'fecha_inicio', label: 'Fecha de inicio', tipo: 'date', requerido: true },
            { name: 'fecha_fin', label: 'Fecha de fin', tipo: 'date', requerido: true, despuesDe: 'fecha_inicio' },
        ]}
    />
);

// ----------------------------------------------------------------- Fechas bloqueadas
export const FechasBloqueadas = () => (
    <CrudPage
        titulo="Fechas bloqueadas"
        descripcion="Feriados, PSA y mantenimientos: en estas fechas no se aceptan reservas. Puede bloquear un rango de días; si hay reservas afectadas se cancelan y se avisa a cada solicitante."
        endpoint="fechas-bloqueadas"
        singular="fecha bloqueada" femenino
        permisos={permisosDe('semestres')}
        filtros={[{ name: 'tipo', label: 'Tipo', opciones: ['FERIADO', 'PSA', 'MANTENIMIENTO', 'OTRO'] }]}
        columnas={[
            { key: 'fecha', label: 'Fecha', render: (f) => <strong>{fecha(f.fecha)}</strong> },
            { key: 'tipo', label: 'Tipo', render: (f) => <Badge valor="PENDIENTE">{f.tipo}</Badge> },
            { key: 'motivo', label: 'Motivo' },
            { key: 'laboratorio', label: 'Afecta a', render: (f) => f.laboratorio?.codigo ?? 'Todos los laboratorios' },
            { key: 'semestre', label: 'Semestre', render: (f) => f.semestre?.gestion },
        ]}
        campos={[
            { name: 'semestre_id', label: 'Semestre', tipo: 'select', opciones: 'semestres', requerido: true },
            { name: 'fecha_desde', label: 'Desde', tipo: 'date', requerido: true, soloCrear: true },
            {
                name: 'fecha_hasta', label: 'Hasta', tipo: 'date', soloCrear: true, ayuda: 'Opcional: déjelo vacío para bloquear un solo día. Los domingos se omiten.',
                validar: (x, v) => (v.fecha_desde && x < v.fecha_desde ? 'La fecha hasta no puede ser anterior a la fecha desde.' : null),
            },
            { name: 'fecha', label: 'Fecha', tipo: 'date', requerido: true, soloEditar: true },
            { name: 'tipo', label: 'Tipo', tipo: 'select', requerido: true, opciones: ['FERIADO', 'PSA', 'MANTENIMIENTO', 'OTRO'] },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', ayuda: 'Déjelo vacío si afecta a todos.' },
            { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 3, max: 200, ancho: 'completo' },
        ]}
    />
);

// ----------------------------------------------------------------- Horario base
export const Horarios = () => (
    <CrudPage
        titulo="Horario base"
        descripcion="Clases semanales del semestre activo. El sistema rechaza choques de laboratorio o de docente."
        endpoint="horarios"
        singular="clase" femenino
        permisos={permisosDe('horarios')}
        filtros={[{ name: 'dia_semana', label: 'Día', opciones: OPC_DIAS.map((d) => ({ value: d.id, label: d.label })) }]}
        columnas={[
            { key: 'dia_semana', label: 'Día', render: (f) => DIAS[f.dia_semana] },
            { key: 'hora', label: 'Horario', render: (f) => `${hora(f.hora_inicio)} – ${hora(f.hora_fin)}` },
            { key: 'laboratorio', label: 'Laboratorio', render: (f) => <strong>{f.laboratorio?.codigo}</strong> },
            { key: 'materia', label: 'Materia', render: (f) => `${f.materia?.sigla} - ${f.materia?.nombre}` },
            { key: 'grupo', label: 'Grupo' },
            { key: 'docente', label: 'Docente', render: (f) => f.docente?.nombre_completo },
        ]}
        aFormulario={(f) => ({ ...f, hora_inicio: hora(f.hora_inicio), hora_fin: hora(f.hora_fin) })}
        campos={[
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
            { name: 'materia_id', label: 'Materia', tipo: 'select', opciones: 'materias', requerido: true },
            { name: 'docente_id', label: 'Docente', tipo: 'select', opciones: 'docentes', requerido: true },
            { name: 'grupo', label: 'Grupo', requerido: true, patron: /^[A-Za-z0-9]{1,5}$/, mensajePatron: 'De 1 a 5 letras o números (ej: SA).', mayusculas: true },
            { name: 'dia_semana', label: 'Día', tipo: 'select', opciones: OPC_DIAS, requerido: true },
            { name: 'hora_inicio', label: 'Hora de inicio', tipo: 'time', requerido: true, validar: (v) => (v < '07:00' ? 'Los laboratorios abren a las 07:00.' : null) },
            { name: 'hora_fin', label: 'Hora de fin', tipo: 'time', requerido: true, despuesDe: 'hora_inicio', validar: (v) => (v > '22:00' ? 'Los laboratorios cierran a las 22:00.' : null) },
        ]}
    />
);

// ----------------------------------------------------------------- Inventario
const TIPOS_EQUIPO = ['COMPUTADORA', 'PROYECTOR', 'COMPONENTE', 'SOFTWARE', 'OTRO'];
export const Equipos = () => (
    <CrudPage
        titulo="Inventario"
        descripcion="Equipos, componentes y software de cada laboratorio. Al reportar una incidencia el equipo pasa a «en reparación» automáticamente."
        endpoint="equipos"
        singular="equipo"
        permisos={permisosDe('equipos')}
        inicial={{ estado: 'OPERATIVO', tipo: 'COMPUTADORA' }}
        filtros={[
            { name: 'tipo', label: 'Tipo', opciones: TIPOS_EQUIPO },
            { name: 'estado', label: 'Estado', opciones: ['OPERATIVO', 'EN_REPARACION', 'BAJA'] },
        ]}
        columnas={[
            { key: 'codigo', label: 'Código', render: (f) => <><strong>{f.codigo}</strong>{f.activo_fijo && <span className="block text-xs text-suave">AF {f.activo_fijo}</span>}</> },
            { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo },
            { key: 'tipo', label: 'Tipo', render: (f) => f.tipo.charAt(0) + f.tipo.slice(1).toLowerCase() },
            { key: 'descripcion', label: 'Descripción' },
            { key: 'estado', label: 'Estado', render: (f) => <Badge valor={f.estado} /> },
        ]}
        campos={[
            { name: 'codigo', label: 'Código', requerido: true, patron: /^[A-Za-z0-9][A-Za-z0-9 ,./-]{1,19}$/, mensajePatron: 'De 2 a 20 caracteres: letras, números, espacio, coma, punto o guion (ej: LAB-41, PC-01).', mayusculas: true },
            { name: 'activo_fijo', label: 'Activo fijo', patron: /^[A-Za-z0-9-]{3,30}$/, mensajePatron: 'De 3 a 30 letras, números o guiones (ej: U147124117399).', mayusculas: true },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
            { name: 'tipo', label: 'Tipo', tipo: 'select', opciones: TIPOS_EQUIPO, requerido: true },
            { name: 'estado', label: 'Estado', tipo: 'select', opciones: ['OPERATIVO', 'EN_REPARACION', 'BAJA'], requerido: true },
            { name: 'descripcion', label: 'Descripción', tipo: 'textarea', requerido: true, min: 3, max: 200, ancho: 'completo' },
        ]}
    />
);

// ----------------------------------------------------------------- Incidencias
export const Incidencias = () => (
    <CrudPage
        titulo="Incidencias"
        descripcion="Fallas reportadas en los equipos y su seguimiento hasta la solución."
        endpoint="incidencias"
        singular="incidencia" femenino
        permisos={{ crear: 'incidencias.crear', editar: 'incidencias.gestionar' }}
        filtros={[{ name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'EN_ATENCION', 'RESUELTA'] }]}
        columnas={[
            { key: 'id', label: 'N°', render: (f) => `#${f.id}` },
            { key: 'created_at', label: 'Reportada', render: (f) => fechaHora(f.created_at) },
            { key: 'equipo', label: 'Equipo', render: (f) => <><strong>{f.equipo?.codigo}</strong><span className="block text-xs text-suave">{f.equipo?.laboratorio?.codigo}</span></> },
            { key: 'descripcion', label: 'Falla' },
            { key: 'reportante', label: 'Reportado por', render: (f) => f.reportante?.nombre_completo },
            { key: 'estado', label: 'Estado', render: (f) => <><Badge valor={f.estado} />{f.solucion && <span className="mt-1 block text-xs text-suave">{f.solucion}</span>}</> },
        ]}
        aFormulario={(f) => ({ estado: f.estado, solucion: f.solucion ?? '' })}
        campos={[
            { name: 'equipo_id', label: 'Equipo', tipo: 'select', opciones: 'equipos', requerido: true, soloCrear: true, ancho: 'completo' },
            { name: 'descripcion', label: 'Descripción de la falla', tipo: 'textarea', requerido: true, min: 10, max: 300, soloCrear: true, ancho: 'completo' },
            { name: 'estado', label: 'Estado', tipo: 'select', opciones: ['PENDIENTE', 'EN_ATENCION', 'RESUELTA'], requerido: true, soloEditar: true },
            { name: 'solucion', label: 'Solución aplicada', tipo: 'textarea', min: 5, max: 300, soloEditar: true, ancho: 'completo', requerido: (v) => v.estado === 'RESUELTA' },
        ]}
    />
);

// ----------------------------------------------------------------- Objetos encontrados
const CAMPOS_ENTREGA = [
    { name: 'entregado_a', label: 'Nombre de quien recibe', requerido: true, min: 5, max: 100, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
    { name: 'ci_receptor', label: 'C.I. de quien recibe', requerido: true, patron: /^\d{5,10}(-?[A-Za-z0-9]{1,3})?$/, mensajePatron: 'Entre 5 y 10 dígitos (puede llevar complemento, ej: 1234567-1A).' },
];
function BotonEntregar({ fila, recargar }) {
    const { puede } = useAuth();
    const notificar = useToast();
    const [abierto, setAbierto] = useState(false);
    const [v, setV] = useState({});
    const [errores, setErrores] = useState({});
    const [enviando, setEnviando] = useState(false);
    if (fila.estado !== 'EN_CUSTODIA' || !puede('objetos.entregar')) return null;
    const enviar = async () => {
        const e = validar(v, CAMPOS_ENTREGA);
        setErrores(e);
        if (Object.keys(e).length) return;
        setEnviando(true);
        try {
            notificar((await api.put(`/objetos/${fila.id}/entregar`, v)).data.message);
            setAbierto(false);
            recargar();
        } catch (err) {
            setErrores(erroresCampos(err));
            notificar(mensajeError(err), 'error');
        } finally {
            setEnviando(false);
        }
    };
    return (
        <>
            <button className="btn btn-fantasma btn-sm text-exito" onClick={() => { setV({}); setErrores({}); setAbierto(true); }}>
                <HandHelping className="size-4" aria-hidden /> Entregar
            </button>
            <Modal abierto={abierto} titulo={`Entregar: ${fila.descripcion}`} onCerrar={() => setAbierto(false)}>
                <Formulario campos={CAMPOS_ENTREGA} valores={v} setValores={setV} errores={errores} onEnviar={enviar} enviando={enviando} textoBoton="Registrar entrega" onCancelar={() => setAbierto(false)} />
            </Modal>
        </>
    );
}

export const Objetos = () => (
    <CrudPage
        titulo="Objetos encontrados"
        descripcion="Objetos olvidados en los laboratorios, en custodia hasta su entrega al dueño."
        endpoint="objetos"
        singular="objeto"
        permisos={{ crear: 'objetos.crear', editar: 'objetos.crear', eliminar: 'objetos.crear' }}
        filtros={[{ name: 'estado', label: 'Estado', opciones: ['EN_CUSTODIA', 'ENTREGADO'] }]}
        columnas={[
            { key: 'fecha_hallazgo', label: 'Hallado', render: (f) => fecha(f.fecha_hallazgo) },
            { key: 'descripcion', label: 'Descripción', render: (f) => <strong>{f.descripcion}</strong> },
            { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo },
            { key: 'registrador', label: 'Registró', render: (f) => f.registrador?.nombre_completo },
            { key: 'estado', label: 'Estado', render: (f) => <><Badge valor={f.estado} />{f.entregado_a && <span className="mt-1 block text-xs text-suave">{f.entregado_a} (CI {f.ci_receptor}) · {fechaHora(f.fecha_entrega)}</span>}</> },
        ]}
        acciones={(fila, recargar) => <BotonEntregar fila={fila} recargar={recargar} />}
        campos={[
            { name: 'descripcion', label: 'Descripción del objeto', requerido: true, min: 3, max: 200, ancho: 'completo' },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
            { name: 'fecha_hallazgo', label: 'Fecha de hallazgo', tipo: 'date', requerido: true, noFuturo: true },
        ]}
    />
);

// ----------------------------------------------------------------- Turnos
export const Turnos = () => (
    <CrudPage
        titulo="Turnos de auxiliares"
        descripcion="Turnos de 4 horas de lunes a viernes y rol rotativo de sábados (semestre activo)."
        endpoint="turnos"
        singular="turno"
        permisos={{ crear: 'turnos.crear', editar: 'turnos.crear', eliminar: 'turnos.crear' }}
        filtros={[{ name: 'dia_semana', label: 'Día', opciones: OPC_DIAS.map((d) => ({ value: d.id, label: d.label })) }]}
        columnas={[
            { key: 'auxiliar', label: 'Auxiliar', render: (f) => <strong>{f.auxiliar?.nombre_completo}</strong> },
            { key: 'dia_semana', label: 'Día', render: (f) => DIAS[f.dia_semana] },
            { key: 'hora', label: 'Horario', render: (f) => `${hora(f.hora_inicio)} – ${hora(f.hora_fin)}` },
            { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo ?? 'General' },
        ]}
        aFormulario={(f) => ({ ...f, hora_inicio: hora(f.hora_inicio), hora_fin: hora(f.hora_fin), laboratorio_id: f.laboratorio_id ?? '' })}
        campos={[
            { name: 'auxiliar_id', label: 'Auxiliar', tipo: 'select', opciones: 'auxiliares', requerido: true },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios' },
            { name: 'dia_semana', label: 'Día', tipo: 'select', opciones: OPC_DIAS, requerido: true },
            { name: 'hora_inicio', label: 'Hora de inicio', tipo: 'time', requerido: true },
            { name: 'hora_fin', label: 'Hora de fin', tipo: 'time', requerido: true, despuesDe: 'hora_inicio' },
        ]}
    />
);

// ----------------------------------------------------------------- Usuarios
/** Aprobar o rechazar una solicitud de cuenta de estudiante (estado PENDIENTE) */
function AccionesSolicitud({ fila, recargar }) {
    const { puede, refrescar } = useAuth();
    const notificar = useToast();
    const [rechazar, setRechazar] = useState(false);
    if (fila.estado !== 'PENDIENTE' || !puede('usuarios.editar')) return null;
    const ejecutar = async (accion) => {
        try {
            notificar((await api.put(`/usuarios/${fila.id}/${accion}`)).data.message);
            recargar();
            refrescar(); // actualiza el número de pendientes del menú
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };
    return (
        <>
            <button className="btn btn-fantasma btn-sm text-exito" onClick={() => ejecutar('aprobar')}><Check className="size-4" aria-hidden /> Aprobar</button>
            <button className="btn btn-fantasma btn-sm text-peligro" onClick={() => setRechazar(true)}><X className="size-4" aria-hidden /> Rechazar</button>
            <Confirmar
                abierto={rechazar}
                titulo="Rechazar solicitud"
                mensaje={`Se eliminará la solicitud de ${fila.nombre_completo} (${fila.email}). Podrá volver a registrarse con datos correctos.`}
                textoBoton="Rechazar"
                onCerrar={() => setRechazar(false)}
                onConfirmar={async () => {
                    await ejecutar('rechazar');
                    setRechazar(false);
                }}
            />
        </>
    );
}

function BotonRestaurarUsuario({ fila, recargar }) {
    const { puede } = useAuth();
    const notificar = useToast();
    if (!fila.deleted_at || !puede('usuarios.eliminar')) return null;
    return (
        <button
            className="btn btn-fantasma btn-sm text-exito"
            onClick={async () => {
                try {
                    notificar((await api.put(`/usuarios/${fila.id}/restaurar`)).data.message);
                    recargar();
                } catch (e) {
                    notificar(mensajeError(e), 'error');
                }
            }}
        >
            <RotateCcw className="size-4" aria-hidden /> Restaurar
        </button>
    );
}

export const Usuarios = () => (
    <CrudPage
        titulo="Usuarios"
        descripcion="Cuentas del sistema y el rol que cumple cada una."
        endpoint="usuarios"
        singular="usuario"
        permisos={permisosDe('usuarios')}
        inicial={{ estado: 'ACTIVO' }}
        filtros={[
            { name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'ACTIVO', 'INACTIVO'] },
            { name: 'eliminados', label: 'Registros', textoTodos: 'Usuarios vigentes', opciones: [{ value: 1, label: 'Usuarios eliminados' }] },
        ]}
        acciones={(fila, recargar) => <><AccionesSolicitud fila={fila} recargar={recargar} /><BotonRestaurarUsuario fila={fila} recargar={recargar} /></>}
        columnas={[
            { key: 'codigo', label: 'Código' },
            { key: 'nombre', label: 'Nombre', render: (f) => <><strong>{f.nombre_completo}</strong><span className="block text-xs text-suave">{f.email}</span></> },
            { key: 'telefono', label: 'Teléfono' },
            {
                key: 'rol', label: 'Rol',
                render: (f) => <>{f.rol?.nombre}{f.carrera && <span className="block text-xs text-suave">{f.carrera}</span>}{f.estado === 'PENDIENTE' && f.detalle_registro && <span className="mt-1 block max-w-64 text-xs italic text-suave">“{f.detalle_registro}”</span>}</>,
            },
            { key: 'ultimo_acceso', label: 'Último acceso', render: (f) => fechaHora(f.ultimo_acceso) },
            { key: 'estado', label: 'Estado', render: (f) => (f.deleted_at ? <Badge valor="BAJA">Eliminado</Badge> : <Badge valor={f.estado} />) },
        ]}
        aFormulario={(f) => ({ codigo: f.codigo, nombre: f.nombre, apellido: f.apellido, email: f.email, telefono: f.telefono ?? '', rol_id: f.rol_id, estado: f.estado, password: '' })}
        campos={[
            { name: 'codigo', label: 'Código / Registro', requerido: true, patron: /^[A-Za-z0-9-]{1,20}$/, mensajePatron: 'Hasta 20 letras, números o guiones.' },
            { name: 'rol_id', label: 'Rol', tipo: 'select', opciones: 'roles', requerido: true },
            { name: 'nombre', label: 'Nombre(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
            { name: 'apellido', label: 'Apellido(s)', requerido: true, min: 2, max: 60, patron: P.soloLetras, mensajePatron: 'Solo letras y espacios.' },
            { name: 'email', label: 'Correo electrónico', tipo: 'email', requerido: true, max: 100 },
            { name: 'telefono', label: 'Teléfono', patron: P.telefono, mensajePatron: 'El teléfono debe tener 8 dígitos y empezar con 6 o 7.' },
            { name: 'estado', label: 'Estado', tipo: 'select', opciones: ['ACTIVO', 'INACTIVO'], requerido: true },
            {
                name: 'password', label: 'Contraseña', tipo: 'password', requerido: true, requeridoAlCrear: true, autocompletar: 'new-password',
                patron: P.password, mensajePatron: 'Mínimo 8 caracteres, con letras y números.',
                ayuda: 'Mínimo 8 caracteres, con letras y números.', ayudaEditar: 'Déjela vacía para no cambiarla.',
            },
        ]}
    />
);

// ----------------------------------------------------------------- Conceptos de cobro
export const Conceptos = () => (
    <CrudPage
        titulo="Conceptos de cobro"
        descripcion="Catálogo de cobros: alquiler de laboratorios, multas, certificados y cursos."
        endpoint="conceptos"
        singular="concepto"
        permisos={permisosDe('conceptos')}
        inicial={{ activo: true }}
        columnas={[
            { key: 'codigo', label: 'Código', render: (f) => <strong>{f.codigo}</strong> },
            { key: 'nombre', label: 'Nombre' },
            { key: 'monto', label: 'Monto', render: (f) => bs(f.monto) },
            { key: 'activo', label: 'Estado', render: (f) => <Badge valor={f.activo ? 'ACTIVO' : 'INACTIVO'} /> },
        ]}
        campos={[
            { name: 'codigo', label: 'Código', requerido: true, patron: P.codigo, mensajePatron: 'De 3 a 20 letras, números o guiones.', mayusculas: true },
            { name: 'monto', label: 'Monto (Bs)', tipo: 'number', requerido: true, min: 0.01, max: 100000, paso: '0.01' },
            { name: 'nombre', label: 'Nombre', requerido: true, min: 3, max: 100, ancho: 'completo' },
            { name: 'activo', label: 'Activo', tipo: 'checkbox', textoCheckbox: 'Disponible para pagos' },
        ]}
    />
);

