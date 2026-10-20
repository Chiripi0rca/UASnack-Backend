<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\Rol;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Asignar el encargado de una tienda por correo (#104, HU-31).
// El administrador solo opera las tiendas de SU facultad: una tienda de otra responde 404.
class AsignarEncargadoService
{
    public function asignar(Usuario $admin, int $idTienda, string $correo): Tienda
    {
        return DB::transaction(function () use ($admin, $idTienda, $correo) {
            // 1. Buscar y bloquear la tienda, solo dentro de la facultad del admin.
            $tienda = Tienda::query()
                ->where('id_facultad', $admin->id_facultad)
                ->lockForUpdate()
                ->find($idTienda);

            if (! $tienda) {
                throw new ErrorNegocio('NO_ENCONTRADO', 'La tienda no existe.', 404);
            }

            // 2. Buscar y bloquear al usuario por correo institucional.
            $nuevoEncargado = Usuario::query()
                ->where('correo_institucional', $correo)
                ->lockForUpdate()
                ->first();

            if (! $nuevoEncargado) {
                $this->usuarioNoEncontrado();
            }

            // 3. Las mismas reglas que ve el panel al buscar el correo (correo verificado,
            //    no admin, sin otra tienda).
            $this->validarCandidato($nuevoEncargado, $tienda->id, lockear: true);

            // 4. Liberar al encargado anterior, si es diferente.
            if ((int) $tienda->id_dueno !== (int) $nuevoEncargado->id) {
                $encargadoAnterior = Usuario::query()
                    ->lockForUpdate()
                    ->find($tienda->id_dueno);

                if ($encargadoAnterior) {
                    $encargadoAnterior->asignarRol(Rol::USUARIO);
                    $encargadoAnterior->save();
                }
            }

            // 5. Asignar el rol al nuevo encargado y dejarlo como dueño de la tienda.
            $nuevoEncargado->asignarRol(Rol::ENCARGADO_TIENDA);
            $nuevoEncargado->save();

            $tienda->id_dueno = $nuevoEncargado->id;
            $tienda->save();

            // Mismas relaciones que usa TiendaAdminResource.
            return $tienda->load(['facultad', 'dueno']);
        });
    }

    // GET /admin/encargados: los encargados de la facultad del admin, con su tienda.
    public function encargados(Usuario $admin): Collection
    {
        $encargados = Usuario::query()
            ->where('id_rol', Rol::idDe(Rol::ENCARGADO_TIENDA))
            ->where('id_facultad', $admin->id_facultad)
            ->orderBy('nombre')
            ->orderBy('apellido')
            ->get();

        $tiendas = Tienda::whereIn('id_dueno', $encargados->pluck('id'))->get()->keyBy('id_dueno');

        return $encargados->map(fn (Usuario $u) => $this->resumen($u, $tiendas->get($u->id)));
    }

    // GET /admin/usuarios/buscar?correo=: para que el panel muestre a quién va a asignar
    // ANTES de guardar. Dice si se puede y, si no, por qué (mismo mensaje que daría asignar).
    public function buscarCandidato(string $correo, ?int $idTienda = null): array
    {
        $usuario = Usuario::where('correo_institucional', $correo)->first();

        if (! $usuario) {
            $this->usuarioNoEncontrado();
        }

        $motivo = null;

        try {
            $this->validarCandidato($usuario, $idTienda);
        } catch (ErrorNegocio $e) {
            $motivo = ['codigo' => $e->codigo, 'mensaje' => $e->getMessage()];
        }

        return [
            ...$this->resumen($usuario, Tienda::where('id_dueno', $usuario->id)->first()),
            'puede_ser_encargado' => $motivo === null,
            'motivo' => $motivo,
        ];
    }

    // Reglas para que alguien sea encargado. $idTienda es la tienda a la que se le asignaría:
    // si ya es el encargado de ESA tienda, no cuenta como "otra tienda".
    private function validarCandidato(Usuario $candidato, ?int $idTienda, bool $lockear = false): void
    {
        if (! $candidato->correo_verificado_en) {
            throw new ErrorNegocio(
                'CORREO_NO_VERIFICADO',
                'El usuario debe verificar su correo antes de ser encargado.',
                422
            );
        }

        if ($candidato->tieneRol(Rol::ADMINISTRADOR)) {
            throw new ErrorNegocio(
                'ADMIN_NO_PUEDE_SER_ENCARGADO',
                'No se puede asignar un administrador como encargado.',
                422
            );
        }

        $tieneOtraTienda = Tienda::query()
            ->where('id_dueno', $candidato->id)
            ->when($idTienda, fn ($q) => $q->where('id', '!=', $idTienda))
            ->when($lockear, fn ($q) => $q->lockForUpdate())
            ->exists();

        if ($tieneOtraTienda) {
            throw new ErrorNegocio(
                'ENCARGADO_EN_OTRA_TIENDA',
                'Este usuario ya es encargado de otra tienda.',
                409
            );
        }
    }

    // Lo justo para identificar a la persona en el panel. Nunca teléfono ni contraseña.
    private function resumen(Usuario $usuario, ?Tienda $tienda): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => trim($usuario->nombre.' '.$usuario->apellido),
            'correo' => $usuario->correo_institucional,
            'rol' => $usuario->claveRol(),
            'correo_verificado' => $usuario->correo_verificado_en !== null,
            'tienda' => $tienda ? ['id' => $tienda->id, 'nombre' => $tienda->nombre] : null,
        ];
    }

    private function usuarioNoEncontrado(): never
    {
        throw new ErrorNegocio(
            'USUARIO_NO_ENCONTRADO',
            'No existe un usuario con ese correo institucional.',
            404
        );
    }
}