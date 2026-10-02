<!DOCTYPE html>

<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación UASnack</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f5f5f5; padding: 40px;">

```
<div style="
    max-width: 600px;
    margin: auto;
    background: white;
    padding: 30px;
    border-radius: 12px;
">

    <h1>UASnack</h1>

    <p>
        Hola {{ $nombre }},
    </p>

    <p>
        Gracias por registrarte en UASnack.
    </p>

    <p>
        Tu código de verificación es:
    </p>

    <div style="
        text-align: center;
        font-size: 36px;
        font-weight: bold;
        letter-spacing: 8px;
        margin: 30px 0;
    ">
        {{ $codigo }}
    </div>

    <p>
        Este código es válido durante 10 minutos.
    </p>

    <p>
        Si tú no realizaste este registro, puedes ignorar este correo.
    </p>

    <p>
        Saludos,<br>
        Equipo UASnack
    </p>

</div>
```

</body>
</html>
