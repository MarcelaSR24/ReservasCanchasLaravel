<?php

namespace Tests\Feature;

use App\Models\Implemento;
use App\Models\User;
use App\Services\JwtService;
use Tests\TestCase;

class ImplementoModuleTest extends TestCase
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

        $implementos = require database_path('migrations/2026_11_23_203428_create_implementos_table.php');
        $implementos->up();
        $movimientos = require database_path('migrations/2026_11_23_203429_create_movimientos_inventario_table.php');
        $movimientos->up();
    }

    public function test_administrator_and_inventory_manager_can_register_implementos(): void
    {
        foreach (['administrador', 'encargado_inventario'] as $index => $role) {
            $actor = $this->userWithRole($role);
            $code = 'BAL-0'.($index + 1);

            $this->postJson('/api/implementos', [
                'codigo' => $code,
                'nombre' => 'Balón',
                'categoria' => 'deportes',
                'descripcion' => 'Balón de entrenamiento',
                'cantidad_disponible' => 4,
                'estado' => 'activo',
                'ubicacion' => 'Bodega principal',
                'created_by_user_id' => 999,
            ], $this->bearerHeaders($actor))->assertCreated()
                ->assertJsonPath('datos.codigo', $code)
                ->assertJsonPath('datos.cantidad_disponible', 4);

            $this->assertDatabaseHas('implementos', [
                'codigo' => $code,
                'estado' => 'activo',
                'cantidad_disponible' => 4,
            ]);
        }

        $this->assertDatabaseCount('implementos', 2);
    }

    public function test_receptionist_and_cashier_cannot_register_implementos(): void
    {
        foreach (['recepcionista', 'cajero'] as $role) {
            $actor = $this->userWithRole($role);

            $this->postJson('/api/implementos', [
                'codigo' => 'BAL-01',
                'nombre' => 'Balón',
                'categoria' => 'deportes',
                'cantidad_disponible' => 4,
            ], $this->bearerHeaders($actor))->assertForbidden()
                ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
        }

        $this->assertDatabaseCount('implementos', 0);
    }

    public function test_administrator_receptionist_and_inventory_manager_can_list_implementos(): void
    {
        $this->createImplemento('BAL-01', 0, 'activo');

        foreach (['administrador', 'recepcionista', 'encargado_inventario'] as $role) {
            $actor = $this->userWithRole($role);

            $this->getJson('/api/implementos', $this->bearerHeaders($actor))
                ->assertOk()
                ->assertJsonPath('datos.0.codigo', 'BAL-01')
                ->assertJsonPath('datos.0.cantidad_disponible', 0)
                ->assertJsonPath('datos.0.estado', 'activo');
        }
    }

    public function test_cashier_cannot_list_implementos(): void
    {
        $cashier = $this->userWithRole('cajero');

        $this->getJson('/api/implementos', $this->bearerHeaders($cashier))->assertForbidden()
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
    }

    public function test_duplicate_implemento_code_returns_conflict_without_increasing_stock(): void
    {
        $actor = $this->userWithRole('administrador');
        $this->createImplemento('BAL-01', 4);
        $payload = [
            'codigo' => 'BAL-01',
            'nombre' => 'Balón duplicado',
            'categoria' => 'deportes',
            'cantidad_disponible' => 9,
        ];

        $this->postJson('/api/implementos', $payload, $this->bearerHeaders($actor))->assertStatus(409);

        $this->assertDatabaseCount('implementos', 1);
        $this->assertDatabaseHas('implementos', ['codigo' => 'BAL-01', 'cantidad_disponible' => 4]);
    }

    public function test_registration_rejects_negative_quantity_and_unknown_state(): void
    {
        $actor = $this->userWithRole('administrador');
        $headers = $this->bearerHeaders($actor);
        $payload = [
            'codigo' => 'BAL-01',
            'nombre' => 'Balón',
            'categoria' => 'deportes',
            'cantidad_disponible' => -1,
        ];

        $this->postJson('/api/implementos', $payload, $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('cantidad_disponible');
        $this->postJson('/api/implementos', [...$payload, 'cantidad_disponible' => 1, 'estado' => 'agotado'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('estado');

        $this->assertDatabaseCount('implementos', 0);
    }

    public function test_zero_quantity_is_allowed_and_status_does_not_change_automatically(): void
    {
        $actor = $this->userWithRole('administrador');
        $this->postJson('/api/implementos', [
            'codigo' => 'BAL-01',
            'nombre' => 'Balón',
            'categoria' => 'deportes',
            'cantidad_disponible' => 0,
        ], $this->bearerHeaders($actor))->assertCreated()
            ->assertJsonPath('datos.estado', 'activo');

        $this->assertDatabaseHas('implementos', [
            'codigo' => 'BAL-01',
            'cantidad_disponible' => 0,
            'estado' => 'activo',
        ]);
    }

    public function test_missing_and_invalid_bearer_tokens_cannot_list_implementos(): void
    {
        $this->getJson('/api/implementos')->assertUnauthorized();
        $this->getJson('/api/implementos', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }

    private function createImplemento(string $code, int $quantity, string $status = 'activo'): Implemento
    {
        return Implemento::query()->create([
            'codigo' => $code,
            'nombre' => 'Balón',
            'categoria' => 'deportes',
            'cantidad_disponible' => $quantity,
            'estado' => $status,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    /** @return array<string, string> */
    private function bearerHeaders(User $user): array
    {
        $token = app(JwtService::class)->issue((int) $user->getKey())['token'];

        return ['Authorization' => 'Bearer '.$token];
    }
}
