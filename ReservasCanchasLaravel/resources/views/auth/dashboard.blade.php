@extends('auth.layout')

@section('title', 'Cuenta | Reservas de canchas')

@section('content')
    <h1>Hola, {{ $user->name }}</h1>
    <p>Has iniciado sesión como {{ $user->email }}. El JWT está guardado en la sesión del servidor.</p>

    <form method="POST" action="{{ route('soap.validate') }}">
        @csrf
        <button type="submit">Validar mi sesión con SOAP</button>
    </form>

    <div id="soap-result" class="notice" hidden aria-live="polite"></div>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button class="secondary" type="submit">Cerrar sesión</button>
    </form>

    <script>
        document.querySelector('form[action="{{ route('soap.validate') }}"]').addEventListener('submit', async (event) => {
            event.preventDefault();
            const response = await fetch(event.currentTarget.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': event.currentTarget.querySelector('[name="_token"]').value, 'Accept': 'application/json' }
            });
            const result = await response.json();
            const output = document.getElementById('soap-result');
            output.hidden = false;
            output.textContent = result.mensaje || (result.exito ? 'Token válido.' : 'No fue posible validar el token.');
        });
    </script>
@endsection
