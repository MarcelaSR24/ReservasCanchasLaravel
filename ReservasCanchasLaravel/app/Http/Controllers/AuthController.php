<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function register(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols(), 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => mb_strtolower($validated['email']),
            'password' => $validated['password'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'exito' => true,
                'mensaje' => 'Usuario registrado correctamente. Ya puedes iniciar sesión.',
                'usuario' => $user->only(['id', 'name', 'email']),
            ], 201);
        }

        return redirect()->route('login')->with('status', 'Registro completado. Inicia sesión con tu correo.');
    }

    public function login(Request $request, JwtService $jwt): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', mb_strtolower($validated['email']))->first();
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            if ($request->expectsJson()) {
                return response()->json(['exito' => false, 'mensaje' => 'Las credenciales no son correctas.'], 422);
            }

            return back()->withErrors(['email' => 'Las credenciales no son correctas.'])->onlyInput('email');
        }

        try {
            $issued = $jwt->issue((int) $user->getKey());
        } catch (Throwable) {
            if ($request->expectsJson()) {
                return response()->json([
                    'exito' => false,
                    'mensaje' => 'La autenticación JWT no está configurada correctamente.',
                ], 503);
            }

            return back()->withErrors(['email' => 'La autenticación no está disponible. Verifica la configuración del servidor.'])->onlyInput('email');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'exito' => true,
                'mensaje' => 'Inicio de sesión correcto.',
                'token_type' => 'Bearer',
                'access_token' => $issued['token'],
                'expires_in' => max(1, (int) config('jwt.ttl', 60)) * 60,
                'usuario' => $user->only(['id', 'name', 'email']),
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('jwt_token', $issued['token']);

        return redirect()->route('dashboard');
    }

    public function dashboard(): View
    {
        return view('auth.dashboard', ['user' => Auth::user()]);
    }

    public function validateSoapToken(Request $request, SoapConnectionService $soap): JsonResponse
    {
        $token = (string) $request->session()->get('jwt_token', '');
        if ($token === '') {
            return response()->json(['exito' => false, 'mensaje' => 'Inicia sesión nuevamente para validar tu JWT.'], 401);
        }

        try {
            $result = $soap->validateToken($token);

            return response()->json($result, ($result['exito'] ?? false) === true ? 200 : 401);
        } catch (Throwable) {
            return response()->json([
                'exito' => false,
                'mensaje' => 'No fue posible validar el token mediante SOAP. Revisa el servicio y la configuración WS-Security.',
            ], 502);
        }
    }

    public function logout(Request $request, JwtService $jwt): JsonResponse|RedirectResponse
    {
        $claims = $request->attributes->get('jwt_claims');
        if (is_array($claims)) {
            $jwt->revoke($claims);

            return response()->json(['exito' => true, 'mensaje' => 'Token revocado.']);
        }

        if (! is_array($claims)) {
            $token = (string) $request->session()->get('jwt_token', '');
            if ($token !== '') {
                try {
                    $claims = $jwt->validate($token);
                } catch (Throwable) {
                    $claims = null;
                }
            }
        }

        if (is_array($claims)) {
            $jwt->revoke($claims);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['exito' => true, 'mensaje' => 'Sesión cerrada.']);
        }

        return redirect()->route('login')->with('status', 'Sesión cerrada.');
    }
}
