<?php

namespace Database\Seeders;

use App\Services\Disponibilidad;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Datos de demostración realistas (sin Faker, para que funcione también en el servidor).
 * Contraseña de todos los usuarios de prueba: Ficct2026
 */
class DatosSeeder extends Seeder
{
    private array $u = [];    // email => id
    private array $labs = []; // codigo => id

    public function run(): void
    {
        mt_srand(2026);
        $this->usuarios();
        $this->laboratorios();
        $this->semestres();
        $this->horarios();
        $this->reservas();
        $this->inventario();
        $this->auxiliares();
        $this->pagos();
        $this->bitacora();
    }

    private function ahora(): array
    {
        return ['created_at' => now(), 'updated_at' => now()];
    }

    private function usuarios(): void
    {
        $roles = DB::table('roles')->pluck('id', 'codigo');
        $pass = Hash::make('Ficct2026');
        $lista = [
            ['ENC-001', 'Marco Antonio', 'Rivero Paz', 'encargado@ficct.edu.bo', '70000002', 'ENC'],
            ['DIR-001', 'Silvia', 'Montaño Rojas', 'director@ficct.edu.bo', '70000003', 'DIR'],
            ['DOC-001', 'Carla', 'Vaca Justiniano', 'docente@ficct.edu.bo', '71000001', 'DOC'],
            ['DOC-002', 'Jorge Luis', 'Suárez Peña', 'jsuarez@ficct.edu.bo', '71000002', 'DOC'],
            ['DOC-003', 'Patricia', 'Añez Céspedes', 'panez@ficct.edu.bo', '71000003', 'DOC'],
            ['DOC-004', 'Ricardo', 'Mendoza Arteaga', 'rmendoza@ficct.edu.bo', '71000004', 'DOC'],
            ['DOC-005', 'Lourdes', 'Gutiérrez Saavedra', 'lgutierrez@ficct.edu.bo', '71000005', 'DOC'],
            ['DOC-006', 'Fernando', 'Ribera Ortiz', 'fribera@ficct.edu.bo', '71000006', 'DOC'],
            ['DOC-007', 'Mónica', 'Salvatierra Lima', 'msalvatierra@ficct.edu.bo', '71000007', 'DOC'],
            ['DOC-008', 'Hugo', 'Parada Chávez', 'hparada@ficct.edu.bo', '71000008', 'DOC'],
            ['220000001', 'Daniel', 'Rojas Cuéllar', 'auxiliar@ficct.edu.bo', '72000001', 'AUX'],
            ['220000002', 'Valeria', 'Flores Zabala', 'vflores@ficct.edu.bo', '72000002', 'AUX'],
            ['220000003', 'Andrés', 'Quiroga Melgar', 'aquiroga@ficct.edu.bo', '72000003', 'AUX'],
            ['220000004', 'Camila', 'Vargas Antelo', 'cvargas@ficct.edu.bo', '72000004', 'AUX'],
            ['220000005', 'Luis Fernando', 'Torrez Durán', 'ltorrez@ficct.edu.bo', '72000005', 'AUX'],
            ['220000006', 'Gabriela', 'Moreno Égüez', 'gmoreno@ficct.edu.bo', '72000006', 'AUX'],
            ['DOC-009', 'Ernesto', 'Claure Rivas', 'eclaure@ficct.edu.bo', '71000009', 'DOC'],
        ];
        foreach ($lista as $i => [$codigo, $nombre, $apellido, $email, $tel, $rol]) {
            $this->u[$email] = DB::table('usuarios')->insertGetId([
                'codigo' => $codigo, 'nombre' => $nombre, 'apellido' => $apellido, 'email' => $email,
                'telefono' => $tel, 'password' => $pass, 'rol_id' => $roles[$rol],
                'estado' => $email === 'eclaure@ficct.edu.bo' ? 'INACTIVO' : 'ACTIVO',
                'ultimo_acceso' => now()->subDays(mt_rand(0, 10)),
                'created_at' => now()->subDays(90 - $i), 'updated_at' => now(),
            ]);
        }
    }

    private function laboratorios(): void
    {
        $lista = [
            ['LAB-41', 'Laboratorio 41 - Desarrollo', 40, 'Módulo 236, piso 4', 'DISPONIBLE', 150],
            ['LAB-42', 'Laboratorio 42 - Desarrollo', 40, 'Módulo 236, piso 4', 'DISPONIBLE', 150],
            ['LAB-43', 'Laboratorio 43 - Base de Datos', 35, 'Módulo 236, piso 4', 'DISPONIBLE', 150],
            ['LAB-44', 'Laboratorio 44 - Redes', 30, 'Módulo 236, piso 4', 'DISPONIBLE', 200],
            ['LAB-45', 'Laboratorio 45 - Multimedia', 30, 'Módulo 236, piso 5', 'DISPONIBLE', 180],
            ['LAB-46', 'Laboratorio 46 - Sistemas', 35, 'Módulo 236, piso 5', 'MANTENIMIENTO', 150],
            ['LAB-RED', 'Laboratorio de Redes Cisco', 25, 'Módulo 236, planta baja', 'DISPONIBLE', 250],
        ];
        foreach ($lista as [$codigo, $nombre, $cap, $ubic, $estado]) {
            $this->labs[$codigo] = DB::table('laboratorios')->insertGetId([
                'codigo' => $codigo, 'nombre' => $nombre, 'capacidad' => $cap, 'ubicacion' => $ubic, 'estado' => $estado,
            ] + $this->ahora());
        }
    }

    private function semestres(): void
    {
        DB::table('semestres')->insert(['gestion' => '1-2026', 'fecha_inicio' => '2026-02-09', 'fecha_fin' => '2026-07-11', 'estado' => 'CERRADO'] + $this->ahora());
        $id = DB::table('semestres')->insertGetId(['gestion' => '2-2026', 'fecha_inicio' => '2026-08-03', 'fecha_fin' => '2026-12-19', 'estado' => 'ACTIVO'] + $this->ahora());
        DB::table('semestres')->insert(['gestion' => '1-2027', 'fecha_inicio' => '2027-02-08', 'fecha_fin' => '2027-07-10', 'estado' => 'PLANIFICADO'] + $this->ahora());

        $bloqueos = [
            ['2026-08-06', 'FERIADO', 'Día de la Patria', null],
            ['2026-09-24', 'FERIADO', 'Aniversario de Santa Cruz', null],
            ['2026-11-02', 'FERIADO', 'Día de Todos los Difuntos', null],
            ['2026-12-05', 'PSA', 'Prueba de Suficiencia Académica - todos los ambientes', null],
            ['2026-11-26', 'MANTENIMIENTO', 'Cambio de cableado estructurado', 'LAB-44'],
            ['2026-12-12', 'OTRO', 'Feria de proyectos de la facultad', 'LAB-45'],
        ];
        foreach ($bloqueos as [$fecha, $tipo, $motivo, $lab]) {
            DB::table('fechas_bloqueadas')->insert(['semestre_id' => $id, 'fecha' => $fecha, 'tipo' => $tipo,
                'motivo' => $motivo, 'laboratorio_id' => $lab ? $this->labs[$lab] : null] + $this->ahora());
        }
    }

    private function horarios(): void
    {
        $materias = [
            ['INF110', 'Introducción a la Informática'], ['INF120', 'Programación I'], ['INF210', 'Programación II'],
            ['INF220', 'Estructura de Datos I'], ['INF310', 'Estructura de Datos II'], ['INF312', 'Base de Datos I'],
            ['INF322', 'Base de Datos II'], ['INF342', 'Sistemas de Información I'], ['INF412', 'Sistemas de Información II'],
            ['INF423', 'Redes I'], ['INF433', 'Redes II'], ['INF513', 'Tecnología Web'], ['INF418', 'Ingeniería de Software II'],
            ['INF552', 'Arquitectura del Software'], ['ELC102', 'Programación Gráfica'],
        ];
        $mat = [];
        foreach ($materias as [$sigla, $nombre]) {
            $mat[$sigla] = DB::table('materias')->insertGetId(['sigla' => $sigla, 'nombre' => $nombre] + $this->ahora());
        }

        $semestre = DB::table('semestres')->where('estado', 'ACTIVO')->value('id');
        $docentes = DB::table('usuarios')->join('roles', 'roles.id', '=', 'usuarios.rol_id')
            ->where('roles.codigo', 'DOC')->where('usuarios.estado', 'ACTIVO')->pluck('usuarios.id')->all();
        $franjas = [['07:00', '08:30'], ['08:30', '10:00'], ['10:00', '11:30'], ['11:30', '13:00'], ['14:00', '15:30'],
            ['15:30', '17:00'], ['17:00', '18:30'], ['18:30', '20:00'], ['20:00', '21:30']];
        $labs = array_values(array_diff_key($this->labs, ['LAB-46' => 0]));
        $ocupado = [];
        $siglas = array_keys($mat);
        $grupos = ['SA', 'SB', 'SC', 'SD', 'SE', 'SF'];

        for ($n = 0; $n < 70; $n++) {
            $dia = mt_rand(1, 6);
            $f = $franjas[mt_rand(0, $dia === 6 ? 3 : 8)];
            $lab = $labs[mt_rand(0, count($labs) - 1)];
            $doc = $docentes[mt_rand(0, count($docentes) - 1)];
            if (isset($ocupado["L$lab-$dia-$f[0]"]) || isset($ocupado["D$doc-$dia-$f[0]"])) {
                continue;
            }
            $ocupado["L$lab-$dia-$f[0]"] = $ocupado["D$doc-$dia-$f[0]"] = true;
            $sigla = $siglas[mt_rand(0, count($siglas) - 1)];
            if (in_array($sigla, ['INF423', 'INF433'])) {
                $lab = $this->labs['LAB-RED'];
                if (isset($ocupado["L$lab-$dia-$f[0]"])) {
                    continue;
                }
                $ocupado["L$lab-$dia-$f[0]"] = true;
            }
            DB::table('horarios')->insert([
                'semestre_id' => $semestre, 'laboratorio_id' => $lab, 'materia_id' => $mat[$sigla], 'docente_id' => $doc,
                'grupo' => $grupos[mt_rand(0, 5)], 'dia_semana' => $dia, 'hora_inicio' => $f[0], 'hora_fin' => $f[1],
            ] + $this->ahora());
        }
    }

    private function reservas(): void
    {
        $solicitantes = array_values(array_intersect_key($this->u, array_flip([
            'docente@ficct.edu.bo', 'jsuarez@ficct.edu.bo', 'panez@ficct.edu.bo', 'rmendoza@ficct.edu.bo',
            'director@ficct.edu.bo', 'auxiliar@ficct.edu.bo', 'lgutierrez@ficct.edu.bo',
        ])));
        $motivos = [
            'EXAMEN' => ['Primer parcial', 'Segundo parcial', 'Examen final', 'Examen de mesa', 'Segunda instancia'],
            'PRACTICA' => ['Práctica de laboratorio adicional', 'Taller de Docker', 'Práctica de SQL avanzado', 'Taller de Git y GitHub'],
            'CAPACITACION' => ['Curso de Laravel - Sociedad Científica', 'Capacitación en ciberseguridad'],
            'CONFERENCIA' => ['Charla de ciberseguridad', 'Conferencia de inteligencia artificial'],
            'HACKATHON' => ['Hackathon FICCT', 'Olimpiada de programación'],
        ];
        $siglas = DB::table('materias')->pluck('sigla')->all();
        $labs = array_values(array_diff_key($this->labs, ['LAB-46' => 0]));
        $encargado = $this->u['encargado@ficct.edu.bo'];
        $franjas = [['08:00', '10:00'], ['10:00', '12:00'], ['14:00', '16:00'], ['16:00', '18:00'], ['18:30', '20:30'], ['07:00', '09:00']];

        for ($i = 0; $i < 70; $i++) {
            $fecha = Carbon::today()->addDays(mt_rand(-75, 18));
            if ($fecha->isSunday() || $fecha->lt(Carbon::parse('2026-08-03'))) {
                continue;
            }
            $tipo = array_rand($motivos);
            $motivo = $motivos[$tipo][array_rand($motivos[$tipo])].(in_array($tipo, ['EXAMEN', 'PRACTICA'], true) ? ' '.$siglas[array_rand($siglas)] : '');
            [$hi, $hf] = $franjas[mt_rand(0, 5)];
            $lab = $labs[mt_rand(0, count($labs) - 1)];
            $libre = empty(Disponibilidad::conflictos($lab, $fecha->toDateString(), $hi, $hf));
            $futura = $fecha->gte(Carbon::today());

            $estado = match (true) {
                ! $libre => 'RECHAZADA',
                $futura && mt_rand(1, 10) <= 6 => 'PENDIENTE',
                mt_rand(1, 10) <= 1 => 'CANCELADA',
                default => 'APROBADA',
            };
            DB::table('reservas')->insert([
                'laboratorio_id' => $lab, 'solicitante_id' => $solicitantes[array_rand($solicitantes)],
                'fecha' => $fecha->toDateString(), 'hora_inicio' => $hi, 'hora_fin' => $hf, 'tipo' => $tipo,
                'motivo' => $motivo, 'estado' => $estado,
                'resuelto_por' => in_array($estado, ['APROBADA', 'RECHAZADA']) ? $encargado : null,
                'observacion' => $estado === 'RECHAZADA' ? 'Choque con clase o reserva existente; use otro laboratorio.' : null,
                'created_at' => $fecha->copy()->subDays(mt_rand(2, 7)), 'updated_at' => now(),
            ]);
        }
    }

    private function inventario(): void
    {
        $equipos = [];
        foreach ($this->labs as $codigo => $lab) {
            $num = substr($codigo, 4);
            for ($i = 1; $i <= 6; $i++) {
                $equipos[] = [$lab, "PC-$num-".str_pad($i, 2, '0', STR_PAD_LEFT), 'COMPUTADORA', 'Core i5 12va gen, 16GB RAM, SSD 512GB'];
            }
            $equipos[] = [$lab, "PRY-$num", 'PROYECTOR', 'Proyector Epson PowerLite 3LCD'];
            $equipos[] = [$lab, "SW-$num-VSC", 'SOFTWARE', 'Visual Studio Code + extensiones'];
            $equipos[] = [$lab, "SW-$num-PG", 'SOFTWARE', 'PostgreSQL 16 + pgAdmin 4'];
        }
        $equipos[] = [$this->labs['LAB-RED'], 'SWT-RED-01', 'COMPONENTE', 'Switch Cisco Catalyst 2960 24 puertos'];
        $equipos[] = [$this->labs['LAB-RED'], 'RTR-RED-01', 'COMPONENTE', 'Router Cisco ISR 4331'];
        $ids = [];
        foreach ($equipos as [$lab, $codigo, $tipo, $desc]) {
            $ids[] = DB::table('equipos')->insertGetId(['codigo' => $codigo, 'laboratorio_id' => $lab, 'tipo' => $tipo,
                'descripcion' => $desc, 'estado' => 'OPERATIVO'] + $this->ahora());
        }
        DB::table('equipos')->where('codigo', 'PC-46-06')->update(['estado' => 'BAJA']);

        $fallas = ['No enciende el monitor', 'El teclado tiene teclas que no responden', 'No conecta a la red de la facultad',
            'Pantalla azul al iniciar Windows', 'El proyector muestra colores distorsionados', 'Licencia del software vencida',
            'El mouse no funciona', 'Se reinicia solo después de unos minutos', 'Ventilador hace mucho ruido',
            'No reconoce memorias USB', 'pgAdmin no inicia el servicio', 'Puerto HDMI dañado'];
        $reportantes = [$this->u['auxiliar@ficct.edu.bo'], $this->u['vflores@ficct.edu.bo'], $this->u['docente@ficct.edu.bo'], $this->u['jsuarez@ficct.edu.bo']];
        foreach ($fallas as $i => $falla) {
            $equipo = $ids[($i * 7 + 3) % count($ids)];
            if (DB::table('equipos')->where('id', $equipo)->value('estado') === 'BAJA') {
                continue;
            }
            $fecha = now()->subDays(mt_rand(1, 40));
            // El trigger tg_incidencia_equipo pone el equipo EN_REPARACION
            $id = DB::table('incidencias')->insertGetId(['equipo_id' => $equipo, 'reportado_por' => $reportantes[$i % 4],
                'descripcion' => $falla.'. Reportado durante la clase.', 'estado' => 'PENDIENTE',
                'created_at' => $fecha, 'updated_at' => $fecha]);
            if ($i % 3 === 0) {
                DB::table('incidencias')->where('id', $id)->update(['estado' => 'EN_ATENCION', 'updated_at' => $fecha->copy()->addDay()]);
            } elseif ($i % 3 === 1) { // el trigger devuelve el equipo a OPERATIVO
                DB::table('incidencias')->where('id', $id)->update(['estado' => 'RESUELTA', 'solucion' => 'Se reemplazó la pieza / se reinstaló el software.',
                    'fecha_solucion' => $fecha->copy()->addDays(2), 'updated_at' => $fecha->copy()->addDays(2)]);
            }
        }

        $objetos = ['Celular Samsung Galaxy A54 negro', 'Memoria USB Kingston 32GB', 'Chaqueta azul talla M', 'Cargador de laptop Lenovo',
            'Calculadora Casio fx-991', 'Billetera café con carnet universitario', 'Audífonos inalámbricos blancos', 'Cuaderno de Base de Datos II',
            'Botella térmica verde', 'Lentes con estuche negro'];
        $auxes = [$this->u['auxiliar@ficct.edu.bo'], $this->u['vflores@ficct.edu.bo'], $this->u['aquiroga@ficct.edu.bo']];
        $labs = array_values($this->labs);
        $receptores = [['María José Céspedes', '8945123'], ['Juan Pablo Arias', '7712456-1B'], ['Ana Lucía Roca', '9123478'], ['Diego Salazar', '6789012']];
        foreach ($objetos as $i => $desc) {
            $fecha = Carbon::today()->subDays(mt_rand(0, 45));
            $entregado = $i < 4;
            DB::table('objetos_encontrados')->insert([
                'laboratorio_id' => $labs[$i % count($labs)], 'descripcion' => $desc, 'imagen' => \App\Support\ImagenObjeto::sugerir($desc), 'fecha_hallazgo' => $fecha->toDateString(),
                'registrado_por' => $auxes[$i % 3], 'estado' => $entregado ? 'ENTREGADO' : 'EN_CUSTODIA',
                'entregado_a' => $entregado ? $receptores[$i][0] : null, 'ci_receptor' => $entregado ? $receptores[$i][1] : null,
                'fecha_entrega' => $entregado ? $fecha->copy()->addDays(2)->setTime(10, 30) : null,
                'created_at' => $fecha, 'updated_at' => $fecha,
            ]);
        }
    }

    private function auxiliares(): void
    {
        $semestre = DB::table('semestres')->where('estado', 'ACTIVO')->value('id');
        $auxes = ['auxiliar@ficct.edu.bo', 'vflores@ficct.edu.bo', 'aquiroga@ficct.edu.bo', 'cvargas@ficct.edu.bo', 'ltorrez@ficct.edu.bo', 'gmoreno@ficct.edu.bo'];
        $turnosBase = [['07:00', '11:00'], ['11:00', '15:00'], ['15:00', '19:00'], ['18:00', '22:00'], ['07:00', '11:00'], ['15:00', '19:00']];
        $labs = array_values($this->labs);
        $turnos = [];
        foreach ($auxes as $i => $email) {
            for ($dia = 1; $dia <= 5; $dia++) {
                $turnos[] = DB::table('turnos')->insertGetId(['semestre_id' => $semestre, 'auxiliar_id' => $this->u[$email],
                    'laboratorio_id' => $labs[$i % count($labs)], 'dia_semana' => $dia,
                    'hora_inicio' => $turnosBase[$i][0], 'hora_fin' => $turnosBase[$i][1]] + $this->ahora());
            }
            if ($i < 3) { // rol de sábados (rotativo)
                $turnos[] = DB::table('turnos')->insertGetId(['semestre_id' => $semestre, 'auxiliar_id' => $this->u[$email],
                    'laboratorio_id' => $labs[$i], 'dia_semana' => 6, 'hora_inicio' => $i === 0 ? '07:00' : ($i === 1 ? '09:00' : '11:00'),
                    'hora_fin' => $i === 0 ? '11:00' : ($i === 1 ? '13:00' : '13:00')] + $this->ahora());
            }
        }

        // Asistencia de las últimas 3 semanas
        for ($d = 21; $d >= 1; $d--) {
            $fecha = Carbon::today()->subDays($d);
            if ($fecha->isSunday()) {
                continue;
            }
            foreach (DB::table('turnos')->where('dia_semana', $fecha->dayOfWeekIso)->get() as $t) {
                $r = mt_rand(1, 100);
                $inicio = Carbon::parse($fecha->toDateString().' '.$t->hora_inicio);
                [$estado, $entrada] = match (true) {
                    $r <= 75 => ['PUNTUAL', $inicio->copy()->subMinutes(mt_rand(0, 10))->addMinutes(mt_rand(0, 9))],
                    $r <= 90 => ['ATRASO', $inicio->copy()->addMinutes(mt_rand(11, 40))],
                    $r <= 95 => ['PERMISO', null],
                    default => ['FALTA', null],
                };
                DB::table('asistencias')->insert([
                    'turno_id' => $t->id, 'auxiliar_id' => $t->auxiliar_id, 'fecha' => $fecha->toDateString(),
                    'hora_entrada' => $entrada?->format('H:i:s'),
                    'hora_salida' => $entrada ? Carbon::parse($fecha->toDateString().' '.$t->hora_fin)->addMinutes(mt_rand(-5, 5))->format('H:i:s') : null,
                    'estado' => $estado,
                    'observacion' => $estado === 'PERMISO' ? 'Examen parcial en su carrera' : ($estado === 'FALTA' ? 'Sin registro de entrada' : null),
                ] + $this->ahora());
            }
        }
    }

    /** Catálogo de cobros (se usa también con los datos reales importados) */
    public static function conceptosDePago(): array
    {
        $conceptos = [
            ['CERT-CURSO', 'Certificado de curso de capacitación', 80],
            ['CURSO-LARAVEL', 'Curso de Laravel + React (Sociedad Científica)', 450],
            ['REPO-CRED', 'Reposición de credencial de acceso', 25],
        ];
        $c = [];
        foreach ($conceptos as [$codigo, $nombre, $monto]) {
            $c[$codigo] = DB::table('conceptos_pago')->insertGetId(['codigo' => $codigo, 'nombre' => $nombre, 'monto' => $monto, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    }

    private function pagos(): void
    {
        $c = self::conceptosDePago();

        $planes = [
            ['docente@ficct.edu.bo', 'CURSO-LARAVEL', 'Curso de Laravel + React (cuotas)', 600, 3, 2],
            ['director@ficct.edu.bo', 'CERT-CURSO', 'Certificados del curso de Docker (15)', 1200, 4, 1],
            ['auxiliar@ficct.edu.bo', 'CURSO-LARAVEL', 'Curso de Laravel + React - cohorte noviembre', 450, 3, 3],
            ['jsuarez@ficct.edu.bo', 'REPO-CRED', 'Reposición de credencial', 25, 1, 0],
            ['panez@ficct.edu.bo', 'CERT-CURSO', 'Certificados del curso de Docker (2)', 160, 2, 1],
            ['vflores@ficct.edu.bo', 'REPO-CRED', 'Reposición de credencial', 25, 1, 0],
        ];
        $trx = 0;
        foreach ($planes as [$email, $con, $desc, $total, $nc, $pagadas]) {
            $creado = now()->subDays(mt_rand(30, 60));
            $plan = DB::table('planes_pago')->insertGetId(['usuario_id' => $this->u[$email], 'concepto_id' => $c[$con], 'descripcion' => $desc,
                'monto_total' => $total, 'nro_cuotas' => $nc, 'estado' => 'VIGENTE', 'creado_por' => $this->u['encargado@ficct.edu.bo'],
                'created_at' => $creado, 'updated_at' => $creado]);
            $monto = round($total / $nc, 2);
            // Primero se crean TODAS las cuotas; recién después se pagan (si no, el trigger
            // tg_cuota_cierra_plan cerraría el plan antes de que existan las cuotas siguientes)
            $cuotas = [];
            for ($k = 1; $k <= $nc; $k++) {
                $vence = $creado->copy()->addDays(10)->addMonthsNoOverflow($k - 1);
                $cuotas[$k] = [$vence, DB::table('cuotas')->insertGetId(['plan_id' => $plan, 'numero' => $k,
                    'monto' => $k < $nc ? $monto : round($total - $monto * ($nc - 1), 2),
                    'fecha_vencimiento' => $vence->toDateString(), 'estado' => 'PENDIENTE', 'pagado_en' => null] + $this->ahora())];
            }
            foreach ($cuotas as $k => [$vence, $cuota]) {
                if ($k <= $pagadas) {
                    $fechaPago = $vence->copy()->subDays(mt_rand(0, 5))->setTime(mt_rand(8, 20), mt_rand(0, 59));
                    $trx++;
                    DB::table('transacciones')->insert(['codigo' => 'TRX-'.str_pad($trx, 6, '0', STR_PAD_LEFT), 'usuario_id' => $this->u[$email],
                        'pasarela' => 'stripe', 'referencia_externa' => 'cs_test_demo'.$trx, 'cuota_id' => $cuota, 'descripcion' => "Cuota $k/$nc - $desc",
                        'monto' => DB::table('cuotas')->where('id', $cuota)->value('monto'), 'estado' => 'APROBADA',
                        'autorizacion' => 'pi_demo'.$trx, 'mensaje' => 'Pago con tarjeta confirmado por Stripe.',
                        'created_at' => $fechaPago, 'updated_at' => $fechaPago]);
                    // el trigger tg_cuota_cierra_plan marca el plan PAGADO al pagar la última
                    DB::table('cuotas')->where('id', $cuota)->update(['estado' => 'PAGADA', 'pagado_en' => $fechaPago]);
                }
            }
        }

        // Pagos únicos repartidos en el último mes (para el gráfico de ingresos)
        $unicos = [['CERT-CURSO', 'panez@ficct.edu.bo'], ['REPO-CRED', 'auxiliar@ficct.edu.bo'], ['REPO-CRED', 'docente@ficct.edu.bo'],
            ['CURSO-LARAVEL', 'director@ficct.edu.bo'], ['CERT-CURSO', 'jsuarez@ficct.edu.bo'], ['CURSO-LARAVEL', 'panez@ficct.edu.bo']];
        for ($i = 0; $i < 16; $i++) {
            [$con, $email] = $unicos[$i % count($unicos)];
            $fecha = now()->subDays(mt_rand(0, 29))->setTime(mt_rand(8, 21), mt_rand(0, 59));
            $rechazo = $i % 7 === 3;
            $trx++;
            $concepto = DB::table('conceptos_pago')->find($c[$con]);
            DB::table('transacciones')->insert(['codigo' => 'TRX-'.str_pad($trx, 6, '0', STR_PAD_LEFT), 'usuario_id' => $this->u[$email],
                'pasarela' => 'stripe', 'referencia_externa' => 'cs_test_demo'.$trx, 'concepto_id' => $concepto->id, 'descripcion' => $concepto->nombre,
                'monto' => $concepto->monto, 'estado' => $rechazo ? 'EXPIRADA' : 'APROBADA',
                'autorizacion' => $rechazo ? null : 'pi_demo'.$trx, 'mensaje' => $rechazo ? 'La página de pago venció sin recibir el pago.' : 'Pago con tarjeta confirmado por Stripe.',
                'created_at' => $fecha, 'updated_at' => $fecha]);
        }
    }

    /** Historial de accesos de los últimos 30 días para que el dashboard tenga datos */
    private function bitacora(): void
    {
        $usuarios = DB::table('usuarios')->where('estado', 'ACTIVO')->get(['id', 'email']);
        $recursos = ['reservas' => 30, 'disponibilidad' => 22, 'horarios' => 14, 'dashboard' => 12, 'equipos' => 9, 'incidencias' => 8,
            'pagos' => 8, 'laboratorios' => 6, 'objetos' => 5, 'asistencias' => 5, 'usuarios' => 4, 'turnos' => 3, 'me' => 15];
        $bolsa = [];
        foreach ($recursos as $r => $peso) {
            $bolsa = array_merge($bolsa, array_fill(0, $peso, $r));
        }
        $ips = ['181.188.162.24', '181.115.131.9', '200.87.45.112', '190.129.78.3', '10.0.2.15', '186.121.200.47', '161.138.8.19'];
        $agentes = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/132.0', 'Mozilla/5.0 (X11; Linux x86_64) Firefox/134.0',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1) Safari/604.1', 'Mozilla/5.0 (Linux; Android 15) Chrome/131.0 Mobile'];
        $filas = [];
        for ($d = 29; $d >= 0; $d--) {
            $dia = Carbon::today()->subDays($d);
            $n = $dia->isWeekend() ? mt_rand(3, 8) : mt_rand(8, 18);
            for ($i = 0; $i < $n; $i++) {
                $u = $usuarios[mt_rand(0, count($usuarios) - 1)];
                $hora = $dia->copy()->setTime(mt_rand(7, 21), mt_rand(0, 59), mt_rand(0, 59));
                if ($hora->isFuture()) {
                    continue;
                }
                $ip = $ips[mt_rand(0, count($ips) - 1)];
                $ag = $agentes[mt_rand(0, 3)];
                if (mt_rand(1, 6) === 1) {
                    $filas[] = ['usuario_id' => $u->id, 'email' => $u->email, 'evento' => 'LOGIN_FALLIDO', 'metodo' => 'POST', 'recurso' => 'auth',
                        'ruta' => 'api/login', 'ip' => $ip, 'user_agent' => $ag, 'codigo_http' => 422, 'detalle' => 'Contraseña incorrecta', 'created_at' => $hora->copy()->subMinute()];
                }
                $filas[] = ['usuario_id' => $u->id, 'email' => $u->email, 'evento' => 'LOGIN_OK', 'metodo' => 'POST', 'recurso' => 'auth',
                    'ruta' => 'api/login', 'ip' => $ip, 'user_agent' => $ag, 'codigo_http' => 200, 'detalle' => null, 'created_at' => $hora];
                for ($k = 0, $m = mt_rand(3, 12); $k < $m; $k++) {
                    $r = $bolsa[mt_rand(0, count($bolsa) - 1)];
                    $filas[] = ['usuario_id' => $u->id, 'email' => $u->email, 'evento' => 'ACCESO', 'metodo' => mt_rand(1, 5) === 1 ? 'POST' : 'GET',
                        'recurso' => $r, 'ruta' => "api/$r", 'ip' => $ip, 'user_agent' => $ag, 'codigo_http' => 200, 'detalle' => null,
                        'created_at' => $hora->copy()->addMinutes($k * 2 + 1)];
                }
            }
            if (mt_rand(1, 4) === 1) { // intentos con correos inexistentes
                $filas[] = ['usuario_id' => null, 'email' => 'test@gmail.com', 'evento' => 'LOGIN_FALLIDO', 'metodo' => 'POST', 'recurso' => 'auth',
                    'ruta' => 'api/login', 'ip' => '45.227.254.8', 'user_agent' => 'python-requests/2.32', 'codigo_http' => 422,
                    'detalle' => 'Usuario no registrado', 'created_at' => $dia->copy()->setTime(3, mt_rand(0, 59))];
            }
        }
        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('bitacora')->insert($lote);
        }

        $paginas = ['/login' => 420, '/' => 380, '/reservas' => 310, '/disponibilidad' => 260, '/dashboard' => 190, '/horarios' => 150,
            '/equipos' => 95, '/incidencias' => 88, '/pagos' => 76, '/objetos' => 41, '/asistencia' => 64, '/laboratorios' => 58, '/usuarios' => 33];
        foreach ($paginas as $ruta => $n) {
            DB::table('visitas_pagina')->insert(['ruta' => $ruta, 'contador' => $n, 'actualizado_en' => now()]);
        }
    }
}
