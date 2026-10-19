<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

class AsignarEncargadoService
{
    public function asignar(int $idTienda, string $correo): Tienda
    {
        return DB::transaction(function () use ($idTienda, $correo) {
            // 1. Buscar y bloquear la tienda.
            $tienda = Tienda::query()
                ->lockForUpdate()
                ->find($idTienda);

            if (! $tienda) {
                throw new ErrorNegocio(
                    'TIENDA_NO_ENCONTRADA',
                    'La tienda indicada no existe.',
                    404
                );
            }

            // 2. Buscar y bloquear al usuario por correo institucional.
            $nuevoEncargado = Usuario::query()
                ->where('correo_institucional', $correo)
                ->lockForUpdate()
                ->first();

            if (! $nuevoEncargado) {
                throw new ErrorNegocio(
                    'USUARIO_NO_ENCONTRADO',
                    'No existe un usuario con ese correo institucional.',
                    404
                );
            }

            // 3. Verificar que el correo esté confirmado.
            if (! $nuevoEncargado->correo_verificado_en) {
                throw new ErrorNegocio(
                    'CORREO_NO_VERIFICADO',
                    'El usuario debe verificar su correo antes de ser encargado.',
                    422
                );
            }

            // 4. Un administrador no puede ser encargado.
            if ($nuevoEncargado->tieneRol(Rol::ADMINISTRADOR)) {
                throw new ErrorNegocio(
                    'ADMIN_NO_PUEDE_SER_ENCARGADO',
                    'No se puede asignar un administrador como encargado.',
                    422
                );
            }

            // 5. Comprobar que no tenga otra tienda asignada.
            $tieneOtraTienda = Tienda::query()
                ->where('id_dueno', $nuevoEncargado->id)
                ->where('id', '!=', $tienda->id)
                ->lockForUpdate()
                ->exists();

            if ($tieneOtraTienda) {
                throw new ErrorNegocio(
                    'ENCARGADO_EN_OTRA_TIENDA',
                    'Este usuario ya es encargado de otra tienda.',
                    409
                );
            }

            // 6. Liberar al encargado anterior, si es diferente.
            if ((int) $tienda->id_dueno !== (int) $nuevoEncargado->id) {
                $encargadoAnterior = Usuario::query()
                    ->lockForUpdate()
                    ->find($tienda->id_dueno);

                if ($encargadoAnterior) {
                    $encargadoAnterior->asignarRol(Rol::USUARIO);
                    $encargadoAnterior->save();
                }
            }

            // Asignar el rol al nuevo encargado.
            $nuevoEncargado->asignarRol(Rol::ENCARGADO_TIENDA);
            $nuevoEncargado->save();

            // Actualizar el propietario de la tienda.
            $tienda->id_dueno = $nuevoEncargado->id;
            $tienda->save();

            // Cargar la relación para la respuesta del controlador.
            return $tienda->load('dueno');
        });
    }
}