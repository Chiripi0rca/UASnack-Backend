<?php

namespace Tests\Feature\Api\V1;

use App\Mail\CodigoVerificacionMail;
use Tests\TestCase;

// Lo que ve el alumno en el correo del código de verificación.
class CorreoCodigoTest extends TestCase
{
    public function test_el_correo_trae_el_codigo_y_el_nombre(): void
    {
        $html = (new CodigoVerificacionMail('482913', 'Ana'))->render();

        $this->assertStringContainsString('482913', $html);
        $this->assertStringContainsString('Hola Ana', $html);
    }

    // Unas comillas de Markdown (```) se colaron en la plantilla y salían tal cual en el correo
    public function test_el_correo_no_trae_restos_de_markdown(): void
    {
        $html = (new CodigoVerificacionMail('482913', 'Ana'))->render();

        $this->assertStringNotContainsString('```', $html);
    }
}
