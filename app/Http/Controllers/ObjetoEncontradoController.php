<?php

namespace App\Http\Controllers;

use App\Models\ObjetoEncontrado;
use App\Support\ImagenObjeto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CU6 - Objetos encontrados y su entrega */
class ObjetoEncontradoController extends CrudController
{
    protected string $modelo = ObjetoEncontrado::class;
    protected array $buscarEn = ['descripcion', 'estado', 'entregado_a', 'laboratorio.codigo'];
    protected array $con = ['laboratorio', 'registrador'];
    protected array $filtros = ['estado', 'laboratorio_id'];
    protected string $ordenarPor = 'fecha_hallazgo';

    protected function reglas(Request $request, ?Model $registro): array
    {
        return [
            'laboratorio_id' => ['required', 'integer', 'exists:laboratorios,id'],
            'descripcion' => ['required', 'string', 'min:3', 'max:200'],
            'fecha_hallazgo' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    protected function antesDeGuardar(array $datos, Request $request, ?Model $registro): array
    {
        abort_if($registro?->estado === 'ENTREGADO', 422, 'No se puede modificar un objeto ya entregado.');
        if (! $registro) {
            $datos['registrado_por'] = $request->user()->id;
        }
        // Sin foto propia: imagen de referencia según la descripción (se actualiza si cambia la descripción)
        if (! ImagenObjeto::esSubida($registro?->imagen)) {
            $datos['imagen'] = ImagenObjeto::sugerir($datos['descripcion']);
        }

        return $datos;
    }

    protected function antesDeEliminar(Model $registro, Request $request): void
    {
        abort_if($registro->estado === 'ENTREGADO', 422, 'No se puede eliminar un objeto ya entregado (queda como constancia).');
        ImagenObjeto::borrar($registro->imagen);
    }

    /** POST /api/objetos/{id}/imagen  (multipart: imagen) → foto del objeto real, tomada por el auxiliar */
    public function subirImagen(Request $request, int $id): JsonResponse
    {
        $request->validate(['imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']], [
            'imagen.required' => 'Elija una foto.', 'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'La foto debe ser JPG, PNG o WEBP.', 'imagen.max' => 'La foto no puede pasar de 4 MB.',
        ]);
        $objeto = ObjetoEncontrado::findOrFail($id);
        $anterior = $objeto->imagen;
        $objeto->update(['imagen' => ImagenObjeto::guardar($request->file('imagen'))]);
        ImagenObjeto::borrar($anterior);

        return response()->json(['message' => 'Foto guardada.', 'data' => $objeto->fresh($this->con)]);
    }

    /** DELETE /api/objetos/{id}/imagen → quita la foto propia y vuelve a la imagen de referencia */
    public function quitarImagen(int $id): JsonResponse
    {
        $objeto = ObjetoEncontrado::findOrFail($id);
        ImagenObjeto::borrar($objeto->imagen);
        $objeto->update(['imagen' => ImagenObjeto::sugerir($objeto->descripcion)]);

        return response()->json(['message' => 'Se quitó la foto. Se muestra la imagen de referencia.', 'data' => $objeto->fresh($this->con)]);
    }

    public function entregar(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'entregado_a' => ['required', 'string', 'min:5', 'max:100', 'regex:/^[\pL\s]+$/u'],
            'ci_receptor' => ['required', 'regex:/^\d{5,10}(-?[A-Za-z0-9]{1,3})?$/'],
        ], ['ci_receptor.regex' => 'El C.I. debe tener entre 5 y 10 dígitos (puede llevar complemento, ej: 1234567-1A).',
            'entregado_a.regex' => 'El nombre solo puede contener letras y espacios.']);

        $objeto = ObjetoEncontrado::findOrFail($id);
        abort_if($objeto->estado === 'ENTREGADO', 422, 'Este objeto ya fue entregado el '.$objeto->fecha_entrega?->format('d/m/Y H:i').'.');
        $objeto->update($datos + ['estado' => 'ENTREGADO', 'fecha_entrega' => now()]);

        return response()->json(['message' => 'Entrega registrada.', 'data' => $objeto->fresh($this->con)]);
    }
}
