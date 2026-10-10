<?php

namespace Database\Seeders;

use App\Enums\RolUsuario;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        // Usuario de prueba: administrador
        $admin = Usuario::create([
            'nombre' => 'Admin',
            'apellido' => 'Pruebas',
            'correo_institucional' => 'admin.pruebas@info.uas.edu.mx',
            'id_facultad' => 1,
            'hash_password' => Hash::make('Admin12345'),
            'telefono' => '6671000001',
        ]);

        $admin->rol = RolUsuario::Admin;
        $admin->correo_verificado_en = now();
        $admin->save();

        // Usuario de prueba: encargado de tienda
        $encargado = Usuario::create([
            'nombre' => 'Encargado',
            'apellido' => 'Pruebas',
            'correo_institucional' => 'encargado.pruebas@info.uas.edu.mx',
            'id_facultad' => 1,
            'hash_password' => Hash::make('Encargado12345'),
            'telefono' => '6671000002',
        ]);

        $encargado->rol = RolUsuario::Encargado_Tienda;
        $encargado->correo_verificado_en = now();
        $encargado->save();
    }
}