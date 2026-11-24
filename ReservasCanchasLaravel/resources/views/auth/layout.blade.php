<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Reservas de canchas')</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; background: #f4f7fb; color: #172033; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 460px); background: #fff; border: 1px solid #dfe5ee; border-radius: 16px; padding: 32px; box-shadow: 0 16px 48px #17203312; }
        h1 { margin: 0 0 8px; font-size: 1.7rem; }
        p { color: #536078; line-height: 1.5; }
        label { display: block; margin: 18px 0 6px; font-weight: 600; }
        input { width: 100%; border: 1px solid #bac5d5; border-radius: 8px; padding: 11px 12px; font: inherit; }
        button { width: 100%; border: 0; border-radius: 8px; margin-top: 22px; padding: 12px; background: #175cd3; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        a { color: #175cd3; }
        .notice { margin: 14px 0; padding: 11px 13px; border-radius: 8px; background: #eef5ff; }
        .error { color: #a12622; font-size: .92rem; }
        .actions { display: flex; gap: 12px; align-items: center; margin-top: 20px; }
        .actions form { flex: 1; }
        .actions button { margin: 0; }
        .secondary { background: #334155; }
        code { overflow-wrap: anywhere; }
    </style>
</head>
<body>
<main>
    @if (session('status'))
        <div class="notice" role="status">{{ session('status') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
