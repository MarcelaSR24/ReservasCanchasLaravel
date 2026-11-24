<?php

namespace Tests\Feature;

use App\Models\Implemento;
use App\Models\MovimientoInventario;
use App\Models\User;
use App\Repositories\MovimientoInventarioRepository;
use App\Services\JwtService;
use RuntimeException;
use Tests\TestCase;

class InventarioModuleTest extends TestCase
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

    public function test_inventory_manager_can_register_an_entry_with_audited_snapshot(): void
    {
        $actor = $this->userWithRole('encargado_inventario');
        $implemento = $this->createImplemento(3);

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'entrada',
            'cantidad' => 2,
            'motivo' => 'Reposición recibida',
        ], $this->bearerHeaders($actor))->assertOk()
            ->assertJsonPath('datos.implemento.cantidad_disponible', 5)
            ->assertJsonPath('datos.movimiento.cantidad_anterior', 3)
            ->assertJsonPath('datos.movimiento.cantidad_posterior', 5)
            ->assertJsonPath('datos.movimiento.user_id', $actor->id);

        $this->assertDatabaseHas('implementos', ['id' => $implemento->id, 'cantidad_disponible' => 5]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'implemento_id' => $implemento->id,
            'user_id' => $actor->id,
            'tipo_movimiento' => 'entrada',
            'cantidad' => 2,
            'cantidad_anterior' => 3,
            'cantidad_posterior' => 5,
            'motivo' => 'Reposición recibida',
        ]);
        $this->assertNotNull(MovimientoInventario::query()->firstOrFail()->created_at);
    }

    public function test_administrator_can_register_a_stock_exit_down_to_zero(): void
    {
        $actor = $this->userWithRole('administrador');
        $implemento = $this->createImplemento(2);

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'salida',
            'cantidad' => 2,
            'motivo' => 'Entrega de implementos',
        ], $this->bearerHeaders($actor))->assertOk()
            ->assertJsonPath('datos.implemento.cantidad_disponible', 0);

        $this->assertDatabaseHas('implementos', [
            'id' => $implemento->id,
            'cantidad_disponible' => 0,
            'estado' => 'activo',
        ]);
        $this->assertDatabaseHas('movimientos_inventario', [
            'cantidad_anterior' => 2,
            'cantidad_posterior' => 0,
            'tipo_movimiento' => 'salida',
        ]);
    }

    public function test_stock_exit_above_available_quantity_returns_conflict_without_audit_row(): void
    {
        $actor = $this->userWithRole('administrador');
        $implemento = $this->createImplemento(1);

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'salida',
            'cantidad' => 2,
            'motivo' => 'No debe registrarse',
        ], $this->bearerHeaders($actor))->assertStatus(409);

        $this->assertDatabaseHas('implementos', ['id' => $implemento->id, 'cantidad_disponible' => 1]);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_adjustment_rejects_zero_and_negative_quantities(): void
    {
        $actor = $this->userWithRole('administrador');
        $implemento = $this->createImplemento(3);
        $headers = $this->bearerHeaders($actor);

        foreach ([0, -1] as $quantity) {
            $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
                'tipo_movimiento' => 'entrada',
                'cantidad' => $quantity,
                'motivo' => 'Cantidad inválida',
            ], $headers)->assertUnprocessable()->assertJsonValidationErrors('cantidad');
        }

        $this->assertDatabaseHas('implementos', ['id' => $implemento->id, 'cantidad_disponible' => 3]);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_adjustment_requires_a_supported_movement_type_and_nonempty_reason(): void
    {
        $actor = $this->userWithRole('administrador');
        $implemento = $this->createImplemento(3);
        $headers = $this->bearerHeaders($actor);

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'correccion',
            'cantidad' => 1,
            'motivo' => 'Motivo',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('tipo_movimiento');
        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'entrada',
            'cantidad' => 1,
            'motivo' => '',
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('motivo');

        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_cashier_cannot_adjust_inventory(): void
    {
        $cashier = $this->userWithRole('cajero');
        $implemento = $this->createImplemento(3);

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'entrada',
            'cantidad' => 1,
            'motivo' => 'No autorizado',
        ], $this->bearerHeaders($cashier))->assertForbidden()
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);

        $this->assertDatabaseHas('implementos', ['id' => $implemento->id, 'cantidad_disponible' => 3]);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_transaction_rolls_back_stock_when_movement_creation_fails(): void
    {
        $actor = $this->userWithRole('administrador');
        $implemento = $this->createImplemento(3);
        $repository = $this->mock(MovimientoInventarioRepository::class);
        $repository->shouldReceive('create')->once()->andThrow(new RuntimeException('Fallo controlado'));

        $this->postJson('/api/implementos/'.$implemento->id.'/ajustar-stock', [
            'tipo_movimiento' => 'entrada',
            'cantidad' => 2,
            'motivo' => 'Prueba de rollback',
        ], $this->bearerHeaders($actor))->assertServerError();

        $this->assertDatabaseHas('implementos', ['id' => $implemento->id, 'cantidad_disponible' => 3]);
        $this->assertDatabaseCount('movimientos_inventario', 0);
    }

    public function test_missing_implemento_returns_json_404(): void
    {
        $actor = $this->userWithRole('administrador');

        $this->postJson('/api/implementos/999/ajustar-stock', [
            'tipo_movimiento' => 'entrada',
            'cantidad' => 1,
            'motivo' => 'Implemento inexistente',
        ], $this->bearerHeaders($actor))->assertNotFound()
            ->assertJsonPath('exito', false);
    }

    private function createImplemento(int $quantity): Implemento
    {
        return Implemento::query()->create([
            'codigo' => 'BAL-01',
            'nombre' => 'Balón',
            'categoria' => 'deportes',
            'cantidad_disponible' => $quantity,
            'estado' => 'activo',
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
