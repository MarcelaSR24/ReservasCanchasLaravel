<?php

namespace Tests\Feature;

use App\Services\JwtService;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Tests\TestCase;

class JwtSoapValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => str_repeat('j', 64),
            'jwt.algorithm' => 'HS256',
            'jwt.issuer' => 'http://testing.local',
            'jwt.ttl' => 30,
            'services.soap.security_username' => 'soap-test-user',
            'services.soap.security_password' => 'soap-test-password',
            'cache.default' => 'array',
        ]);
    }

    public function test_wsdl_lists_jwt_validation_and_existing_operations(): void
    {
        $response = $this->get('/soap?wsdl')->assertOk();

        $response->assertSee('ValidateTokenService', false)
            ->assertSee('registrarCancha', false)
            ->assertSee('consultarReservas', false);
    }

    public function test_soap_rejects_post_without_ws_security(): void
    {
        $response = $this->call('POST', '/soap', [], [], [], ['CONTENT_TYPE' => 'text/xml'], '<Envelope/>');

        $response->assertStatus(500)->assertSee('WS-Security', false);
    }

    public function test_soap_accepts_a_valid_hs256_token_and_keeps_username_token(): void
    {
        $issued = app(JwtService::class)->issue(7);
        $response = $this->postSoapToken($issued['token']);

        $response->assertOk()->assertSee('&quot;exito&quot;:true', false)->assertSee('&quot;usuario_id&quot;:7', false);
    }

    public function test_soap_rejects_altered_expired_and_empty_tokens(): void
    {
        $valid = app(JwtService::class)->issue(7)['token'];
        $segments = explode('.', $valid);
        $segments[2] = ($segments[2][0] === 'A' ? 'B' : 'A').substr($segments[2], 1);
        $altered = implode('.', $segments);
        $expired = JWT::encode([
            'iss' => 'http://testing.local',
            'sub' => '7',
            'iat' => time() - 120,
            'nbf' => time() - 120,
            'exp' => time() - 1,
            'jti' => (string) Str::uuid(),
        ], str_repeat('j', 64), 'HS256');

        foreach ([$altered, $expired, ''] as $token) {
            $response = $this->postSoapToken($token);
            $response->assertOk()->assertSee('&quot;exito&quot;:false', false);
        }
    }

    private function postSoapToken(string $token)
    {
        $securityNamespace = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $passwordType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
        $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
            .'xmlns:urn="urn:ReservasCanchas" '
            .'xmlns:wsse="'.$securityNamespace.'">'
            .'<soapenv:Header><wsse:Security><wsse:UsernameToken>'
            .'<wsse:Username>soap-test-user</wsse:Username>'
            .'<wsse:Password Type="'.$passwordType.'">soap-test-password</wsse:Password>'
            .'</wsse:UsernameToken></wsse:Security></soapenv:Header>'
            .'<soapenv:Body><urn:ValidateTokenService><token>'.htmlspecialchars($token, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</token></urn:ValidateTokenService></soapenv:Body></soapenv:Envelope>';

        return $this->call('POST', '/soap', [], [], [], [
            'CONTENT_TYPE' => 'text/xml; charset=UTF-8',
            'HTTP_SOAPACTION' => '"urn:ReservasCanchas#ValidateTokenService"',
        ], $xml);
    }
}
