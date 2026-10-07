<?php

namespace App\Models\Scopes;

use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

// Global scope de los modelos que pertenecen a una tienda (#163).
// Cuando quien pide es un ENCARGADO, toda consulta del modelo se filtra por su tienda:
// lo de otra tienda simplemente "no existe" y Laravel responde 404 (no 403, que confirmaría que existe).
//
// No filtra a nadie más:
//   - el alumno ve el catálogo de todas las tiendas y facultades (decisión del Sprint 02);
//   - el administrador ve todas las tiendas.
// La tienda del encargado la resuelve el middleware "tenant" (#162), que corre antes de buscar
// los modelos de la ruta.
class DeLaTiendaDelEncargado implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenant::class);

        if ($tenant->esEncargado() && $tenant->idTienda) {
            $builder->where($model->qualifyColumn('id_tienda'), $tenant->idTienda);
        }
    }
}
