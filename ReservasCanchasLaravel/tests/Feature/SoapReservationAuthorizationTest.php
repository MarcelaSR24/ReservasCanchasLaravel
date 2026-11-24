<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\JwtService;
use App\Services\SoapReservationAuthorizationService;
use Firebase\JWT\JWT;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SoapReservationAuthorizationTest extends TestCase
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
            'services.soap.security_username' => 'soap-test-user',
            'services.soap.security_password' => 'soap-test-password',
        ]);

        Schema::create('canchas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('tipo');
            $table->string('estado');
        });

        Schema::create('reservas', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('cancha_id');
            $table->unsignedInteger('usuario_id');
            $table->date('fecha');
            $table->time('hora');
            $table->string('estado');
        });

        Route::post('/_test/consultar-cobro', function (Request $request, SoapReservationAuthorizationService $authorization) {
            $capability = $authorization->issueForAuthorizedCobro($request);
            app()->instance('test.soap_reservation_capability', $capability);

            return response()->json(['autorizado' => true]);
        })->name('api.cobros.consultar')->middleware(['jwt.auth', 'can.consult.billing']);
    }

    public function test_administrator_and_receptionist_can_use_a_normal_jwt(): void
    {
        foreach (['administrador', 'recepcionista'] as $role) {
            $user = $this->userWithRole($role);
            $response = $this->postSoapOperation('consultarReservas', $this->normalToken($user));

            $response->assertOk()->assertSee('&quot;exito&quot;:true', false);
            $this->assertStringNotContainsString($this->normalToken($user), $response->getContent());
        }
    }

    public function test_cashier_normal_jwt_is_rejected_by_direct_soap_call(): void
    {
        $cashier = $this->userWithRole('cajero');
        $response = $this->postSoapOperation('consultarReservas', $this->normalToken($cashier));

        $response->assertOk()->assertSee('&quot;exito&quot;:false', false);
    }

    public function test_cashier_can_use_a_temporary_capability_issued_only_in_authorized_cobro_flow(): void
    {
        $cashier = $this->userWithRole('cajero');
        $response = $this->postJson('/_test/consultar-cobro', [], $this->bearerHeaders($cashier));
        $response->assertOk()->assertExactJson(['autorizado' => true]);

        $capability = app('test.soap_reservation_capability');
        $this->assertIsString($capability);
        $this->assertStringNotContainsString($capability, $response->getContent());
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$capability])->assertUnauthorized();

        $soapResponse = $this->postSoapOperation('consultarReservas', $capability);
        $soapResponse->assertOk()->assertSee('&quot;exito&quot;:true', false);
        $this->assertStringNotContainsString($capability, $soapResponse->getContent());
    }

    public function test_cashier_capability_is_rejected_when_reused(): void
    {
        $capability = $this->issueCashierCapability();

        $this->postSoapOperation('consultarReservas', $capability)
            ->assertOk()->assertSee('&quot;exito&quot;:true', false);
        $this->postSoapOperation('consultarReservas', $capability)
            ->assertOk()->assertSee('&quot;exito&quot;:false', false);
    }

    public function test_inventory_role_cannot_consult_reservations(): void
    {
        $inventoryUser = $this->userWithRole('encargado_inventario');

        $this->postSoapOperation('consultarReservas', $this->normalToken($inventoryUser))
            ->assertOk()->assertSee('&quot;exito&quot;:false', false);
    }

    public function test_expired_altered_wrong_audience_and_wrong_scope_capabilities_are_rejected(): void
    {
        $cashier = $this->userWithRole('cajero');
        $expired = $this->signedCapability($cashier, [
            'iat' => time() - 60,
            'nbf' => time() - 60,
            'exp' => time() - 1,
        ]);
        $valid = $this->signedCapability($cashier);
        $segments = explode('.', $valid);
        $segments[2] = ($segments[2][0] === 'A' ? 'B' : 'A').substr($segments[2], 1);
        $altered = implode('.', $segments);
        $wrongAudience = $this->signedCapability($cashier, ['aud' => 'urn:wrong-service']);
        $wrongScope = $this->signedCapability($cashier, ['scope' => 'soap:registrarCancha']);

        foreach ([$expired, $altered, $wrongAudience, $wrongScope] as $token) {
            $this->postSoapOperation('consultarReservas', $token)
                ->assertOk()->assertSee('&quot;exito&quot;:false', false);
        }
    }

    public function test_deleted_user_cannot_use_a_temporary_capability(): void
    {
        $cashier = $this->userWithRole('cajero');
        $capability = $this->signedCapability($cashier);
        $cashier->delete();

        $this->postSoapOperation('consultarReservas', $capability)
            ->assertOk()->assertSee('&quot;exito&quot;:false', false);
    }

    public function test_capability_cannot_be_issued_without_the_authorized_cobro_request_context(): void
    {
        $cashier = $this->userWithRole('cajero');
        $request = Request::create('/api/soap/reservation-capability', 'POST');
        $request->setUserResolver(static fn () => $cashier);

        try {
            app(SoapReservationAuthorizationService::class)->issueForAuthorizedCobro($request);
            $this->fail('Se emitió una credencial sin el contexto autorizado de consultarCobro.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->postJson('/api/soap/reservation-capability')->assertNotFound();
    }

    public function test_soap_still_requires_username_token_and_rejects_wrong_credentials(): void
    {
        $token = $this->normalToken($this->userWithRole('administrador'));

        $this->call('POST', '/soap', [], [], [], ['CONTENT_TYPE' => 'text/xml'], '<Envelope/>')
            ->assertStatus(500)->assertSee('WS-Security', false);

        $this->postSoapOperation(
            'consultarReservas',
            $token,
            [],
            username: 'wrong-user',
            password: 'wrong-password'
        )
            ->assertStatus(500)->assertSee('incorrecto', false);
    }

    public function test_existing_soap_operations_and_wsdl_remain_available(): void
    {
        $this->get('/soap?wsdl')->assertOk()
            ->assertSee('registrarCancha', false)
            ->assertSee('consultarCancha', false)
            ->assertSee('listarCanchas', false)
            ->assertSee('actualizarCancha', false)
            ->assertSee('registrarReserva', false)
            ->assertSee('consultarReservas', false);

        $inventoryUser = $this->userWithRole('encargado_inventario');
        require_once app_path('Soap/SoapFunctions.php');
        $capability = $this->issueCashierCapability();
        $capabilityUse = json_decode(registrarCancha(
            $capability,
            'SOAP-CAPABILITY-REJECTED',
            'No debe registrarse',
            'Fútbol',
            'ACTIVA'
        ), true);
        $this->assertFalse($capabilityUse['exito']);
        $this->assertDatabaseMissing('canchas', ['codigo' => 'SOAP-CAPABILITY-REJECTED']);

        $result = json_decode(registrarCancha(
            $this->normalToken($inventoryUser),
            'SOAP-ROLE-TEST',
            'Cancha compatible',
            'Fútbol',
            'ACTIVA'
        ), true);

        $this->assertTrue($result['exito']);
        $this->assertDatabaseHas('canchas', ['codigo' => 'SOAP-ROLE-TEST']);
    }

    public function test_file_cache_allows_only_one_of_two_concurrent_consumers(): void
    {
        $jti = (string) Str::uuid();
        $expiresAt = time() + 30;
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'soap-capability-'.Str::uuid();
        mkdir($directory);

        $gate = $directory.DIRECTORY_SEPARATOR.'release';
        $autoload = base_path('vendor/autoload.php');
        $processes = [];
        $resultFiles = [];

        try {
            for ($index = 0; $index < 2; $index++) {
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $result = $directory.DIRECTORY_SEPARATOR.'result-'.$index;
                $resultFiles[] = $result;
                $script = sprintf(
                    'require %s; $repository = new \\Illuminate\\Cache\\Repository(new \\Illuminate\\Cache\\FileStore(new \\Illuminate\\Filesystem\\Filesystem(), %s)); $store = new \\App\\Services\\SoapReservationCapabilityStore($repository); touch(%s); $deadline = microtime(true) + 15; while (! file_exists(%s) && microtime(true) < $deadline) { usleep(1000); } if (! file_exists(%s)) { exit(2); } file_put_contents(%s, $store->consume(%s, %d) ? "1" : "0");',
                    var_export($autoload, true),
                    var_export($directory.DIRECTORY_SEPARATOR.'cache', true),
                    var_export($ready, true),
                    var_export($gate, true),
                    var_export($gate, true),
                    var_export($result, true),
                    var_export($jti, true),
                    $expiresAt,
                );

                $process = new Process([PHP_BINARY, '-r', $script], base_path());
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            while (count(glob($directory.DIRECTORY_SEPARATOR.'ready-*') ?: []) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($directory.DIRECTORY_SEPARATOR.'ready-*') ?: [], 'Both workers must reach the shared barrier.');
            touch($gate);

            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            }

            $results = array_map(static fn (string $path): string => (string) file_get_contents($path), $resultFiles);
            sort($results);
            $this->assertSame(['0', '1'], $results);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function issueCashierCapability(): string
    {
        $cashier = $this->userWithRole('cajero');
        $response = $this->postJson('/_test/consultar-cobro', [], $this->bearerHeaders($cashier));
        $response->assertOk()->assertExactJson(['autorizado' => true]);

        return app('test.soap_reservation_capability');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function normalToken(User $user): string
    {
        return app(JwtService::class)->issue((int) $user->getKey())['token'];
    }

    /** @return array<string, string> */
    private function bearerHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->normalToken($user)];
    }

    /** @param array<string, mixed> $overrides */
    private function signedCapability(User $user, array $overrides = []): string
    {
        $now = time();
        $claims = array_replace([
            'iss' => 'http://testing.local',
            'sub' => (string) $user->getKey(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 30,
            'jti' => (string) Str::uuid(),
            'token_use' => 'soap_capability',
            'scope' => 'soap:consultarReservas',
            'aud' => 'urn:ReservasCanchas#consultarReservas',
        ], $overrides);

        return JWT::encode($claims, str_repeat('j', 64), 'HS256', null, ['typ' => 'JWT']);
    }

    /** @param array<string, string> $arguments */
    private function postSoapOperation(
        string $operation,
        string $token,
        array $arguments = [],
        string $username = 'soap-test-user',
        string $password = 'soap-test-password',
    ) {
        $securityNamespace = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $passwordType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
        $bodyArguments = '<token>'.htmlspecialchars($token, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</token>';
        foreach ($arguments as $name => $value) {
            $bodyArguments .= '<'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8').'>';
        }

        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            .'xmlns:urn="urn:ReservasCanchas" xmlns:wsse="'.$securityNamespace.'">'
            .'<soapenv:Header><wsse:Security><wsse:UsernameToken>'
            .'<wsse:Username>'.htmlspecialchars($username, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</wsse:Username>'
            .'<wsse:Password Type="'.$passwordType.'">'.htmlspecialchars($password, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</wsse:Password>'
            .'</wsse:UsernameToken></wsse:Security></soapenv:Header>'
            .'<soapenv:Body><urn:'.$operation.'>'.$bodyArguments.'</urn:'.$operation.'></soapenv:Body></soapenv:Envelope>';

        return $this->call('POST', '/soap', [], [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
            'HTTP_SOAPACTION' => '"urn:ReservasCanchas#'.$operation.'"',
        ], $xml);
    }
}
