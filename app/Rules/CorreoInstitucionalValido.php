<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use App\Models\DominiosInstitucional;

class CorreoInstitucionalValido implements ValidationRule
{
 
    public function validate(string $attribute, mixed $value, Closure $fail): void
                        //$attribute es el nombre del campo que se esta validando, $value es el valor del campo que se esta validando, $fail es una funcion que se llama cuando la validacion falla
    {
       $dominioCorreo = strtolower(substr(strrchr($value, "@"), 1)); // aqui le quitamos el @ y nos quedamos por ejemplo info.uas.edu.mx

       $valido = DominiosInstitucional::where('activo', true)
             ->get() //aqui traemos el dominio de la universidad las cuales estan activa ejmplo uas.edu.mx 
            ->contains(fn($d) => str_ends_with($dominioCorreo, $d->dominio));
                 //aqui lo que hace $d es que toma todas las filas de la tabla y la ultima $d lo que hace es solo sacar la columna dominio para comparar ;
                 //y si ese dominio no jala se va a la otra fila esto lo hace contains() que va ciclando en cada iteracion
                 // ya que se hicieron todo los ciclos contains() va a regresar un true o false si el dominio es valido o no, y lo guardamos en la variable $valido

    if (!$valido) {
        $fail('El correo electrónico debe pertenecer a un dominio institucional válido.');
    }
    
    }
     
}
