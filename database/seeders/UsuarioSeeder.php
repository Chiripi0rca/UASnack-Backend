<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Usuario;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Usuario::create([
            'nombre' => 'Ricardo',
            'apellido' => 'Ramos',
            'correo_institucional' => 'r.ramos22@info.uas.edu.mx',
            'id_facultad' => 1,
            'hash_password' => Hash::make('password'),
            'telefono' => '1122334455',
        ]);

        Usuario::create([
            'nombre' => 'Cesar',
            'apellido' => 'Burgos',
            'correo_institucional' => 'ca.burgos22@info.uas.edu.mx',
            'id_facultad' => 1,
            'hash_password' => Hash::make('password'),
            'telefono' => '1234567890',
        ]);
    }
}
