<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Código para recuperar la contraseña (HU-33). Mismo formato que CodigoVerificacionMail.
class CodigoRecuperacionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $codigo,
        public string $nombre,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Recupera tu contraseña - UASnack',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.codigo-recuperacion',
            text: 'emails.codigo-recuperacion-texto',
        );
    }
}
