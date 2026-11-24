@extends('auth.layout')

@section('title', 'Crear cuenta | Reservas de canchas')

@section('content')
    <h1>Crear cuenta</h1>
    <p>Regístrate para acceder a las herramientas de reservas.</p>

    @if ($errors->any())
        <div class="notice error" role="alert">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.submit') }}">
        @csrf
        <label for="name">Nombre</label>
        <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required maxlength="255">

        <label for="email">Correo electrónico</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required maxlength="255">

        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" autocomplete="new-password" required minlength="12">
        <p>Usa al menos 12 caracteres, con mayúscula, minúscula, número y símbolo.</p>

        <label for="password_confirmation">Confirmar contraseña</label>
        <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required>

        <button type="submit">Crear cuenta</button>
    </form>
    <p>¿Ya tienes una cuenta? <a href="{{ route('login') }}">Inicia sesión</a>.</p>
@endsection
