<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\EquipoRed;
use App\Models\Incidencia;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** CU6 - Configuración de red de los equipos, reglas de incidencias y avisos */
class InventarioRedTest extends SistemaTestCase
{
    private function equipo(array $cambios = []): array
    {
        return array_merge([
            'codigo' => 'PC-TEST-01', 'laboratorio_id' => DB::table('laboratorios')->where('codigo', 'LAB-41')->value('id'),
            'tipo' => 'COMPUTADORA', 'estado' => 'OPERATIVO', 'descripcion' => 'Core i7, 16 GB RAM',
            'red_ip' => '172.20.172.201', 'red_mascara' => '255.255.255.0', 'red_gateway' => '172.20.172.254',
            'red_dns1' => '10.10.0.251', 'red_dns2' => '8.8.8.8', 'red_proxy' => '172.20.172.254:3128', 'red_grupo' => 'lab41',
        ], $cambios);
    }

    public function test_registra_equipo_con_red_y_valida_la_red(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $id = $this->postJson('/api/equipos', $this->equipo())->assertCreated()
            ->assertJsonPath('data.red.ip', '172.20.172.201')->assertJsonPath('data.red.grupo_red', 'LAB41')->json('data.id');

        // IP repetida: dice qué equipo la tiene
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'PC-TEST-02']))->assertStatus(422)
            ->assertJsonFragment(['message' => 'La IP 172.20.172.201 ya está asignada a PC-TEST-01 (LAB-41).']);
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'PC-TEST-02', 'red_ip' => '172.20.172.300']))->assertStatus(422)
            ->assertJsonValidationErrors(['red_ip' => 'El campo IP debe ser una dirección IPv4 válida (ej: 172.20.172.5).']);
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'PC-TEST-02', 'red_ip' => '172.20.172.202', 'red_mascara' => '255.0.255.0']))
            ->assertStatus(422)->assertJsonFragment(['message' => 'La máscara 255.0.255.0 no es válida (ej: 255.255.255.0).']);
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'PC-TEST-02', 'red_ip' => '172.20.172.202', 'red_gateway' => '172.20.173.254']))
            ->assertStatus(422)->assertJsonFragment(['message' => 'El gateway 172.20.173.254 no está en la misma subred que la IP 172.20.172.202 (máscara 255.255.255.0).']);
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'PC-TEST-02', 'red_ip' => '172.20.172.202', 'red_mascara' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('red_mascara');

        // Editar conservando su propia IP; buscar por IP; quitar la red
        $this->putJson("/api/equipos/$id", $this->equipo(['descripcion' => 'Core i7, 32 GB RAM']))->assertOk();
        $this->assertSame([$id], array_column($this->getJson('/api/equipos?q=172.20.172.201')->json('data'), 'id'));
        $this->putJson("/api/equipos/$id", $this->equipo(['red_ip' => null]))->assertOk()->assertJsonPath('data.red', null);

        // Software no lleva red: los datos de red se ignoran
        $this->postJson('/api/equipos', $this->equipo(['codigo' => 'SW-OFFICE', 'tipo' => 'SOFTWARE']))->assertCreated()->assertJsonPath('data.red', null);
        $this->assertFalse(EquipoRed::where('ip', '172.20.172.201')->exists());
    }

    public function test_incidencias_avisan_y_protegen_el_equipo(): void
    {
        $equipo = Equipo::where('estado', 'OPERATIVO')->whereDoesntHave('incidencias')->firstOrFail();
        $enc = User::where('email', 'encargado@ficct.edu.bo')->value('id');
        $doc = User::where('email', 'docente@ficct.edu.bo')->value('id');
        $avisos = fn ($u) => DB::table('notificaciones')->where('usuario_id', $u)->count();
        $antesEnc = $avisos($enc);

        // Un docente reporta: el equipo pasa a EN_REPARACION (trigger) y el Encargado recibe el aviso
        $this->como('docente@ficct.edu.bo');
        $id = $this->postJson('/api/incidencias', ['equipo_id' => $equipo->id, 'descripcion' => 'No enciende el monitor'])->assertCreated()->json('data.id');
        $this->assertSame('EN_REPARACION', $equipo->fresh()->estado);
        $this->assertSame($antesEnc + 1, $avisos($enc));

        // Otro reporte de la misma falla: rechazado
        $this->como('auxiliar@ficct.edu.bo');
        $this->postJson('/api/incidencias', ['equipo_id' => $equipo->id, 'descripcion' => 'El monitor no prende'])->assertStatus(422);

        // El Encargado no puede volverlo a OPERATIVO a mano, ni borrarlo
        $this->como('encargado@ficct.edu.bo');
        $this->putJson("/api/equipos/{$equipo->id}", ['codigo' => $equipo->codigo, 'laboratorio_id' => $equipo->laboratorio_id, 'tipo' => $equipo->tipo,
            'descripcion' => $equipo->descripcion, 'estado' => 'OPERATIVO'])->assertStatus(422);
        $this->deleteJson("/api/equipos/{$equipo->id}")->assertStatus(422);

        // Atender → no vuelve a PENDIENTE → resolver: el docente recibe los avisos y el equipo vuelve a OPERATIVO
        $antesDoc = $avisos($doc);
        $this->putJson("/api/incidencias/$id", ['estado' => 'EN_ATENCION'])->assertOk();
        $this->putJson("/api/incidencias/$id", ['estado' => 'PENDIENTE'])->assertStatus(422);
        $this->putJson("/api/incidencias/$id", ['estado' => 'RESUELTA'])->assertStatus(422)->assertJsonValidationErrors('solucion');
        $this->putJson("/api/incidencias/$id", ['estado' => 'RESUELTA', 'solucion' => 'Se cambió el cable de video'])->assertOk();
        $this->assertSame($antesDoc + 2, $avisos($doc));
        $this->assertSame('OPERATIVO', $equipo->fresh()->estado);
        $this->assertNotNull(Incidencia::find($id)->fecha_solucion);
    }
}
