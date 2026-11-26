<?php

namespace Database\Seeders;

use App\Support\Encargado;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Roles, permisos (matriz de acceso) y menú dinámico.
 */
class SeguridadSeeder extends Seeder
{
    /** recurso => [accion => descripción] */
    const PERMISOS = [
        'dashboard' => ['ver' => 'Ver el panel de estadísticas'],
        'reportes' => ['ver' => 'Ver y exportar los reportes (ocupación, reservas, incidencias, asistencia, semestre)'],
        'usuarios' => ['ver' => 'Listar usuarios', 'crear' => 'Registrar usuarios', 'editar' => 'Modificar usuarios', 'eliminar' => 'Eliminar usuarios'],
        'roles' => ['ver' => 'Ver roles y matriz de acceso', 'editar' => 'Crear/editar roles y asignar permisos'],
        'bitacora' => ['ver' => 'Ver bitácora de accesos'],
        'auditoria' => ['ver' => 'Ver auditoría de cambios (triggers)', 'restaurar' => 'Restaurar registros eliminados'],
        'laboratorios' => ['ver' => 'Listar laboratorios', 'crear' => 'Registrar laboratorios', 'editar' => 'Modificar laboratorios', 'eliminar' => 'Eliminar laboratorios'],
        'semestres' => ['ver' => 'Ver semestres y fechas bloqueadas', 'crear' => 'Registrar semestres y fechas bloqueadas', 'editar' => 'Modificar/activar/cerrar semestres', 'eliminar' => 'Eliminar semestres y fechas bloqueadas'],
        'horarios' => ['ver' => 'Ver horario base y materias', 'crear' => 'Registrar clases y materias', 'editar' => 'Modificar clases y materias', 'eliminar' => 'Eliminar clases y materias'],
        'disponibilidad' => ['ver' => 'Consultar disponibilidad de laboratorios'],
        'reservas' => ['ver' => 'Ver sus reservas', 'ver_todas' => 'Ver reservas de todos', 'crear' => 'Solicitar reservas', 'aprobar' => 'Aprobar o rechazar reservas', 'cancelar' => 'Cancelar reservas', 'registrar_uso' => 'Registrar si una reserva se utilizó o no (genera multa)', 'pedir_auxiliares' => 'Pedir auxiliares de apoyo al solicitar una reserva'],
        'equipos' => ['ver' => 'Ver inventario', 'crear' => 'Registrar equipos', 'editar' => 'Modificar equipos', 'eliminar' => 'Eliminar equipos'],
        'incidencias' => ['ver' => 'Ver incidencias', 'crear' => 'Reportar incidencias', 'gestionar' => 'Atender y resolver incidencias'],
        'objetos' => ['ver' => 'Ver objetos encontrados', 'crear' => 'Registrar objetos encontrados', 'entregar' => 'Registrar entrega de objetos'],
        'turnos' => ['ver' => 'Ver turnos', 'crear' => 'Asignar y modificar turnos'],
        'permutas' => ['ver' => 'Ver sus permutas de turno', 'solicitar' => 'Solicitar y responder permutas de turno', 'aprobar' => 'Aprobar o rechazar permutas y ver las de todos'],
        'mensajes' => ['usar' => 'Enviar y recibir mensajes internos'],
        'asistencia' => ['ver' => 'Ver asistencia', 'marcar' => 'Marcar entrada y salida', 'gestionar' => 'Registrar permisos y faltas, ver toda la asistencia'],
        'pagos' => ['ver' => 'Ver sus pagos y planes', 'pagar' => 'Pagar con tarjeta (Stripe) cuotas, multas y conceptos', 'ver_todos' => 'Ver transacciones de todos'],
        'planes' => ['gestionar' => 'Crear y anular planes de pago'],
        'multas' => ['ver' => 'Ver y pagar sus multas', 'gestionar' => 'Ver las multas de todos y anularlas'],
        'conceptos' => ['crear' => 'Registrar conceptos de cobro', 'editar' => 'Modificar conceptos de cobro', 'eliminar' => 'Eliminar conceptos de cobro'],
    ];

    const ROLES = [
        // El Encargado es el administrador del sistema: rol de sistema (protegido) con TODOS los permisos
        ['ENC', 'Encargado de Laboratorios', Encargado::DESCRIPCION, true],
        ['DOC', 'Docente', 'Solicita reservas, consulta disponibilidad y reporta incidencias', false],
        ['AUX', 'Auxiliar', 'Atiende los laboratorios en su turno, registra asistencia, incidencias y objetos', false],
        ['DIR', 'Director de Carrera', 'Solicita reservas para eventos y consulta estadísticas', false],
        ['EST', 'Estudiante FICCT', 'Estudiante de la FICCT (ej: auxiliar de cátedra) que solicita reservas de laboratorio', false],
    ];

    /** Permisos por rol de negocio (el Encargado recibe TODOS: ver Encargado::asegurarRol) */
    const ROL_PERMISOS = [
        'DOC' => ['laboratorios.ver', 'horarios.ver', 'disponibilidad.ver', 'reservas.ver', 'reservas.crear',
            'reservas.cancelar', 'equipos.ver', 'incidencias.ver', 'incidencias.crear', 'pagos.ver', 'pagos.pagar', 'multas.ver', 'reservas.pedir_auxiliares'],
        'AUX' => ['laboratorios.ver', 'horarios.ver', 'disponibilidad.ver', 'reservas.ver', 'reservas.crear', 'reservas.cancelar',
            'equipos.ver', 'incidencias.ver', 'incidencias.crear', 'objetos.*', 'turnos.ver', 'asistencia.ver',
            'asistencia.marcar', 'pagos.ver', 'pagos.pagar', 'permutas.ver', 'permutas.solicitar', 'mensajes.usar', 'multas.ver', 'reservas.registrar_uso'],
        // Se registra solo desde el login; el Encargado aprueba la cuenta
        'EST' => ['laboratorios.ver', 'disponibilidad.ver', 'reservas.ver', 'reservas.crear', 'reservas.cancelar', 'multas.ver', 'pagos.ver', 'pagos.pagar'],
        'DIR' => ['dashboard.ver', 'reportes.ver', 'laboratorios.ver', 'horarios.ver', 'disponibilidad.ver', 'reservas.ver', 'reservas.ver_todas',
            'reservas.crear', 'reservas.cancelar', 'equipos.ver', 'incidencias.ver', 'pagos.ver', 'pagos.pagar', 'pagos.ver_todos', 'multas.ver', 'reservas.pedir_auxiliares'],
    ];

    /** grupo, título, ruta React, ícono (lucide), permiso requerido */
    const MENU = [
        ['General', 'Inicio', '/', 'Home', null],
        ['General', 'Panel de estadísticas', '/dashboard', 'BarChart3', 'dashboard.ver'],
        ['General', 'Reportes', '/reportes', 'FileSpreadsheet', 'reportes.ver'],
        ['General', 'Mensajes', '/mensajes', 'MessagesSquare', 'mensajes.usar'],
        ['Horarios y reservas', 'Disponibilidad', '/disponibilidad', 'CalendarSearch', 'disponibilidad.ver'],
        ['Horarios y reservas', 'Reservas', '/reservas', 'CalendarCheck', 'reservas.ver'],
        ['Horarios y reservas', 'Horario base', '/horarios', 'CalendarDays', 'horarios.ver'],
        ['Horarios y reservas', 'Materias', '/materias', 'BookOpen', 'horarios.crear'],
        ['Operación', 'Inventario', '/equipos', 'Monitor', 'equipos.ver'],
        ['Operación', 'Incidencias', '/incidencias', 'Wrench', 'incidencias.ver'],
        ['Operación', 'Objetos encontrados', '/objetos', 'PackageSearch', 'objetos.ver'],
        ['Operación', 'Turnos', '/turnos', 'Clock', 'turnos.ver'],
        ['Operación', 'Asistencia', '/asistencia', 'UserCheck', 'asistencia.ver'],
        ['Operación', 'Permutas de turno', '/permutas', 'ArrowLeftRight', 'permutas.ver'],
        ['Pagos', 'Mis pagos', '/pagos', 'Wallet', 'pagos.ver'],
        ['Pagos', 'Multas', '/multas', 'Gavel', 'multas.ver'],
        ['Pagos', 'Planes de pago', '/pagos/planes', 'ListChecks', 'planes.gestionar'],
        ['Pagos', 'Conceptos de cobro', '/pagos/conceptos', 'Tags', 'conceptos.crear'],
        ['Configuración', 'Laboratorios', '/laboratorios', 'Building2', 'laboratorios.ver'],
        ['Configuración', 'Semestres', '/semestres', 'CalendarRange', 'semestres.ver'],
        ['Configuración', 'Fechas bloqueadas', '/fechas-bloqueadas', 'CalendarX', 'semestres.ver'],
        ['Seguridad', 'Usuarios', '/usuarios', 'Users', 'usuarios.ver'],
        ['Seguridad', 'Roles y permisos', '/roles', 'ShieldCheck', 'roles.ver'],
        ['Seguridad', 'Bitácora', '/bitacora', 'ScrollText', 'bitacora.ver'],
        ['Seguridad', 'Auditoría', '/auditoria', 'History', 'auditoria.ver'],
    ];

    public function run(): void
    {
        foreach (self::PERMISOS as $recurso => $acciones) {
            foreach ($acciones as $accion => $desc) {
                DB::table('permisos')->insert(['codigo' => "$recurso.$accion", 'recurso' => $recurso, 'accion' => $accion, 'descripcion' => $desc]);
            }
        }

        foreach (self::ROLES as [$codigo, $nombre, $desc, $sistema]) {
            DB::table('roles')->insert(['codigo' => $codigo, 'nombre' => $nombre, 'descripcion' => $desc,
                'es_sistema' => $sistema, 'created_at' => now(), 'updated_at' => now()]);
        }

        $permisos = DB::table('permisos')->pluck('id', 'codigo');
        $roles = DB::table('roles')->pluck('id', 'codigo');

        Encargado::asegurarRol();
        foreach (self::ROL_PERMISOS as $rol => $lista) {
            foreach ($permisos as $codigo => $id) {
                $recurso = explode('.', $codigo)[0];
                if (in_array($codigo, $lista, true) || in_array("$recurso.*", $lista, true)) {
                    DB::table('rol_permiso')->insert(['rol_id' => $roles[$rol], 'permiso_id' => $id]);
                }
            }
        }

        foreach (self::MENU as $i => [$grupo, $titulo, $ruta, $icono, $permiso]) {
            DB::table('menus')->insert(['grupo' => $grupo, 'titulo' => $titulo, 'ruta' => $ruta, 'icono' => $icono,
                'permiso_codigo' => $permiso, 'orden' => ($i + 1) * 10, 'activo' => true]);
        }
    }
}
