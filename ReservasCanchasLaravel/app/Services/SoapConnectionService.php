<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class SoapConnectionService
{
    /** Internal call; callers must never include the supplied token in REST output or logs. */
    public function consultarReservas(string $token): array
    {
        if (! extension_loaded('soap')) {
            throw new RuntimeException('La extensión SOAP de PHP no está habilitada.');
        }

        $endpoint = (string) config('services.soap.endpoint');
        $username = (string) config('services.soap.security_username');
        $password = (string) config('services.soap.security_password');
        if ($endpoint === '' || $username === '' || $password === '') {
            throw new RuntimeException('Configura SOAP_ENDPOINT y las credenciales WS-Security.');
        }

        try {
            $wsdl = str_contains($endpoint, 'wsdl')
                ? $endpoint
                : $endpoint.(str_contains($endpoint, '?') ? '&' : '?').'wsdl';
            $client = new UsernameTokenSoapClient($wsdl, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => 5,
            ], $username, $password);
            $result = $client->__soapCall('consultarReservas', ['token' => $token]);
        } catch (Throwable) {
            throw new RuntimeException('El servicio SOAP no pudo completar la consulta de reservas.');
        }

        $payload = is_object($result) && isset($result->return) ? $result->return : $result;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($payload)) {
            return $payload;
        }

        return ['exito' => false, 'mensaje' => 'SOAP devolvió una respuesta inesperada.'];
    }

    /** @return array<string, mixed> */
    public function validateToken(string $token): array
    {
        if (! extension_loaded('soap')) {
            throw new RuntimeException('La extensión SOAP de PHP no está habilitada.');
        }

        $endpoint = (string) config('services.soap.endpoint');
        $username = (string) config('services.soap.security_username');
        $password = (string) config('services.soap.security_password');
        if ($endpoint === '' || $username === '' || $password === '') {
            throw new RuntimeException('Configura SOAP_ENDPOINT y las credenciales WS-Security.');
        }

        try {
            $wsdl = str_contains($endpoint, 'wsdl')
                ? $endpoint
                : $endpoint.(str_contains($endpoint, '?') ? '&' : '?').'wsdl';
            $client = new UsernameTokenSoapClient($wsdl, [
                'exceptions' => true,
                'trace' => false,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => 5,
            ], $username, $password);
            $result = $client->__soapCall('ValidateTokenService', ['token' => $token]);
        } catch (Throwable $exception) {
            throw new RuntimeException('El servicio SOAP no pudo completar la validación.', 0, $exception);
        }

        $payload = is_object($result) && isset($result->return) ? $result->return : $result;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (is_array($payload)) {
            return $payload;
        }

        return ['exito' => false, 'mensaje' => 'SOAP devolvió una respuesta inesperada.'];
    }
}
