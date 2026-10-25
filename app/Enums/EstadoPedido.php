<?php

namespace App\Enums;

// Estados del pedido (§6 del documento general). Van en MAYÚSCULAS a propósito (Anexo D.3):
// así se distingue de un vistazo un estado de la máquina de un valor de catálogo.
enum EstadoPedido: string
{
    case PendientePago = 'PENDIENTE_PAGO';          // creado, esperando el webhook de Stripe
    case EnEspera = 'EN_ESPERA';                    // pagado en "voy saliendo", aún no entra a cocina (§7.3)
    case Pagado = 'PAGADO';
    case Aceptado = 'ACEPTADO';
    case EnPreparacion = 'EN_PREPARACION';
    case Listo = 'LISTO';
    case Entregado = 'ENTREGADO';
    case NoRecogido = 'NO_RECOGIDO';
    case Rechazado = 'RECHAZADO';
    case CanceladoPorUsuario = 'CANCELADO_POR_USUARIO';
}
