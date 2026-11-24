<?php

namespace Tests\Feature;

use App\Models\Cobro;
use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapConnectionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CobroModuleTest extends TestCase
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

        $this->createLegacyReservationTables();
        $this->runModuleMigrations();
    }

    public function test_administrator_and_receptionist_can_generate_one_cobro_per_reservation(): void
    {
        $administrator = $this->userWithRole('administrador');
        $receptionist = $this->userWithRole('recepcionista');
        $this->createReservation(1);
        $this->createReservation(2);

        $this->postJson('/api/cobros', [
            'reserva_id' => 1,
            'monto' => '45000.00',
            'estado' => 'pagado',
            'created_by_user_id' => 999,
            'updated_by_user_id' => 999,
            'moneda' => 'USD',
        ], $this->bearerHeaders($administrator))->assertCreated()
            ->assertJsonPath('exito', true)
            ->assertJsonPath('datos.estado', 'pendiente')
            ->assertJsonPath('datos.moneda', 'COP');

        $this->postJson('/api/cobros', [
            'reserva_id' => 2,
            'monto' => '45000',
        ], $this->bearerHeaders($receptionist))->assertCreated();

        $this->assertDatabaseHas('cobros', [
            'reserva_id' => 1,
            'estado' => 'pendiente',
            'moneda' => 'COP',
            'created_by_user_id' => $administrator->id,
            'updated_by_user_id' => null,
            'metodo_pago' => null,
        ]);
        $this->assertDatabaseHas('cobros', [
            'reserva_id' => 2,
            'created_by_user_id' => $receptionist->id,
        ]);
        $this->assertDatabaseCount('cobros', 2);
    }

    public function test_cashier_cannot_generate_a_cobro(): void
    {
        $cashier = $this->userWithRole('cajero');
        $this->createReservation(1);

        $this->postJson('/api/cobros', [
            'reserva_id' => 1,
            'monto' => '45000.00',
        ], $this->bearerHeaders($cashier))->assertForbidden()
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);

        $this->assertDatabaseCount('cobros', 0);
    }

    public function test_cobro_generation_rejects_invalid_amount_and_missing_reservation(): void
    {
        $administrator = $this->userWithRole('administrador');
        $this->createReservation(1);
        $headers = $this->bearerHeaders($administrator);

        $this->postJson('/api/cobros', ['reserva_id' => 1, 'monto' => '0'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson('/api/cobros', ['reserva_id' => 1, 'monto' => '10000000000.00'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson('/api/cobros', ['reserva_id' => 1, 'monto' => '1.001'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('monto');
        $this->postJson('/api/cobros', ['reserva_id' => 999, 'monto' => '1.00'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('reserva_id');

        $this->assertDatabaseCount('cobros', 0);
    }

    public function test_existing_reservation_status_is_not_used_as_an_unconfirmed_eligibility_rule(): void
    {
        $administrator = $this->userWithRole('administrador');
        $this->createReservation(1, 'CANCELADA');

        $this->postJson('/api/cobros', [
            'reserva_id' => 1,
            'monto' => '1.00',
        ], $this->bearerHeaders($administrator))->assertCreated();
    }

    public function test_duplicate_reservation_cobro_returns_a_controlled_conflict(): void
    {
        $administrator = $this->userWithRole('administrador');
        $this->createReservation(1);
        $headers = $this->bearerHeaders($administrator);
        $payload = ['reserva_id' => 1, 'monto' => '45000.00'];

        $this->postJson('/api/cobros', $payload, $headers)->assertCreated();
        $this->postJson('/api/cobros', $payload, $headers)->assertStatus(409);

        $this->assertDatabaseCount('cobros', 1);
    }

    public function test_administrator_and_receptionist_can_consult_cobro_with_normal_jwts(): void
    {
        $charge = $this->createCobro(1);
        $soap = Mockery::mock(SoapConnectionService::class);
        $this->app->instance(SoapConnectionService::class, $soap);
        foreach (['administrador', 'recepcionista'] as $role) {
            $actor = $this->userWithRole($role);
            $token = app(JwtService::class)->issue((int) $actor->getKey())['token'];
            $soap->shouldReceive('consultarReservas')
                ->once()
                ->with($token)
                ->andReturn($this->soapReservations());

            $this->getJson('/api/cobros/'.$charge->id, ['Authorization' => 'Bearer '.$token])
                ->assertOk()
                ->assertJsonPath('datos.cobro.id', $charge->id)
                ->assertJsonPath('datos.reserva.id', 1);
        }
    }

    public function test_cashier_consult_uses_private_capability_and_returns_only_the_linked_reservation(): void
    {
        $charge = $this->createCobro(1);
        $cashier = $this->userWithRole('cajero');
        $normalToken = app(JwtService::class)->issue((int) $cashier->getKey())['token'];
        $capability = null;
        $soap = Mockery::mock(SoapConnectionService::class);
        $soap->shouldReceive('consultarReservas')
            ->once()
            ->with(Mockery::on(function (string $token) use (&$capability, $cashier): bool {
                $capability = $token;
                $claims = app(JwtService::class)->validate($token);

                $this->assertSame((string) $cashier->getKey(), $claims['sub']);
                $this->assertSame('soap_capability', $claims['token_use']);
                $this->assertSame('soap:consultarReservas', $claims['scope']);
                $this->assertSame('urn:ReservasCanchas#consultarReservas', $claims['aud']);
                $this->assertLessThanOrEqual(30, (int) $claims['exp'] - (int) $claims['iat']);

                return true;
            }))
            ->andReturn($this->soapReservations());
        $this->app->instance(SoapConnectionService::class, $soap);

        $response = $this->getJson('/api/cobros/'.$charge->id, ['Authorization' => 'Bearer '.$normalToken]);

        $response->assertOk()
            ->assertJsonPath('datos.cobro.reserva_id', 1)
            ->assertJsonPath('datos.reserva.id', 1)
            ->assertJsonMissingPath('datos.reservas.1')
            ->assertJsonMissingPath('datos.cobro.created_by_user_id');
        $this->assertIsString($capability);
        $this->assertNotSame($normalToken, $capability);
        $this->assertStringNotContainsString($capability, $response->getContent());
    }

    public function test_inventory_role_cannot_consult_cobros(): void
    {
        $charge = $this->createCobro(1);
        $inventoryUser = $this->userWithRole('encargado_inventario');

        $this->getJson('/api/cobros/'.$charge->id, $this->bearerHeaders($inventoryUser))
            ->assertForbidden()
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
    }

    public function test_missing_and_invalid_bearer_tokens_cannot_access_cobros(): void
    {
        $this->getJson('/api/cobros/1')->assertUnauthorized();
        $this->getJson('/api/cobros/1', ['Authorization' => 'Bearer invalid'])->assertUnauthorized();
    }

    public function test_payment_can_be_marked_by_administrator_or_cashier_and_final_state_cannot_change(): void
    {
        foreach (['administrador', 'cajero'] as $role) {
            $actor = $this->userWithRole($role);
            $charge = $this->createCobro($role === 'administrador' ? 1 : 2);

            $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
                'estado' => 'pagado',
                'metodo_pago' => 'transferencia',
                'monto' => '1.00',
                'updated_by_user_id' => 999,
            ], $this->bearerHeaders($actor))->assertOk()
                ->assertJsonPath('datos.estado', 'pagado')
                ->assertJsonMissingPath('datos.updated_by_user_id');

            $this->assertDatabaseHas('cobros', [
                'id' => $charge->id,
                'estado' => 'pagado',
                'metodo_pago' => 'transferencia',
                'monto' => '45000.00',
                'updated_by_user_id' => $actor->id,
            ]);
            $this->assertNotNull(Cobro::query()->findOrFail($charge->id)->fecha_pago);

            $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
                'estado' => 'pagado',
                'metodo_pago' => 'transferencia',
            ], $this->bearerHeaders($actor))->assertStatus(409);
        }
    }

    public function test_payment_requires_a_supported_method(): void
    {
        $actor = $this->userWithRole('cajero');
        $charge = $this->createCobro(1);

        $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
            'estado' => 'pagado',
        ], $this->bearerHeaders($actor))->assertUnprocessable()->assertJsonValidationErrors('metodo_pago');
        $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
            'estado' => 'pagado',
            'metodo_pago' => 'cheque',
        ], $this->bearerHeaders($actor))->assertUnprocessable()->assertJsonValidationErrors('metodo_pago');

        $this->assertDatabaseHas('cobros', ['id' => $charge->id, 'estado' => 'pendiente']);
    }

    public function test_receptionist_and_inventory_manager_cannot_update_cobro_status(): void
    {
        $charge = $this->createCobro(1);

        foreach (['recepcionista', 'encargado_inventario'] as $role) {
            $actor = $this->userWithRole($role);
            $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
                'estado' => 'pagado',
                'metodo_pago' => 'efectivo',
            ], $this->bearerHeaders($actor))->assertForbidden();
        }

        $this->assertDatabaseHas('cobros', ['id' => $charge->id, 'estado' => 'pendiente']);
    }

    public function test_only_administrator_can_cancel_a_pending_cobro(): void
    {
        $cashier = $this->userWithRole('cajero');
        $charge = $this->createCobro(1);

        $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
            'estado' => 'anulado',
        ], $this->bearerHeaders($cashier))->assertForbidden()
            ->assertExactJson(['error' => 'Permisos insuficientes para ejecutar este método']);
        $this->assertDatabaseHas('cobros', ['id' => $charge->id, 'estado' => 'pendiente']);

        $administrator = $this->userWithRole('administrador');
        $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
            'estado' => 'anulado',
        ], $this->bearerHeaders($administrator))->assertOk()->assertJsonPath('datos.estado', 'anulado');
    }

    public function test_invalid_cobro_transition_returns_conflict_without_changing_the_charge(): void
    {
        $actor = $this->userWithRole('administrador');
        $charge = $this->createCobro(1);

        $this->patchJson('/api/cobros/'.$charge->id.'/estado', [
            'estado' => 'pendiente',
        ], $this->bearerHeaders($actor))->assertStatus(409);

        $this->assertDatabaseHas('cobros', ['id' => $charge->id, 'estado' => 'pendiente']);
    }

    public function test_soap_failure_or_missing_reservation_returns_controlled_gateway_error(): void
    {
        $actor = $this->userWithRole('administrador');
        $charge = $this->createCobro(1);
        $soap = Mockery::mock(SoapConnectionService::class);
        $soap->shouldReceive('consultarReservas')->once()->andReturn(['exito' => true, 'datos' => []]);
        $this->app->instance(SoapConnectionService::class, $soap);

        $this->getJson('/api/cobros/'.$charge->id, $this->bearerHeaders($actor))
            ->assertStatus(502)
            ->assertJsonPath('exito', false);
    }

    public function test_missing_cobro_returns_json_404(): void
    {
        $actor = $this->userWithRole('administrador');

        $this->getJson('/api/cobros/999', $this->bearerHeaders($actor))
            ->assertNotFound()
            ->assertJsonPath('exito', false);
    }

    private function createLegacyReservationTables(): void
    {
        Schema::create('canchas', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('codigo', 20)->unique();
            $table->string('nombre', 100);
            $table->string('tipo', 50);
            $table->string('estado', 20);
        });
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('documento', 20)->unique();
            $table->string('nombre', 100);
            $table->string('telefono', 20);
        });
        Schema::create('reservas', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('cancha_id');
            $table->integer('usuario_id');
            $table->date('fecha');
            $table->time('hora');
            $table->string('estado', 20);
        });
    }

    private function runModuleMigrations(): void
    {
        foreach ([
            '2026_11_23_203427_create_cobros_table.php',
            '2026_11_23_203428_create_implementos_table.php',
            '2026_11_23_203429_create_movimientos_inventario_table.php',
        ] as $migrationFile) {
            $migration = require database_path('migrations/'.$migrationFile);
            $migration->up();
        }
    }

    private function createReservation(int $id, string $status = 'CONFIRMADA'): void
    {
        DB::table('canchas')->insertOrIgnore([
            'id' => 1,
            'codigo' => 'CAN-001',
            'nombre' => 'Cancha Principal',
            'tipo' => 'Fútbol',
            'estado' => 'ACTIVA',
        ]);
        DB::table('usuarios')->insertOrIgnore([
            'id' => 1,
            'documento' => '1001001001',
            'nombre' => 'Usuario de prueba',
            'telefono' => '3001234567',
        ]);
        DB::table('reservas')->insert([
            'id' => $id,
            'cancha_id' => 1,
            'usuario_id' => 1,
            'fecha' => '2027-01-10',
            'hora' => sprintf('%02d:00:00', 8 + $id),
            'estado' => $status,
        ]);
    }

    private function createCobro(int $reservationId): Cobro
    {
        $actor = $this->userWithRole('administrador');
        if (! Schema::hasTable('reservas') || DB::table('reservas')->where('id', $reservationId)->doesntExist()) {
            $this->createReservation($reservationId);
        }

        return Cobro::query()->create([
            'reserva_id' => $reservationId,
            'monto' => '45000.00',
            'moneda' => 'COP',
            'estado' => 'pendiente',
            'fecha_generacion' => now(),
            'created_by_user_id' => $actor->getKey(),
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

    /** @return array{exito: bool, datos: list<array<string, int|string>>} */
    private function soapReservations(): array
    {
        return [
            'exito' => true,
            'datos' => [
                ['id' => 1, 'cancha_id' => 1, 'usuario_id' => 1, 'fecha' => '2027-01-10', 'hora' => '09:00:00', 'estado' => 'CONFIRMADA'],
                ['id' => 2, 'cancha_id' => 1, 'usuario_id' => 1, 'fecha' => '2027-01-10', 'hora' => '10:00:00', 'estado' => 'CONFIRMADA'],
            ],
        ];
    }
}
