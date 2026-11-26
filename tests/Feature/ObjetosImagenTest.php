<?php

namespace Tests\Feature;

use App\Models\Laboratorio;
use App\Models\ObjetoEncontrado;
use App\Support\ImagenObjeto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** CU6 - Imagen de los objetos encontrados: referencia según el tipo de objeto o foto subida por el auxiliar */
class ObjetosImagenTest extends SistemaTestCase
{
    public function test_la_imagen_de_referencia_se_elige_por_la_descripcion(): void
    {
        $this->assertSame('img/objetos/mouse.jpg', ImagenObjeto::sugerir('Mouse inalámbrico negro HP'));
        $this->assertSame('img/objetos/cargador_laptop.jpg', ImagenObjeto::sugerir('Cargador de laptop Lenovo'));
        $this->assertSame('img/objetos/cargador_celular.jpg', ImagenObjeto::sugerir('Cargador Samsung con cable tipo C'));
        $this->assertSame('img/objetos/audifonos_inalambricos.jpg', ImagenObjeto::sugerir('Auriculares inalambricos Realme Buds'));
        $this->assertSame('img/objetos/estuche_lentes.jpg', ImagenObjeto::sugerir('Funda de lentes (cajita negra)'));
        $this->assertNull(ImagenObjeto::sugerir('Objeto sin descripcion'));
        foreach (array_unique(array_filter(array_map(fn ($c) => $c === '__audifonos' ? null : $c, ImagenObjeto::REGLAS))) as $img) {
            $this->assertFileExists(public_path("img/objetos/$img.jpg"));
        }
    }

    public function test_el_auxiliar_registra_con_foto_y_la_puede_quitar(): void
    {
        Storage::fake('local');
        $this->como('auxiliar@ficct.edu.bo');
        $obj = $this->postJson('/api/objetos', ['laboratorio_id' => Laboratorio::value('id'), 'descripcion' => 'Termo Stanley verde',
            'fecha_hallazgo' => now()->toDateString()])->assertCreated()
            ->assertJsonPath('data.imagen', 'img/objetos/termo.jpg')->assertJsonPath('data.imagen_propia', false)->json('data.id');

        $this->post("/api/objetos/$obj/imagen", ['imagen' => UploadedFile::fake()->image('termo.jpg', 800, 600)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.imagen_propia', true);
        $ruta = ObjetoEncontrado::find($obj)->imagen;
        $this->assertStringStartsWith('media/objetos/', $ruta);
        Storage::disk('local')->assertExists('objetos/'.basename($ruta));

        // Al cambiar la descripción, la foto propia se mantiene
        $this->putJson("/api/objetos/$obj", ['laboratorio_id' => Laboratorio::value('id'), 'descripcion' => 'Termo Stanley verde grande',
            'fecha_hallazgo' => now()->toDateString()])->assertOk();
        $this->assertSame($ruta, ObjetoEncontrado::find($obj)->imagen);

        $this->deleteJson("/api/objetos/$obj/imagen")->assertOk();
        $this->assertSame('img/objetos/termo.jpg', ObjetoEncontrado::find($obj)->imagen);
        Storage::disk('local')->assertMissing('objetos/'.basename($ruta));

        // Un archivo que no es imagen se rechaza
        $this->post("/api/objetos/$obj/imagen", ['imagen' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['imagen']);
    }

    public function test_el_encargado_ve_los_objetos_pero_no_sube_fotos(): void
    {
        $this->como('encargado@ficct.edu.bo');
        $id = ObjetoEncontrado::value('id');
        $this->getJson('/api/objetos')->assertOk()->assertJsonStructure(['data' => [['imagen_url', 'imagen_propia']]]);
        $this->post("/api/objetos/$id/imagen", ['imagen' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertStatus(403);
    }
}
