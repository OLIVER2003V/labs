import { useState } from 'react';
import { Check, FileSpreadsheet, HandHelping, ImageOff, Lock, LogOut, PackageSearch, PlayCircle, RotateCcw, ShieldAlert, Unlock, X } from 'lucide-react';
import ImportarHorario from './ImportarHorario';
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
        inicial={{ estado: 'DISPONIBLE' }}
        columnas={[
            { key: 'codigo', label: 'Código', render: (f) => <strong>{f.codigo}</strong> },
            { key: 'nombre', label: 'Nombre' },
            { key: 'capacidad', label: 'Capacidad', render: (f) => `${f.capacidad} equipos` },
            { key: 'ubicacion', label: 'Ubicación' },
            { key: 'estado', label: 'Estado', render: (f) => <Badge valor={f.estado} /> },
        ]}
        campos={[
            { name: 'codigo', label: 'Código', requerido: true, patron: /^[A-Za-z0-9-]{2,10}$/, mensajePatron: 'Use de 2 a 10 letras, números o guiones (ej: LAB-41).', mayusculas: true, placeholder: 'LAB-41' },
            { name: 'nombre', label: 'Nombre', requerido: true, min: 3, max: 60 },
            { name: 'capacidad', label: 'Capacidad', tipo: 'number', requerido: true, min: 1, max: 200, entero: true },
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
const TIPOS_BLOQUEO = ['FERIADO', 'PSA', 'CUP', 'MANTENIMIENTO', 'OTRO'];
export const FechasBloqueadas = () => (
    <CrudPage
        titulo="Fechas bloqueadas"
        descripcion="Feriados, PSA, CUP y mantenimientos. Puede bloquear un rango de días, varios laboratorios y solo ciertas horas (fuera de ese horario el laboratorio sigue disponible). Se permite hasta 90 días antes del inicio del semestre. Si hay reservas afectadas se cancelan y se avisa a cada solicitante."
        endpoint="fechas-bloqueadas"
        singular="fecha bloqueada" femenino
        permisos={permisosDe('semestres')}
        inicial={{ laboratorios: [] }}
        aFormulario={(f) => ({ ...f, hora_inicio: f.hora_inicio ? hora(f.hora_inicio) : '', hora_fin: f.hora_fin ? hora(f.hora_fin) : '', laboratorio_id: f.laboratorio_id ?? '' })}
        filtros={[{ name: 'tipo', label: 'Tipo', opciones: TIPOS_BLOQUEO }]}
        columnas={[
            { key: 'fecha', label: 'Fecha', render: (f) => <strong>{fecha(f.fecha)}</strong> },
            { key: 'horario', label: 'Horario', render: (f) => (f.hora_inicio ? `${hora(f.hora_inicio)} – ${hora(f.hora_fin)}` : 'Todo el día') },
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
            {
                name: 'hora_inicio', label: 'Desde la hora', tipo: 'time', ayuda: 'Opcional: vacío = todo el día.',
                requerido: (v) => !!v.hora_fin,
            },
            {
                name: 'hora_fin', label: 'Hasta la hora', tipo: 'time', requerido: (v) => !!v.hora_inicio,
                validar: (x, v) => (v.hora_inicio && x <= v.hora_inicio ? 'La hora final debe ser posterior a la inicial.' : null),
            },
            { name: 'tipo', label: 'Tipo', tipo: 'select', requerido: true, opciones: TIPOS_BLOQUEO, ayuda: 'PSA: Prueba de Suficiencia Académica · CUP: Curso Preuniversitario' },
            {
                name: 'laboratorios', label: 'Laboratorios', tipo: 'casillas', opciones: 'laboratorios', soloCrear: true, ancho: 'completo',
                etiquetaCorta: (l) => l.split(' - ')[0], ayuda: 'Marque los laboratorios afectados. Si no marca ninguno, el bloqueo es para TODOS.',
            },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', soloEditar: true, ayuda: 'Vacío = todos los laboratorios.' },
            { name: 'motivo', label: 'Motivo', tipo: 'textarea', requerido: true, min: 3, max: 200, ancho: 'completo' },
        ]}
    />
);

// ----------------------------------------------------------------- Horario base
export const Horarios = () => {
    const { puede } = useAuth();
    const [importar, setImportar] = useState(false);
    const [recargar, setRecargar] = useState(0);
    return (
    <>
    <ImportarHorario abierto={importar} onCerrar={() => setImportar(false)} onImportado={() => setRecargar((n) => n + 1)} />
    <CrudPage
        recargarClave={recargar}
        botonesExtra={() => puede('horarios.crear') && (
            <button className="btn btn-secundario" onClick={() => setImportar(true)}>
                <FileSpreadsheet className="size-4" aria-hidden /> Importar desde Excel
            </button>
        )}
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
    </>
    );
};

// ----------------------------------------------------------------- Inventario
const TIPOS_EQUIPO = ['COMPUTADORA', 'PROYECTOR', 'COMPONENTE', 'SOFTWARE', 'OTRO'];
// CU6 - Configuración de red: solo para equipos que se conectan (no software ni componentes)
const sinRed = (v) => ['SOFTWARE', 'COMPONENTE'].includes(v.tipo);
const IPV4 = /^((25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)\.){3}(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)$/;
const ipANumero = (ip) => ip.split('.').reduce((n, o) => n * 256 + Number(o), 0);
const campoIp = (name, label, extra = {}) => ({ name, label, patron: IPV4, mensajePatron: 'Escriba una IP válida (ej: 172.20.172.5).', oculto: sinRed, placeholder: extra.placeholder, ...extra });
const CAMPOS_RED = [
    { name: 'titulo_red', label: 'Configuración de red (opcional)', tipo: 'seccion', oculto: sinRed, ancho: 'completo' },
    campoIp('red_ip', 'IP', { placeholder: '172.20.172.5', ayuda: 'Única en toda la facultad. Déjela vacía si el equipo no tiene red.' }),
    campoIp('red_mascara', 'Máscara', {
        placeholder: '255.255.255.0', requerido: (v) => !!v.red_ip,
        validar: (m) => { const inv = (~ipANumero(m)) >>> 0; return (inv & (inv + 1)) === 0 && m !== '0.0.0.0' ? null : 'Máscara no válida (ej: 255.255.255.0).'; },
    }),
    campoIp('red_gateway', 'Gateway', {
        placeholder: '172.20.172.254', requerido: (v) => !!v.red_ip,
        validar: (g, v) => {
            if (!v.red_ip || !IPV4.test(v.red_ip) || !IPV4.test(v.red_mascara ?? '')) return null;
            if (g === v.red_ip) return 'El gateway no puede ser la misma IP del equipo.';
            const m = ipANumero(v.red_mascara);
            return ((ipANumero(g) & m) >>> 0) === ((ipANumero(v.red_ip) & m) >>> 0) ? null : 'El gateway debe estar en la misma subred que la IP.';
        },
    }),
    campoIp('red_dns1', 'DNS primario', { placeholder: '10.10.0.251', requerido: (v) => !!v.red_ip }),
    campoIp('red_dns2', 'DNS secundario', { placeholder: '8.8.8.8' }),
    { name: 'red_proxy', label: 'Proxy', placeholder: '172.20.172.254:3128', oculto: sinRed, patron: /^[A-Za-z0-9.-]{3,50}:\d{2,5}$/, mensajePatron: 'Formato servidor:puerto (ej: 172.20.172.254:3128).' },
    { name: 'red_grupo', label: 'Grupo de red', placeholder: 'LAB41', oculto: sinRed, mayusculas: true, patron: /^[A-Za-z0-9-]{2,20}$/, mensajePatron: 'De 2 a 20 letras, números o guiones (ej: LAB41).' },
];
/** La red viene anidada (f.red.ip) y el formulario la usa plana (red_ip) */
const equipoAFormulario = (f) => ({
    codigo: f.codigo, activo_fijo: f.activo_fijo ?? '', laboratorio_id: f.laboratorio_id, tipo: f.tipo, estado: f.estado, descripcion: f.descripcion,
    red_ip: f.red?.ip ?? '', red_mascara: f.red?.mascara ?? '', red_gateway: f.red?.gateway ?? '', red_dns1: f.red?.dns1 ?? '',
    red_dns2: f.red?.dns2 ?? '', red_proxy: f.red?.proxy ?? '', red_grupo: f.red?.grupo_red ?? '',
});
export const Equipos = () => (
    <CrudPage
        titulo="Inventario"
        descripcion="Equipos, componentes y software de cada laboratorio, con su configuración de red. Al reportar una incidencia el equipo pasa a «en reparación» automáticamente. Puede buscar por IP."
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
            {
                key: 'red', label: 'Red',
                render: (f) => (f.red ? <><span className="font-mono text-sm">{f.red.ip}</span><span className="block text-xs text-suave">GW {f.red.gateway}{f.red.grupo_red ? ` · ${f.red.grupo_red}` : ''}</span></> : <span className="text-suave">—</span>),
            },
            { key: 'estado', label: 'Estado', render: (f) => <Badge valor={f.estado} /> },
        ]}
        aFormulario={equipoAFormulario}
        campos={[
            { name: 'codigo', label: 'Código', requerido: true, patron: /^[A-Za-z0-9][A-Za-z0-9 ,./-]{1,19}$/, mensajePatron: 'De 2 a 20 caracteres: letras, números, espacio, coma, punto o guion (ej: LAB-41, PC-01).', mayusculas: true },
            { name: 'activo_fijo', label: 'Activo fijo', patron: /^[A-Za-z0-9-]{3,30}$/, mensajePatron: 'De 3 a 30 letras, números o guiones (ej: U147124117399).', mayusculas: true },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
            { name: 'tipo', label: 'Tipo', tipo: 'select', opciones: TIPOS_EQUIPO, requerido: true },
            { name: 'estado', label: 'Estado', tipo: 'select', opciones: ['OPERATIVO', 'EN_REPARACION', 'BAJA'], requerido: true },
            { name: 'descripcion', label: 'Descripción', tipo: 'textarea', requerido: true, min: 3, max: 200, ancho: 'completo' },
            ...CAMPOS_RED,
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

/**
 * Foto del objeto: la que subió el auxiliar o, si no hay, una imagen de referencia según el tipo de objeto.
 * grande = para la tarjeta; si no, miniatura para la lista. Al tocarla se ve en grande.
 */
function FotoObjeto({ fila, grande = false }) {
    const [ver, setVer] = useState(false);
    const [rota, setRota] = useState(false);
    const caja = grande ? 'aspect-[4/3] w-full' : 'size-14 rounded-tema border border-borde';
    if (!fila.imagen_url || rota) {
        return (
            <div className={`${caja} grid shrink-0 place-items-center bg-superficie-2 text-suave`} title="Sin imagen">
                <PackageSearch className={grande ? 'size-12' : 'size-6'} aria-hidden />
            </div>
        );
    }
    // Las de referencia son fotos de producto: se muestran enteras sobre blanco. Las propias llenan el recuadro.
    const ajuste = fila.imagen_propia ? 'object-cover' : 'object-contain bg-white p-1';
    return (
        <>
            <button type="button" className={`${caja} relative shrink-0 overflow-hidden`} onClick={() => setVer(true)} aria-label={`Ver la foto de ${fila.descripcion}`}>
                <img src={fila.imagen_url} alt="" loading="lazy" className={`size-full ${ajuste}`} onError={() => setRota(true)} />
                {grande && !fila.imagen_propia && (
                    <span className="absolute bottom-1.5 left-1.5 rounded bg-black/65 px-1.5 py-0.5 text-[10px] font-semibold text-white">Imagen de referencia</span>
                )}
            </button>
            <Modal abierto={ver} titulo={fila.descripcion} onCerrar={() => setVer(false)} ancho="max-w-2xl">
                <img src={fila.imagen_url} alt={fila.descripcion} className={`max-h-[70vh] w-full rounded-tema ${fila.imagen_propia ? 'object-contain' : 'object-contain bg-white p-2'}`} />
                <p className="mt-2 text-xs text-suave">
                    {fila.imagen_propia ? 'Foto del objeto tomada por el auxiliar.' : 'Imagen de referencia según el tipo de objeto (no es la foto del objeto real).'}
                </p>
            </Modal>
        </>
    );
}

/** Quitar la foto subida (vuelve a la imagen de referencia) */
function BotonQuitarFoto({ fila, recargar }) {
    const { puede } = useAuth();
    const notificar = useToast();
    const [confirmar, setConfirmar] = useState(false);
    if (!fila.imagen_propia || fila.estado !== 'EN_CUSTODIA' || !puede('objetos.crear')) return null;
    return (
        <>
            <button className="btn btn-fantasma btn-sm" onClick={() => setConfirmar(true)} title="Quitar la foto">
                <ImageOff className="size-4" aria-hidden /> <span className="sr-only">Quitar foto</span>
            </button>
            <Confirmar
                abierto={confirmar}
                titulo="Quitar la foto"
                mensaje="Se borrará la foto subida y se mostrará la imagen de referencia del tipo de objeto."
                textoBoton="Quitar foto"
                onConfirmar={async () => {
                    try {
                        notificar((await api.delete(`/objetos/${fila.id}/imagen`)).data.message);
                        recargar();
                    } catch (e) {
                        notificar(mensajeError(e), 'error');
                    }
                    setConfirmar(false);
                }}
                onCerrar={() => setConfirmar(false)}
            />
        </>
    );
}

/** Tarjeta de un objeto: foto grande y sus datos */
const TarjetaObjeto = (f) => (
    <>
        <FotoObjeto fila={f} grande />
        <div className="space-y-1 p-3 text-sm">
            <p className="font-semibold leading-snug">{f.descripcion}</p>
            <p className="text-xs text-suave">{f.laboratorio?.codigo} · hallado el {fecha(f.fecha_hallazgo)}</p>
            <p className="text-xs text-suave">Registró: {f.registrador?.nombre_completo}</p>
            <div className="pt-1">
                <Badge valor={f.estado} />
                {f.entregado_a && <span className="mt-1 block text-xs text-suave">A {f.entregado_a} (CI {f.ci_receptor}) · {fechaHora(f.fecha_entrega)}</span>}
            </div>
        </div>
    </>
);

/** Sube la foto elegida en el formulario (después de guardar el objeto) */
const subirFoto = async (registro, valores) => {
    if (!(valores.foto instanceof File)) return null;
    const datos = new FormData();
    datos.append('imagen', valores.foto);
    await api.post(`/objetos/${registro.id}/imagen`, datos, { headers: { 'Content-Type': 'multipart/form-data' } });
    return 'Objeto guardado con su foto.';
};

export const Objetos = () => (
    <CrudPage
        titulo="Objetos encontrados"
        descripcion="Objetos olvidados en los laboratorios, en custodia hasta su entrega al dueño. Los registran y los entregan los auxiliares de turno."
        endpoint="objetos"
        singular="objeto"
        permisos={{ crear: 'objetos.crear', editar: 'objetos.crear', eliminar: 'objetos.crear' }}
        filtros={[{ name: 'estado', label: 'Estado', opciones: ['EN_CUSTODIA', 'ENTREGADO'] }]}
        tarjeta={TarjetaObjeto}
        alGuardar={subirFoto}
        columnas={[
            { key: 'foto', label: 'Foto', exportar: false, render: (f) => <FotoObjeto fila={f} /> },
            { key: 'fecha_hallazgo', label: 'Hallado', render: (f) => fecha(f.fecha_hallazgo) },
            { key: 'descripcion', label: 'Descripción', render: (f) => <strong>{f.descripcion}</strong> },
            { key: 'laboratorio', label: 'Laboratorio', render: (f) => f.laboratorio?.codigo },
            { key: 'registrador', label: 'Registró (auxiliar)', render: (f) => f.registrador?.nombre_completo },
            { key: 'estado', label: 'Estado', render: (f) => <><Badge valor={f.estado} />{f.entregado_a && <span className="mt-1 block text-xs text-suave">{f.entregado_a} (CI {f.ci_receptor}) · {fechaHora(f.fecha_entrega)}</span>}</> },
        ]}
        acciones={(fila, recargar) => <><BotonQuitarFoto fila={fila} recargar={recargar} /><BotonEntregar fila={fila} recargar={recargar} /></>}
        aFormulario={(f) => ({ descripcion: f.descripcion, laboratorio_id: f.laboratorio_id, fecha_hallazgo: f.fecha_hallazgo, foto: '', imagen_url: f.imagen_url })}
        campos={[
            { name: 'descripcion', label: 'Descripción del objeto', requerido: true, min: 3, max: 200, ancho: 'completo', placeholder: 'Ej: Mouse inalámbrico negro Logitech' },
            { name: 'laboratorio_id', label: 'Laboratorio', tipo: 'select', opciones: 'laboratorios', requerido: true },
            { name: 'fecha_hallazgo', label: 'Fecha de hallazgo', tipo: 'date', requerido: true, noFuturo: true },
            {
                name: 'foto', label: 'Foto del objeto (opcional)', tipo: 'imagen', ancho: 'completo', actual: (v) => v.imagen_url,
                ayuda: 'Tome o elija una foto (JPG, PNG o WEBP, hasta 4 MB). Si no sube una, se muestra una imagen de referencia según el tipo de objeto.',
            },
        ]}
    />
);

// ----------------------------------------------------------------- Turnos
export const Turnos = () => (
    <CrudPage
        titulo="Turnos de auxiliares"
        descripcion="Turnos fijos de 4 horas de lunes a viernes y rol rotativo de sábados (semestre activo). Se definen al inicio del semestre y no se modifican desde el sistema; los cambios puntuales se hacen con permutas."
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

/** CU10: estado de seguridad de una cuenta (bloqueo por intentos fallidos y sesiones abiertas) */
function BotonSeguridad({ fila }) {
    const { puede, usuario } = useAuth();
    const notificar = useToast();
    const [info, setInfo] = useState(null);
    const [abierto, setAbierto] = useState(false);
    if (!puede('usuarios.editar') || fila.deleted_at || fila.estado === 'PENDIENTE') return null;

    const cargar = async () => {
        try {
            setInfo((await api.get(`/usuarios/${fila.id}/seguridad`)).data);
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };
    const accion = async (url) => {
        try {
            notificar((await api.put(url)).data.message);
            cargar();
        } catch (e) {
            notificar(mensajeError(e), 'error');
        }
    };

    return (
        <>
            <button className="btn btn-fantasma btn-sm" title="Seguridad de la cuenta" aria-label={`Seguridad de ${fila.nombre_completo}`} onClick={() => { setInfo(null); setAbierto(true); cargar(); }}>
                <ShieldAlert className="size-4" aria-hidden />
            </button>
            <Modal abierto={abierto} titulo={`Seguridad: ${fila.nombre_completo}`} onCerrar={() => setAbierto(false)}>
                {!info ? <p className="text-suave">Cargando…</p> : (
                    <div className="space-y-4">
                        <div className={`rounded-tema border p-3 text-sm ${info.bloqueado ? 'border-peligro bg-peligro-tenue text-peligro' : 'border-borde'}`}>
                            {info.bloqueado
                                ? <><strong>Cuenta bloqueada</strong> por intentos fallidos. Se desbloquea sola en {info.minutos_restantes} min.</>
                                : <>Sin bloqueo. Intentos fallidos recientes: {info.intentos_fallidos}.</>}
                            <span className="block text-xs text-suave">Último acceso: {info.ultimo_acceso ? fechaHora(info.ultimo_acceso) : 'nunca'}</span>
                        </div>
                        <div>
                            <p className="etiqueta">Sesiones abiertas ({info.sesiones.length})</p>
                            {info.sesiones.length ? (
                                <ul className="text-sm">{info.sesiones.map((x, i) => <li key={i}>{x.dispositivo === 'spa' ? 'Sesión anterior' : x.dispositivo} <span className="text-xs text-suave">· último uso {x.ultimo_uso ? fechaHora(x.ultimo_uso) : '—'}</span></li>)}</ul>
                            ) : <p className="text-sm text-suave">Ninguna.</p>}
                        </div>
                        <div className="flex flex-wrap justify-end gap-2">
                            {info.bloqueado && <button className="btn btn-secundario" onClick={() => accion(`/usuarios/${fila.id}/desbloquear`)}><Unlock className="size-4" aria-hidden /> Desbloquear</button>}
                            {info.sesiones.length > 0 && fila.id !== usuario.id && (
                                <button className="btn btn-peligro" onClick={() => accion(`/usuarios/${fila.id}/cerrar-sesiones`)}><LogOut className="size-4" aria-hidden /> Cerrar todas sus sesiones</button>
                            )}
                        </div>
                    </div>
                )}
            </Modal>
        </>
    );
}

export const Usuarios = () => {
    const { avisos } = useAuth();
    const solicitudes = avisos['/usuarios'] ?? 0;
    return (
    <CrudPage
        titulo="Usuarios"
        descripcion="Cuentas del sistema y el rol que cumple cada una."
        endpoint="usuarios"
        singular="usuario"
        aviso={solicitudes > 0 ? { texto: `${solicitudes} estudiante(s) solicitaron una cuenta y esperan su aprobación.`, filtro: { estado: 'PENDIENTE' } } : null}
        resaltar={(f) => (f.estado === 'PENDIENTE' && !f.deleted_at ? 'Solicitud por aprobar' : null)}
        permisos={permisosDe('usuarios')}
        inicial={{ estado: 'ACTIVO' }}
        filtros={[
            { name: 'estado', label: 'Estado', opciones: ['PENDIENTE', 'ACTIVO', 'INACTIVO'] },
            { name: 'eliminados', label: 'Registros', textoTodos: 'Usuarios vigentes', opciones: [{ value: 1, label: 'Usuarios eliminados' }] },
        ]}
        acciones={(fila, recargar) => <><AccionesSolicitud fila={fila} recargar={recargar} /><BotonSeguridad fila={fila} /><BotonRestaurarUsuario fila={fila} recargar={recargar} /></>}
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
            // CU1: un usuario nuevo lleva un código de 3 a 10 dígitos. Al editar se respeta el código que ya tenía
            { name: 'codigo', label: 'Código / Registro', requerido: true, soloCrear: true, patron: /^\d{3,10}$/, mensajePatron: 'Solo números, entre 3 y 10 dígitos.', inputMode: 'numeric', max: 10 },
            { name: 'codigo', label: 'Código / Registro', requerido: true, soloEditar: true, patron: /^[A-Za-z0-9-]{1,20}$/, mensajePatron: 'Hasta 20 letras, números o guiones.', ayuda: 'Si lo cambia, el nuevo código debe tener solo números (3 a 10 dígitos).' },
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
};

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

