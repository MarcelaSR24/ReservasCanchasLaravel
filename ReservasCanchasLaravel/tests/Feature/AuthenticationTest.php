<?php

namespace Tests\Feature;

use App\Http\Middleware\JwtAuthenticate;
use App\Models\User;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('j', 64),
            'jwt.algorithm' => 'HS256',
            'jwt.issuer' => 'http://testing.local',
            'jwt.ttl' => 30,
            'cache.default' => 'array',
        ]);
    }

    public function test_user_can_register_and_password_is_hashed(): void
    {
        $authenticatedUser = User::factory()->create();

        $response = $this->postJson('/api/register', [
            'name' => 'María Pruebas',
            'email' => 'Maria@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ], $this->bearerHeaders((int) $authenticatedUser->id));

        $response->assertCreated()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('usuario.email', 'maria@example.com');

        $user = User::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePass123!', $user->password));
        $this->assertArrayNotHasKey('password', $response->json('usuario'));
    }

    public function test_registration_rejects_duplicate_email_and_weak_password(): void
    {
        $authenticatedUser = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.com']);
        $headers = $this->bearerHeaders((int) $authenticatedUser->id);

        $this->postJson('/api/register', [
            'name' => 'Otra persona',
            'email' => 'taken@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->postJson('/api/register', [
            'name' => 'Persona',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_public_web_registration_does_not_assign_a_client_supplied_role(): void
    {
        $this->post('/register', [
            'name' => 'Usuario de prueba',
            'email' => 'rol@example.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'role' => 'administrador',
        ])->assertRedirect(route('login'));

        $user = User::query()->where('email', 'rol@example.com')->firstOrFail();
        $this->assertNull($user->role);
    }

    public function test_login_issues_hs256_jwt_and_rejects_wrong_password(): void
    {
        $user = User::factory()->create([
            'email' => 'member@example.com',
            'password' => Hash::make('SecurePass123!'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'MEMBER@example.com',
            'password' => 'SecurePass123!',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer');

        $claims = app(JwtService::class)->validate($response->json('access_token'));
        $this->assertSame((string) $user->id, $claims['sub']);
        $this->assertSame('http://testing.local', $claims['iss']);
        $this->assertArrayHasKey('jti', $claims);
        $this->assertGreaterThan(time(), $claims['exp']);

        $this->postJson('/api/login', [
            'email' => 'member@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonMissingPath('access_token');
    }

    public function test_user_can_log_in_through_the_web_session(): void
    {
        $user = User::factory()->create([
            'email' => 'web-login@example.com',
            'password' => Hash::make('SecurePass123!'),
        ]);

        $this->post('/login', [
            'email' => 'WEB-LOGIN@example.com',
            'password' => 'SecurePass123!',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
    }

    public function test_logout_revokes_the_bearer_token(): void
    {
        $user = User::factory()->create();
        $issued = app(JwtService::class)->issue((int) $user->id);
        $headers = ['Authorization' => 'Bearer '.$issued['token']];

        $this->getJson('/api/me', $headers)->assertOk()->assertJsonPath('usuario_id', $user->id);
        $this->postJson('/api/logout', [], $headers)->assertOk();
        $this->getJson('/api/me', $headers)->assertUnauthorized();
    }

    public function test_protected_api_rejects_a_missing_bearer_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized()->assertJsonPath('exito', false);
    }

    public function test_jwt_middleware_resolves_the_authenticated_user_on_the_request(): void
    {
        $user = User::factory()->create();
        $issued = app(JwtService::class)->issue((int) $user->id);
        $request = Request::create('/protected', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$issued['token'],
        ]);

        $response = app(JwtAuthenticate::class)->handle($request, function (Request $request) use ($user) {
            $this->assertSame($user->id, $request->user()?->id);

            return response('resolved');
        });

        $this->assertSame('resolved', $response->getContent());
    }

    public function test_valid_jwt_for_a_deleted_user_is_rejected(): void
    {
        $issued = app(JwtService::class)->issue(999999);
        $request = Request::create('/protected', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$issued['token'],
        ]);

        $response = app(JwtAuthenticate::class)->handle($request, fn () => response('unexpected'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_registration_and_login_pages_are_available(): void
    {
        $this->get('/register')->assertOk()->assertSee('Confirmar contraseña');
        $this->get('/login')->assertOk()->assertSee('Iniciar sesión');
    }

    /** @return array<string, string> */
    private function bearerHeaders(int $userId): array
    {
        $issued = app(JwtService::class)->issue($userId);

        return ['Authorization' => 'Bearer '.$issued['token']];
    }
}
