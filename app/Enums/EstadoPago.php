<?php

// Carpeta (namespace) donde viven los enums del proyecto.
namespace App\Enums;

// Los cuatro estados posibles de un pago (§5.1 del documento general).
// Van en minúsculas porque así los define el documento y así salen en GET /pagos ("estado": "pagado").
// ": string" significa que cada caso se guarda en la base como el texto de la derecha.
enum EstadoPago: string
{
    // Se creó el PaymentIntent en Stripe pero el alumno todavía no confirma la tarjeta.
    case Pendiente = 'pendiente';

    // Stripe avisó por webhook que el cobro sí pasó (lo marca la #62).
    case Pagado = 'pagado';

    // Se devolvió el dinero (cancelación temprana o rechazo de la tienda).
    case Reembolsado = 'reembolsado';

    // Stripe avisó que el cobro no pasó (tarjeta rechazada, sin fondos…).
    case Fallido = 'fallido';
}