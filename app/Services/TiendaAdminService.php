<?php

namespace App\Services;

use App\Exceptions\ErrorNegocio;
use App\Models\Tienda;
use App\Models\Usuario;
use Illuminate\Support\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

// Lógica de administración de tiendas (panel del administrador).
// aquí el admin lista, da de alta y (después) edita las tiendas de SU facultad.
class TiendaAdminService
{

    private const CUPO_BAJO_DEMANDA_INICIAL = 3;   // «voy saliendo» simultáneos
    private const UMBRAL_ESPERA_MIN_INICIAL = 15;  // minutos
    // Lista todas las tiendas de la facultad del admin, activas e inactivas,
    public function tiendasAdmin(Usuario $admin): Collection
    {
        // with() es como el include de Prisma: trae la facultad y el encargado
        return Tienda::with(['facultad', 'dueno'])
            ->where('id_facultad', $admin->id_facultad) // solo las de su facultad
            ->orderBy('nombre')                         // orden alfabético para la tabla del panel
            ->get();
    }

    // Crea una tienda en la facultad del admin.
    // $datos ya viene validado por CrearTiendaRequest .
    public function crearTienda(Usuario $admin, array $datos): Tienda
    {
        // Si el admin no tiene facultad, no sabemos dónde crear la tienda.
        // Se lanza ErrorNegocio en lugar de devolver response()
        if (! $admin->id_facultad) {
            throw new ErrorNegocio(
                'SIN_FACULTAD_ASIGNADA',
                'Tu cuenta no tiene una facultad asignada.',
                403
            );
        }

        $tienda = Tienda::create([
            'nombre'           => $datos['nombre'],
            'ubicacion'        => $datos['ubicacion'],
            'horario_apertura' => $datos['horario_apertura'],
            'horario_cierre'   => $datos['horario_cierre'],
            // La facultad sale del admin, NUNCA del cliente:
            'id_facultad'      => $admin->id_facultad,
             'cupo_bajo_demanda' => self::CUPO_BAJO_DEMANDA_INICIAL,
            'umbral_espera_min' => self::UMBRAL_ESPERA_MIN_INICIAL,
        ]);

        // fresh() vuelve a leer la tienda de la BD para traer los valores
        // que puso Postgres por default (activa, duracion_franja_min, etc.).
        // load() carga las relaciones que usa TiendaAdminResource para la respuesta.
        return $tienda->fresh()->load(['facultad', 'dueno']);
    }

        // Edita una tienda de la facultad del admin.
    // $datos trae SOLO los campos que mandó el cliente (ya validados).
    public function actualizarTienda(Usuario $admin, int $id, array $datos): Tienda
    {
        // Busca la tienda SOLO dentro de la facultad del admin.
        $tienda = Tienda::where('id_facultad', $admin->id_facultad)->find($id);

        if (! $tienda) {
            throw new ErrorNegocio('NO_ENCONTRADO', 'La tienda no existe.', 404);
        }

        // fill() asigna los campos que estén en $fillable, pero NO guarda todavía.
        // Quitamos 'activa' porque no está en $fillable
        $tienda->fill(Arr::except($datos, ['activa']));

        // array_key_exists y no isset: isset() da false si el valor es false,
        // y justamente "activa": false es dar de baja la tienda.
        if (array_key_exists('activa', $datos)) {
            $tienda->activa = $datos['activa'];
        }

        // Ya con los cambios aplicados en memoria, revisamos que el cierre
        // sea después de la apertura (mezclando lo nuevo con lo que ya había).
        // Carbon::parse sirve igual si el horario viene como texto o como fecha.
        $apertura = Carbon::parse($tienda->horario_apertura);
        $cierre   = Carbon::parse($tienda->horario_cierre);

        if ($cierre->lte($apertura)) {
            // ValidationException para que salga como VALIDACION 422
            // con 'campos', igual que los errores del Form Request.
            throw ValidationException::withMessages([
                'horario_cierre' => 'El horario de cierre debe ser después del de apertura.',
            ]);
        }

        // save() hace el UPDATE solo de las columnas que cambiaron.
        $tienda->save();

        return $tienda->fresh(['facultad', 'dueno']);
    }
}