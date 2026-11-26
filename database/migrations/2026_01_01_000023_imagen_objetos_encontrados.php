<?php

use App\Support\ImagenObjeto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU6 - Imagen de los objetos encontrados: foto subida por el auxiliar o, si no hay, una imagen de referencia
 * según el tipo de objeto (ver App\Support\ImagenObjeto). A los objetos existentes se les asigna la de referencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('objetos_encontrados', function (Blueprint $table) {
            $table->string('imagen', 200)->nullable()->after('descripcion');
        });

        foreach (DB::table('objetos_encontrados')->get(['id', 'descripcion']) as $o) {
            if ($img = ImagenObjeto::sugerir($o->descripcion)) {
                DB::table('objetos_encontrados')->where('id', $o->id)->update(['imagen' => $img]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('objetos_encontrados', fn (Blueprint $table) => $table->dropColumn('imagen'));
    }
};
