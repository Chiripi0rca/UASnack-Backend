<?php

namespace App\Http\Requests;

use App\Models\Pedido;
use App\Services\CapacidadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

// POST /pedidos (#148). Aquí solo se valida la forma de lo que manda la app.
// Nunca se aceptan precios ni totales: el servidor los calcula (CatalogoService::calcularTotal).
class CrearPedidoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $conFranja = Rule::requiredIf(fn () => $this->input('modo_retiro') === Pedido::RETIRO_FRANJA);

        return [
            'id_tienda' => ['required', 'integer', 'min:1'],
            'modo_retiro' => ['required', Rule::in([Pedido::RETIRO_FRANJA, Pedido::RETIRO_VOY_SALIENDO])],
            // la franja es la que regresó GET /tiendas/{id}/franjas: solo se usan fecha e inicio
            'franja' => ['nullable', 'array'],
            'franja.fecha' => [$conFranja, 'date_format:Y-m-d'],
            'franja.inicio' => [$conFranja, 'date_format:H:i'],
            'lineas' => ['required', 'array', 'min:1', 'max:50'],
            'lineas.*.id_producto' => ['required', 'integer', 'min:1'],
            'lineas.*.cantidad' => ['required', 'integer', 'min:1', 'max:99'],
            'lineas.*.extras' => ['nullable', 'array'],
            'lineas.*.extras.*' => ['integer'],
            'lineas.*.quitables' => ['nullable', 'array'],
            'lineas.*.quitables.*' => ['integer'],
        ];
    }

    // la hora de recoger, en hora de la tienda; null en "voy saliendo"
    public function franja(): ?Carbon
    {
        if ($this->input('modo_retiro') !== Pedido::RETIRO_FRANJA) {
            return null;
        }

        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $this->input('franja.fecha').' '.$this->input('franja.inicio'),
            CapacidadService::ZONA_HORARIA,
        );
    }

    public function messages(): array
    {
        return [
            'id_tienda.required' => 'Indica de qué tienda es el pedido.',
            'id_tienda.integer' => 'La tienda no es válida.',
            'modo_retiro.required' => 'Indica cómo vas a recoger tu pedido.',
            'modo_retiro.in' => 'El modo de retiro no es válido.',
            'franja.fecha.required' => 'Elige la hora para recoger tu pedido.',
            'franja.fecha.date_format' => 'La hora de recogida no es válida.',
            'franja.inicio.required' => 'Elige la hora para recoger tu pedido.',
            'franja.inicio.date_format' => 'La hora de recogida no es válida.',
            'lineas.required' => 'Agrega al menos un producto.',
            'lineas.min' => 'Agrega al menos un producto.',
            'lineas.max' => 'Un pedido no puede tener más de 50 productos.',
            'lineas.*.id_producto.required' => 'Falta el producto de una línea.',
            'lineas.*.id_producto.integer' => 'Un producto no es válido.',
            'lineas.*.cantidad.required' => 'Indica la cantidad de cada producto.',
            'lineas.*.cantidad.integer' => 'La cantidad debe ser un número entero.',
            'lineas.*.cantidad.min' => 'La cantidad debe ser de al menos 1.',
            'lineas.*.cantidad.max' => 'La cantidad no puede pasar de 99.',
        ];
    }
}
