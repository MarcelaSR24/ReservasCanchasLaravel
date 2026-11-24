<?php

namespace Tests\Feature;

use App\Models\Cancha;
use App\Models\Usuario;
use App\Services\CanchaService;
use App\Services\JwtService;
use App\Services\ReservaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SoapBusinessRulesTest extends TestCase
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

        Schema::create('canchas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('tipo');
            $table->string('estado');
        });

        Schema::create('usuarios', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('documento')->unique();
            $table->string('nombre');
            $table->string('telefono');
        });

        Schema::create('reservas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('cancha_id');
            $table->unsignedInteger('usuario_id');
            $table->date('fecha');
            $table->time('hora');
            $table->string('estado');
            $table->unique(['cancha_id', 'fecha', 'hora']);
        });
    }

    public function test_existing_court_registration_and_duplicate_rule_work(): void
    {
        $service = app(CanchaService::class);
        $data = ['codigo' => 'CAN-TEST', 'nombre' => 'Principal', 'tipo' => 'Fútbol', 'estado' => 'ACTIVA'];

        $this->assertInstanceOf(Cancha::class, $service->registrarCancha($data));

        try {
            $service->registrarCancha($data);
            $this->fail('Se aceptó un código de cancha duplicado.');
        } catch (\Exception $exception) {
            $this->assertSame('La cancha ya existe con ese código', $exception->getMessage());
        }
    }

    public function test_soap_court_operation_requires_jwt_and_calls_the_existing_service(): void
    {
        require_once app_path('Soap/SoapFunctions.php');

        $rejected = json_decode(registrarCancha('', 'CAN-SOAP', 'Cancha SOAP', 'Fútbol', 'ACTIVA'), true);
        $this->assertFalse($rejected['exito']);
        $this->assertDatabaseMissing('canchas', ['codigo' => 'CAN-SOAP']);

        $token = app(JwtService::class)->issue(1)['token'];
        $accepted = json_decode(registrarCancha($token, 'CAN-SOAP', 'Cancha SOAP', 'Fútbol', 'ACTIVA'), true);
        $this->assertTrue($accepted['exito']);
        $this->assertDatabaseHas('canchas', ['codigo' => 'CAN-SOAP']);
    }

    public function test_existing_reservation_rules_reject_inactive_courts_and_missing_users(): void
    {
        $inactiveCourt = Cancha::query()->create([
            'codigo' => 'CAN-OFF', 'nombre' => 'Cerrada', 'tipo' => 'Tenis', 'estado' => 'INACTIVA',
        ]);
        Usuario::query()->create(['documento' => '10000001', 'nombre' => 'Test', 'telefono' => '3000000000']);

        try {
            $this->reserve((int) $inactiveCourt->id, 1);
            $this->fail('Se aceptó una reserva en una cancha inactiva.');
        } catch (\Exception $exception) {
            $this->assertSame('La cancha está inactiva', $exception->getMessage());
        }

        $activeCourt = Cancha::query()->create([
            'codigo' => 'CAN-ON', 'nombre' => 'Abierta', 'tipo' => 'Fútbol', 'estado' => 'ACTIVA',
        ]);

        try {
            $this->reserve((int) $activeCourt->id, 999);
            $this->fail('Se aceptó una reserva para un usuario inexistente.');
        } catch (\Exception $exception) {
            $this->assertSame('El usuario no existe', $exception->getMessage());
        }
    }

    public function test_existing_reservation_rule_rejects_same_court_date_and_time(): void
    {
        $court = Cancha::query()->create([
            'codigo' => 'CAN-ON', 'nombre' => 'Abierta', 'tipo' => 'Fútbol', 'estado' => 'ACTIVA',
        ]);
        Usuario::query()->create(['documento' => '10000001', 'nombre' => 'Test', 'telefono' => '3000000000']);

        $this->reserve((int) $court->id, 1);

        try {
            $this->reserve((int) $court->id, 1);
            $this->fail('Se aceptó una reserva duplicada.');
        } catch (\Exception $exception) {
            $this->assertSame('La cancha ya tiene una reserva para esa fecha y hora', $exception->getMessage());
        }
    }

    /** @return array<string, int|string> */
    private function reserve(int $courtId, int $userId): array
    {
        return app(ReservaService::class)->registrarReserva([
            'cancha_id' => $courtId,
            'usuario_id' => $userId,
            'fecha' => '2027-02-10',
            'hora' => '10:00:00',
            'estado' => 'CONFIRMADA',
        ])->toArray();
    }
}
