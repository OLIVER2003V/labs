<?php

namespace Tests\Feature;

use App\Models\User;

/** CU1 - Registro de estudiantes de la FICCT con aprobación del Encargado */
class RegistroEstudianteTest extends SistemaTestCase
{
    private array $datos = [
        'codigo' => '219000111', 'nombre' => 'Lucía', 'apellido' => 'Paz Rojas', 'email' => 'Lucia.Paz@ficct.edu.bo',
        'telefono' => '76543210', 'carrera' => 'Ingeniería Informática',
        'detalle_registro' => 'Auxiliar de cátedra de INF513 grupo SA, necesito reservar para prácticas.',
        'password' => 'Clave2026', 'password_confirmation' => 'Clave2026',
    ];

    public function test_flujo_completo_registro_pendiente_aprobacion_y_acceso_limitado(): void
    {
        $this->postJson('/api/registro', $this->datos)->assertCreated();
        $u = User::where('email', 'lucia.paz@ficct.edu.bo')->firstOrFail();
        $this->assertSame('PENDIENTE', $u->estado);
        $this->assertSame('EST', $u->rol->codigo);
        $this->assertDatabaseHas('bitacora', ['email' => 'lucia.paz@ficct.edu.bo', 'evento' => 'REGISTRO']);

        // No puede entrar hasta ser aprobado
        $this->postJson('/api/login', ['email' => 'lucia.paz@ficct.edu.bo', 'password' => 'Clave2026'])
            ->assertStatus(403)->assertJsonFragment(['message' => 'Su solicitud de cuenta aún no fue aprobada. El Encargado de Laboratorios la revisará pronto.']);

        // El Encargado ve el aviso y la aprueba
        $enc = $this->como('encargado@ficct.edu.bo');
        $this->assertSame(1, $this->getJson('/api/me')->json('avisos./usuarios'));
        $this->putJson("/api/usuarios/{$u->id}/aprobar")->assertOk();
        $this->assertSame('ACTIVO', $u->fresh()->estado);
        $this->assertSame($enc->id, $u->fresh()->aprobado_por);

        // Ya entra, con acceso a laboratorios, disponibilidad y sus reservas (CU9: el estudiante no tiene multas ni pagos)
        $this->postJson('/api/login', ['email' => 'lucia.paz@ficct.edu.bo', 'password' => 'Clave2026'])->assertOk();
        $this->como('lucia.paz@ficct.edu.bo');
        $rutas = collect($this->getJson('/api/me')->json('menu'))->pluck('ruta')->all();
        $this->assertEqualsCanonicalizing(['/', '/disponibilidad', '/reservas', '/laboratorios'], $rutas);
        $this->getJson('/api/reservas')->assertOk();
        $this->getJson('/api/multas')->assertStatus(403);
        $this->getJson('/api/equipos')->assertStatus(403);
        $this->getJson('/api/reportes/reservas')->assertStatus(403);
        $this->putJson('/api/multas/1/anular', ['observacion' => 'no debería poder'])->assertStatus(403);
    }

    public function test_validaciones_del_registro_en_espanol(): void
    {
        $r = $this->postJson('/api/registro', ['codigo' => 'ABC', 'email' => 'docente@ficct.edu.bo', 'telefono' => '123', 'carrera' => 'Medicina',
            'detalle_registro' => 'corto', 'password' => 'abc', 'password_confirmation' => 'xyz']);

        $r->assertStatus(422)->assertJsonValidationErrors(['codigo', 'nombre', 'email', 'telefono', 'carrera', 'detalle_registro', 'password']);
        $this->assertSame('El registro universitario debe tener solo números (entre 3 y 10 dígitos).', $r->json('errors.codigo.0'));
        $this->assertSame('Ese correo ya tiene una cuenta o una solicitud pendiente.', $r->json('errors.email.0'));
    }

    public function test_rechazar_elimina_la_solicitud_y_permite_registrarse_de_nuevo(): void
    {
        $this->postJson('/api/registro', $this->datos)->assertCreated();
        $id = User::where('email', 'lucia.paz@ficct.edu.bo')->value('id');

        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/usuarios/$id/rechazar")->assertOk();
        $this->assertDatabaseMissing('usuarios', ['id' => $id]);
        $this->putJson('/api/usuarios/'.User::where('email', 'docente@ficct.edu.bo')->value('id').'/rechazar')->assertStatus(422); // solo pendientes

        $this->postJson('/api/registro', $this->datos)->assertCreated();
    }

    public function test_un_docente_no_puede_aprobar_solicitudes(): void
    {
        $this->postJson('/api/registro', $this->datos)->assertCreated();
        $this->como('docente@ficct.edu.bo');
        $this->putJson('/api/usuarios/'.User::where('email', 'lucia.paz@ficct.edu.bo')->value('id').'/aprobar')->assertStatus(403);
    }
}
