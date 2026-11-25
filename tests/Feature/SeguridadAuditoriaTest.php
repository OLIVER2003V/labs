<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** CU10 - Bloqueo por intentos fallidos, sesiones activas, deshacer cambios y restaurar en lote lo borrado por SQL */
class SeguridadAuditoriaTest extends SistemaTestCase
{
    private function login(string $email, string $password = 'Ficct2026', string $agente = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0')
    {
        return $this->withHeader('User-Agent', $agente)->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    /** Simula a alguien que entra directo a PostgreSQL (sin pasar por el sistema) */
    private function sqlDirecto(callable $fn): void
    {
        DB::select("SELECT set_config('app.usuario_id', '', false)");
        $fn();
    }

    public function test_cinco_intentos_fallidos_bloquean_la_cuenta_hasta_que_el_admin_la_desbloquee(): void
    {
        $email = 'auxiliar@ficct.edu.bo';
        for ($i = 1; $i <= 5; $i++) {
            $this->login($email, 'ClaveMala1')->assertStatus(422);
        }
        $this->travel(2)->minutes(); // pasa el límite de 5 por minuto: ahora rige el bloqueo de la cuenta
        $this->login($email)->assertStatus(423)->assertJsonFragment(['message' =>
            'Por seguridad la cuenta quedó bloqueada por 5 intentos fallidos. Intente de nuevo en 13 minuto(s) o pida al administrador que la desbloquee.']);
        $this->assertDatabaseHas('notificaciones', ['usuario_id' => User::where('email', $email)->value('id'), 'titulo' => 'Su cuenta fue bloqueada por intentos fallidos']);

        $this->como('encargado@ficct.edu.bo');
        $id = User::where('email', $email)->value('id');
        $this->getJson("/api/usuarios/$id/seguridad")->assertOk()->assertJsonPath('bloqueado', true);
        $this->putJson("/api/usuarios/$id/desbloquear")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->login($email)->assertOk();
    }

    public function test_sesiones_activas_cerrar_las_demas_y_cambiar_contrasena(): void
    {
        $email = 'docente@ficct.edu.bo';
        $pc = $this->login($email)->assertOk()->json('token');
        $cel = $this->login($email, 'Ficct2026', 'Mozilla/5.0 (Linux; Android 14) Firefox/131.0')->json('token');
        $this->app['auth']->forgetGuards();

        $sesiones = $this->withToken($pc)->getJson('/api/perfil/sesiones')->assertOk()->json();
        $this->assertCount(2, $sesiones);
        $this->assertContains('Firefox · Android · 127.0.0.1', array_column($sesiones, 'dispositivo'));
        $this->assertTrue(collect($sesiones)->firstWhere('dispositivo', 'Chrome · Windows · 127.0.0.1')['actual']);

        $this->withToken($pc)->deleteJson('/api/perfil/sesiones')->assertOk()
            ->assertJsonFragment(['message' => 'Se cerraron 1 sesión(es) en otros dispositivos.']);
        $this->app['auth']->forgetGuards();
        $this->withToken($cel)->getJson('/api/me')->assertUnauthorized();

        // Cambiar la contraseña cierra las demás sesiones
        $otra = $this->login($email)->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($pc)->putJson('/api/perfil/password', ['password_actual' => 'Ficct2026', 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'])
            ->assertOk()->assertJsonFragment(['message' => 'Contraseña actualizada. Se cerraron 1 sesión(es) en otros dispositivos.']);
        $this->app['auth']->forgetGuards();
        $this->withToken($otra)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_el_encargado_cierra_las_sesiones_de_otro_pero_un_docente_no(): void
    {
        $this->login('docente@ficct.edu.bo')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->como('encargado@ficct.edu.bo');
        $doc = User::where('email', 'docente@ficct.edu.bo')->first();
        $this->putJson("/api/usuarios/{$doc->id}/cerrar-sesiones")->assertOk();
        $this->assertSame(0, $doc->tokens()->count());

        $this->como('docente@ficct.edu.bo');
        $this->putJson('/api/usuarios/'.User::where('email', 'encargado@ficct.edu.bo')->value('id').'/cerrar-sesiones')->assertForbidden();
    }

    public function test_cambio_directo_en_bd_se_avisa_y_se_puede_deshacer(): void
    {
        $lab = DB::table('laboratorios')->orderBy('id')->first();
        $this->sqlDirecto(fn () => DB::table('laboratorios')->where('id', $lab->id)->update(['nombre' => 'HACKEADO', 'capacidad' => 1]));
        $auditoria = DB::table('auditoria')->where('tabla', 'laboratorios')->where('operacion', 'UPDATE')->latest('id')->first();
        $this->assertNull($auditoria->usuario_app_id);

        $this->como('encargado@ficct.edu.bo');
        $this->assertGreaterThanOrEqual(1, $this->getJson('/api/notificaciones')->json('avisos./auditoria'));
        $this->assertSame($auditoria->id, $this->getJson('/api/auditoria?origen=directo&tabla=laboratorios')->json('data.0.id'));

        $this->postJson("/api/auditoria/{$auditoria->id}/deshacer")->assertOk();
        $actual = DB::table('laboratorios')->find($lab->id);
        $this->assertSame([$lab->nombre, $lab->capacidad], [$actual->nombre, $actual->capacidad]);

        // Un cambio que ya no es el último no se deshace (hay que ir del más reciente al más antiguo)
        $this->postJson("/api/auditoria/{$auditoria->id}/deshacer")->assertStatus(422);
    }

    public function test_la_api_informa_la_version_y_las_multas_no_se_eligen_como_concepto(): void
    {
        $this->getJson('/api/estado')->assertOk()->assertHeader('X-App-Version');

        $this->como('docente@ficct.edu.bo');
        $conceptos = array_column($this->getJson('/api/opciones/conceptos')->json(), 'label');
        $this->assertNotEmpty($conceptos);
        $this->assertEmpty(array_filter($conceptos, fn ($l) => str_starts_with($l, 'Multa')));
    }

    public function test_restaurar_en_lote_lo_borrado_por_sql_respetando_padres_e_hijos(): void
    {
        // Un equipo con su red y sus incidencias, borrado "a mano" (primero los hijos, por las claves foráneas)
        $equipo = DB::table('equipos')->whereIn('id', DB::table('incidencias')->select('equipo_id'))->first();
        DB::table('equipos_red')->insertOrIgnore(['equipo_id' => $equipo->id, 'ip' => '10.99.99.99', 'mascara' => '255.255.255.0',
            'gateway' => '10.99.99.1', 'dns1' => '8.8.8.8', 'created_at' => now(), 'updated_at' => now()]);
        $incidencias = DB::table('incidencias')->where('equipo_id', $equipo->id)->count();
        $objetos = DB::table('objetos_encontrados')->orderBy('id')->limit(3)->pluck('id');
        $antes = now()->subMinute();

        $this->sqlDirecto(function () use ($equipo, $objetos) {
            DB::table('incidencias')->where('equipo_id', $equipo->id)->delete();
            DB::table('equipos')->where('id', $equipo->id)->delete(); // la red se borra en cascada
            DB::table('objetos_encontrados')->whereIn('id', $objetos)->delete();
        });
        $this->assertFalse(DB::table('equipos')->where('id', $equipo->id)->exists());

        $this->como('encargado@ficct.edu.bo');
        $r = $this->postJson('/api/auditoria/restaurar-eliminados', ['desde' => $antes->toDateTimeString(), 'solo_directos' => true])
            ->assertOk()->json();
        $this->assertSame(0, $r['fallidos']);
        $this->assertSame(1 + 1 + $incidencias + 3, $r['restaurados']); // equipo + red + incidencias + objetos
        $this->assertTrue(DB::table('equipos')->where('id', $equipo->id)->exists());
        $this->assertSame('10.99.99.99', DB::table('equipos_red')->where('equipo_id', $equipo->id)->value('ip'));
        $this->assertSame($incidencias, DB::table('incidencias')->where('equipo_id', $equipo->id)->count());

        // Repetirlo no duplica nada
        $this->postJson('/api/auditoria/restaurar-eliminados', ['desde' => $antes->toDateTimeString()])->assertOk()->assertJsonPath('restaurados', 0);

        $this->como('docente@ficct.edu.bo');
        $this->postJson('/api/auditoria/restaurar-eliminados', ['desde' => $antes->toDateTimeString()])->assertForbidden();
    }
}
