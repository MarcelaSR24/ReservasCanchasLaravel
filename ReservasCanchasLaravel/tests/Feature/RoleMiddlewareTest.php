<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    private const FORBIDDEN_JSON = '{"error":"Permisos insuficientes para ejecutar este método"}';

    /** @var array<string, list<string>> */
    private array $permissions = [
        'generar-cobro' => ['administrador', 'recepcionista'],
        'consultar-cobro' => ['administrador', 'recepcionista', 'cajero'],
        'actualizar-estado-cobro' => ['administrador', 'cajero'],
        'listar-implementos' => ['administrador', 'recepcionista', 'encargado_inventario'],
        'registrar-implemento' => ['administrador', 'encargado_inventario'],
        'ajustar-stock' => ['administrador', 'encargado_inventario'],
    ];

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

        Route::get('/_test/permissions/generar-cobro', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.manage.billing']);
        Route::get('/_test/permissions/consultar-cobro', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.consult.billing']);
        Route::get('/_test/permissions/actualizar-estado-cobro', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.update.payment.status']);
        Route::get('/_test/permissions/listar-implementos', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.list.equipment']);
        Route::get('/_test/permissions/registrar-implemento', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.manage.equipment']);
        Route::get('/_test/permissions/ajustar-stock', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'can.manage.equipment']);
        Route::get('/_test/permissions/administrador-exclusivo', fn () => response()->json(['ok' => true]))
            ->middleware(['jwt.auth', 'is.administrator']);
    }

    public function test_role_matrix_allows_and_denies_every_operation(): void
    {
        foreach (['administrador', 'recepcionista', 'cajero', 'encargado_inventario'] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();
            $headers = $this->bearerHeaders((int) $user->id);

            foreach ($this->permissions as $operation => $allowedRoles) {
                $response = $this->getJson('/_test/permissions/'.$operation, $headers);

                if (in_array($role, $allowedRoles, true)) {
                    $response->assertOk()->assertExactJson(['ok' => true]);
                } else {
                    $response->assertStatus(403)->assertExactJson([
                        'error' => 'Permisos insuficientes para ejecutar este método',
                    ]);
                    $this->assertSame(self::FORBIDDEN_JSON, $response->getContent());
                }
            }
        }
    }

    public function test_user_without_a_role_is_denied_by_every_protected_operation(): void
    {
        $user = User::factory()->create();
        $headers = $this->bearerHeaders((int) $user->id);

        foreach (array_keys($this->permissions) as $operation) {
            $response = $this->getJson('/_test/permissions/'.$operation, $headers);

            $response->assertStatus(403)->assertExactJson([
                'error' => 'Permisos insuficientes para ejecutar este método',
            ]);
            $this->assertSame(self::FORBIDDEN_JSON, $response->getContent());
        }
    }

    public function test_administrator_only_middleware_allows_only_the_administrator(): void
    {
        foreach (['administrador', 'recepcionista', 'cajero', 'encargado_inventario'] as $role) {
            $user = User::factory()->create();
            $user->forceFill(['role' => $role])->save();
            $response = $this->getJson(
                '/_test/permissions/administrador-exclusivo',
                $this->bearerHeaders((int) $user->id)
            );

            if ($role === 'administrador') {
                $response->assertOk()->assertExactJson(['ok' => true]);
            } else {
                $response->assertStatus(403)->assertExactJson([
                    'error' => 'Permisos insuficientes para ejecutar este método',
                ]);
                $this->assertSame(self::FORBIDDEN_JSON, $response->getContent());
            }
        }
    }

    public function test_every_protected_operation_requires_a_valid_bearer_token(): void
    {
        foreach (array_keys($this->permissions) as $operation) {
            $this->getJson('/_test/permissions/'.$operation)->assertUnauthorized();
            $this->getJson('/_test/permissions/'.$operation, [
                'Authorization' => 'Bearer invalid.token.value',
            ])->assertUnauthorized();
        }

        foreach (['/api/register', '/api/soap/validate-token'] as $existingProtectedPath) {
            $this->postJson($existingProtectedPath)->assertUnauthorized();
            $this->postJson($existingProtectedPath, [], [
                'Authorization' => 'Bearer invalid.token.value',
            ])->assertUnauthorized();
        }

        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    /** @return array<string, string> */
    private function bearerHeaders(int $userId): array
    {
        $issued = app(JwtService::class)->issue($userId);

        return ['Authorization' => 'Bearer '.$issued['token']];
    }
}
