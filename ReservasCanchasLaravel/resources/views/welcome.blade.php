<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Reservas de canchas') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7fb; color: #172033; font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 620px; padding: 40px; border: 1px solid #dfe5ee; border-radius: 18px; background: #fff; box-shadow: 0 16px 48px #17203312; }
        h1 { margin: 0 0 12px; font-size: 2rem; }
        p { color: #536078; }
        nav { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 26px; }
        a { display: inline-block; border-radius: 8px; padding: 11px 16px; background: #175cd3; color: #fff; text-decoration: none; font-weight: 700; }
        a.secondary { background: #eaf1fc; color: #175cd3; }
    </style>
</head>
<body>
<main>
    <h1>Reservas de canchas</h1>
    <p>Administra canchas y reservas mediante Laravel y SOAP. Inicia sesión para validar tu autenticación JWT con el servicio.</p>
    <nav aria-label="Acceso">
        <a href="{{ route('login') }}">Iniciar sesión</a>
        <a class="secondary" href="{{ route('register') }}">Crear cuenta</a>
    </nav>
</main>
</body>
</html>
