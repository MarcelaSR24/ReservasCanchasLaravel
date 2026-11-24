@extends('auth.layout')

@section('title', 'Iniciar sesión | Reservas de canchas')

@section('content')
    <h1>Reservas de canchas</h1>
    <p>Inicia sesión. Tu JWT se conservará en la sesión del servidor y no se mostrará en la página.</p>

    @if ($errors->any())
        <div class="notice error" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('login.submit') }}">
        @csrf
        <label for="email">Correo electrónico</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required>

        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" autocomplete="current-password" required>

        <button type="submit">Iniciar sesión</button>
    </form>
    <p>¿Primera vez aquí? <a href="{{ route('register') }}">Crea una cuenta</a>.</p>
@endsection
