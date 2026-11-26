<?php

namespace Tests\Feature;

use App\Http\Controllers\PagoStripeController;
use App\Models\Laboratorio;
use App\Models\Reserva;
use App\Models\Rol;
use App\Models\Semestre;
use App\Models\Transaccion;
use App\Models\User;
use App\Services\Disponibilidad;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CU4 - Jerarquía de reservas: Hackathon/CUP/PSA > Examen > Capacitación/Conferencia > Clases > Prácticas de auxiliares.
 * También: regla de los 4 días, aprobación automática, auxiliares de apoyo, presencia del chat, código de usuario
 * y pagos sin avisos al Encargado.
 */
class JerarquiaReservasTest extends SistemaTestCase
{
    /** Laboratorio sin nada en esa fecha y franja */
    private function labLibre(string $fecha, string $ini, string $fin): Laboratorio
    {
        return Laboratorio::where('estado', 'DISPONIBLE')->orderBy('codigo')->get()
            ->first(fn ($l) => ! Disponibilidad::conflictos($l->id, $fecha, $ini, $fin));
    }

    private function clase(Laboratorio $lab, string $fecha, string $ini, string $fin): int
    {
        return DB::table('horarios')->insertGetId([
            'semestre_id' => Semestre::activo()->id, 'laboratorio_id' => $lab->id, 'materia_id' => DB::table('materias')->value('id'),
            'docente_id' => User::where('email', 'docente@ficct.edu.bo')->value('id'),
            'grupo' => 'JJ', 'dia_semana' => Carbon::parse($fecha)->dayOfWeekIso, 'hora_inicio' => $ini, 'hora_fin' => $fin,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reserva(Laboratorio $lab, string $fecha, string $tipo, array $mas = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/reservas', array_merge(['laboratorio_id' => $lab->id, 'fecha' => $fecha, 'hora_inicio' => '21:00',
            'hora_fin' => '22:00', 'tipo' => $tipo, 'motivo' => "Prueba de $tipo"], $mas));
    }

    private function avisosDe(string $email): int
    {
        return DB::table('notificaciones')->where('usuario_id', User::where('email', $email)->value('id'))->count();
    }

    /** Fecha hábil dentro de los próximos 3 días (menos de 4 días de anticipación) sin bloqueos */
    private function fechaCercana(): string
    {
        $d = Carbon::today()->addDay();
        while ($d->isSunday() || DB::table('fechas_bloqueadas')->whereDate('fecha', $d)->exists()) {
            $d->addDay();
        }

        return $d->toDateString();
    }

    public function test_un_examen_ocupa_el_lugar_de_una_clase_y_la_clase_ese_dia_se_suspende(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $this->clase($lab, $fecha, '21:00', '22:00');

        $this->como('jsuarez@ficct.edu.bo');
        $id = $this->reserva($lab, $fecha, 'EXAMEN')->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE')->json('data.id');
        $antes = $this->avisosDe('docente@ficct.edu.bo');

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/aprobar")->assertOk()->assertJsonFragment(['message' => 'Reserva aprobada. Se suspendió 1 clase(s) ese día y se avisó al docente.']);
        $this->assertSame('APROBADA', Reserva::find($id)->estado);
        $this->assertSame($antes + 1, $this->avisosDe('docente@ficct.edu.bo')); // el docente de la clase recibe el aviso

        $fila = collect(Disponibilidad::agenda($fecha))->firstWhere('laboratorio.id', $lab->id);
        $clase = collect($fila['bloques'])->first(fn ($b) => $b['tipo'] === 'CLASE' && $b['inicio'] === '21:00');
        $this->assertTrue($clase['suspendida']);
        $this->assertStringContainsString('Suspendida ese día por Examen', $clase['detalle']);
    }

    public function test_una_practica_no_puede_ocupar_el_lugar_de_una_clase(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $this->clase($lab, $fecha, '21:00', '22:00');

        $this->como('jsuarez@ficct.edu.bo');
        $this->reserva($lab, $fecha, 'PRACTICA')->assertStatus(422)->assertJsonStructure(['alternativas']);
    }

    public function test_sobre_una_clase_se_pide_con_4_dias_pero_si_esta_libre_en_cualquier_momento(): void
    {
        $fecha = $this->fechaCercana();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $this->como('jsuarez@ficct.edu.bo');
        $this->reserva($lab, $fecha, 'EXAMEN')->assertCreated(); // libre: se puede para mañana

        $otro = $this->labLibre($fecha, '21:00', '22:00');
        $this->clase($otro, $fecha, '21:00', '22:00');
        $r = $this->reserva($otro, $fecha, 'EXAMEN')->assertStatus(422);
        $this->assertStringContainsString('al menos 4 días de anticipación', $r->json('message'));
    }

    public function test_un_examen_desplaza_una_conferencia_pero_no_al_reves(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $this->como('encargado@ficct.edu.bo');
        $conf = $this->reserva($lab, $fecha, 'CONFERENCIA')->assertCreated()->assertJsonPath('data.estado', 'APROBADA')->json('data.id');

        // Algo de igual prioridad no entra
        $this->como('jsuarez@ficct.edu.bo');
        $this->reserva($lab, $fecha, 'CAPACITACION')->assertStatus(422);

        // Un examen sí: al aprobarlo se cancela la conferencia con aviso
        $antes = $this->avisosDe('encargado@ficct.edu.bo');
        $ex = $this->reserva($lab, $fecha, 'EXAMEN')->assertCreated()->json('data.id');
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$ex/aprobar")->assertOk();
        $this->assertSame('CANCELADA', Reserva::find($conf)->estado);
        $this->assertStringContainsString('mayor prioridad', Reserva::find($conf)->observacion);
        $this->assertGreaterThan($antes, $this->avisosDe('encargado@ficct.edu.bo'));

        // Y ahora una conferencia ya no puede pisar el examen
        $this->como('jsuarez@ficct.edu.bo');
        $this->reserva($lab, $fecha, 'CONFERENCIA')->assertStatus(422);
    }

    public function test_aprobacion_automatica_para_docentes_y_aviso_al_encargado(): void
    {
        $this->como('docente@ficct.edu.bo');
        $this->getJson('/api/reservas/configuracion')->assertStatus(403); // el interruptor es del Encargado

        $this->como('encargado@ficct.edu.bo');
        $this->putJson('/api/reservas/configuracion', ['aprobacion_automatica' => true])->assertOk()->assertJsonPath('aprobacion_automatica', true);

        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $antes = $this->avisosDe('encargado@ficct.edu.bo');
        $this->como('docente@ficct.edu.bo');
        $this->reserva($lab, $fecha, 'EXAMEN')->assertCreated()->assertJsonPath('data.estado', 'APROBADA');
        $this->assertSame($antes + 1, $this->avisosDe('encargado@ficct.edu.bo'));

        // Un estudiante sigue necesitando aprobación
        $otro = $this->labLibre($fecha, '21:00', '22:00');
        $est = User::whereHas('rol', fn ($q) => $q->where('codigo', 'EST'))->where('estado', 'ACTIVO')->first();
        if ($est) {
            \Laravel\Sanctum\Sanctum::actingAs($est);
            $this->reserva($otro, $fecha, 'PRACTICA')->assertCreated()->assertJsonPath('data.estado', 'PENDIENTE');
        }
    }

    public function test_si_pide_mas_de_5_auxiliares_se_asignan_4_y_el_encargado_asigna_el_resto(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $aux = Rol::where('codigo', 'AUX')->value('id');
        $nuevos = collect(range(1, 6))->map(function ($i) use ($aux, $fecha, $lab) {
            $u = User::forceCreate(['codigo' => "99000$i", 'nombre' => 'Aux', 'apellido' => "Prueba $i", 'email' => "auxprueba$i@ficct.edu.bo",
                'password' => bcrypt(Str::random(12)), 'rol_id' => $aux, 'estado' => 'ACTIVO']);
            DB::table('turnos')->insert(['semestre_id' => Semestre::activo()->id, 'auxiliar_id' => $u->id, 'laboratorio_id' => $lab->id,
                'dia_semana' => Carbon::parse($fecha)->dayOfWeekIso, 'hora_inicio' => '18:00', 'hora_fin' => '22:00', 'created_at' => now(), 'updated_at' => now()]);

            return $u;
        });

        $this->como('docente@ficct.edu.bo');
        $id = $this->reserva($lab, $fecha, 'EXAMEN', ['auxiliares_solicitados' => 7])->assertCreated()->json('data.id');
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/reservas/$id/aprobar")->assertOk()
            ->assertJsonFragment(['message' => 'Reserva aprobada. Se asignaron 4 auxiliar(es) con turno a esa hora. Faltan 3 auxiliar(es): los asigna el Encargado.']);
        $r = Reserva::find($id);
        $this->assertSame(4, $r->auxiliares()->count());
        $this->assertSame(1, Reserva::faltanAuxiliares()->where('id', $id)->count());

        // El auxiliar asignado ve la reserva en su lista y recibió el aviso
        $asignado = $r->auxiliares()->first();
        $this->assertSame(1, DB::table('notificaciones')->where('usuario_id', $asignado->id)->where('reserva_id', $id)->count());
        \Laravel\Sanctum\Sanctum::actingAs($asignado);
        $this->assertContains($id, array_column($this->getJson('/api/reservas')->assertOk()->json('data'), 'id'));

        // El Encargado ve los candidatos con turno y asigna uno a mano
        $this->como('encargado@ficct.edu.bo');
        $libres = $this->getJson("/api/reservas/$id/auxiliares")->assertOk()->json('con_turno');
        $this->assertNotEmpty($libres);
        $this->postJson("/api/reservas/$id/auxiliares", ['auxiliar_id' => $libres[0]['id']])->assertOk();
        $this->assertSame(5, $r->auxiliares()->count());
        $this->assertSame(1, $r->auxiliares()->wherePivotNotNull('asignado_por')->count());
        $this->assertCount(6, $nuevos);
    }

    public function test_hasta_5_auxiliares_se_asignan_todos_los_que_tienen_turno(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $aux = Rol::where('codigo', 'AUX')->value('id');
        foreach (range(1, 3) as $i) {
            $u = User::forceCreate(['codigo' => "98000$i", 'nombre' => 'Aux', 'apellido' => "Turno $i", 'email' => "auxturno$i@ficct.edu.bo",
                'password' => bcrypt(Str::random(12)), 'rol_id' => $aux, 'estado' => 'ACTIVO']);
            DB::table('turnos')->insert(['semestre_id' => Semestre::activo()->id, 'auxiliar_id' => $u->id, 'laboratorio_id' => $lab->id,
                'dia_semana' => Carbon::parse($fecha)->dayOfWeekIso, 'hora_inicio' => '18:00', 'hora_fin' => '22:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->como('encargado@ficct.edu.bo');
        $id = $this->reserva($lab, $fecha, 'EXAMEN', ['auxiliares_solicitados' => 3])->assertCreated()->json('data.id');
        $this->assertSame(3, Reserva::find($id)->auxiliares()->count());
        $this->assertSame(0, Reserva::faltanAuxiliares()->where('id', $id)->count());
    }

    public function test_no_se_pueden_pedir_mas_auxiliares_de_los_disponibles(): void
    {
        $fecha = $this->martesLibre();
        $lab = $this->labLibre($fecha, '21:00', '22:00');
        $this->como('docente@ficct.edu.bo');
        $hay = $this->getJson("/api/reservas/auxiliares-disponibles?fecha=$fecha&hora_inicio=21:00&hora_fin=22:00")->assertOk()->json('disponibles');
        $this->assertSame(\App\Services\AsignadorAuxiliares::disponibles($fecha, '21:00', '22:00'), $hay);

        // Uno con permiso ese día ya no cuenta
        $aux = User::whereHas('rol', fn ($q) => $q->where('codigo', 'AUX'))->where('estado', 'ACTIVO')->first();
        DB::table('permisos_auxiliar')->insert(['auxiliar_id' => $aux->id, 'fecha' => $fecha, 'motivo' => 'Permiso de prueba',
            'registrado_por' => User::where('email', 'encargado@ficct.edu.bo')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame($hay - 1, \App\Services\AsignadorAuxiliares::disponibles($fecha, '21:00', '22:00'));

        $r = $this->reserva($lab, $fecha, 'EXAMEN', ['auxiliares_solicitados' => $hay])->assertStatus(422)
            ->assertJsonValidationErrors(['auxiliares_solicitados']);
        $this->assertStringContainsString('Solo hay '.($hay - 1), $r->json('message'));
        $this->reserva($lab, $fecha, 'EXAMEN', ['auxiliares_solicitados' => $hay - 1])->assertCreated();
    }

    public function test_el_chat_muestra_en_linea_y_ultima_vez(): void
    {
        $aux = User::whereHas('rol', fn ($q) => $q->where('codigo', 'AUX'))->where('estado', 'ACTIVO')->first();
        \Laravel\Sanctum\Sanctum::actingAs($aux);
        $this->getJson('/api/notificaciones')->assertOk(); // cualquier uso del sistema actualiza la presencia
        $this->assertTrue(DB::table('presencia')->where('usuario_id', $aux->id)->exists());

        $this->como('encargado@ficct.edu.bo');
        $c = collect($this->getJson('/api/mensajes/contactos')->assertOk()->json())->firstWhere('id', $aux->id);
        $this->assertTrue($c['en_linea']);
        $this->assertNotNull($c['ultima_vez']);

        DB::table('presencia')->where('usuario_id', $aux->id)->update(['visto_en' => now()->subHour()]);
        $c = collect($this->getJson('/api/mensajes/contactos')->json())->firstWhere('id', $aux->id);
        $this->assertFalse($c['en_linea']);
    }

    public function test_el_codigo_de_un_usuario_nuevo_tiene_de_3_a_10_digitos(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $base = ['nombre' => 'Ana', 'apellido' => 'Prueba', 'email' => 'ana.codigo@ficct.edu.bo', 'rol_id' => Rol::where('codigo', 'DOC')->value('id'),
            'estado' => 'ACTIVO', 'password' => 'Clave1234'];
        foreach (['12', '12345678901', 'AB123'] as $malo) {
            $this->postJson('/api/usuarios', $base + ['codigo' => $malo])->assertStatus(422)
                ->assertJsonPath('errors.codigo.0', 'El código debe tener solo números, entre 3 y 10 dígitos.');
        }
        $this->postJson('/api/usuarios', $base + ['codigo' => '123'])->assertCreated();
    }

    public function test_los_pagos_confirmados_no_notifican_al_encargado(): void
    {
        $doc = User::where('email', 'docente@ficct.edu.bo')->first();
        $trx = Transaccion::forceCreate(['codigo' => 'TRX-PRUEBA-1', 'usuario_id' => $doc->id, 'pasarela' => 'stripe', 'estado' => 'PENDIENTE',
            'monto' => 50, 'descripcion' => 'Certificado de prueba']);
        $antes = $this->avisosDe('encargado@ficct.edu.bo');

        PagoStripeController::confirmar($trx, 'pi_prueba');
        PagoStripeController::confirmar($trx, 'pi_prueba'); // el webhook y la consulta pueden llegar los dos

        $this->assertSame('APROBADA', $trx->fresh()->estado);
        $this->assertSame($antes, $this->avisosDe('encargado@ficct.edu.bo')); // sin avisos por cada pago (evita la fatiga)
    }
}
