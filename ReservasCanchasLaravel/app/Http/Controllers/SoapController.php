<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SoapController extends Controller
{
    public function handle(Request $request)
    {
        require_once base_path(
            'vendor/econea/nusoap/src/nusoap.php'
        );

        require_once app_path(
            'Soap/SoapFunctions.php'
        );

        $input = file_get_contents('php://input');

        if ($input === false) {
            $input = '';
        }

        /*
        |--------------------------------------------------------------------------
        | WS-Security
        |--------------------------------------------------------------------------
        | El WSDL se puede consultar mediante GET sin autenticación.
        | Las operaciones SOAP mediante POST requieren UsernameToken.
        |--------------------------------------------------------------------------
        */

        if ($request->isMethod('POST')) {

            $resultadoSeguridad = $this->validarWSecurity($input);

            if ($resultadoSeguridad !== true) {
                return response(
                    $this->soapFault($resultadoSeguridad),
                    500
                )->header(
                    'Content-Type',
                    'text/xml; charset=UTF-8'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Servidor SOAP
        |--------------------------------------------------------------------------
        */

        $server = new \soap_server();

        $server->configureWSDL(
            'ReservasCanchas',
            'urn:ReservasCanchas'
        );

        $server->wsdl->schemaTargetNamespace =
            'urn:ReservasCanchas';

        $server->soap_defencoding = 'UTF-8';
        $server->xml_encoding = 'UTF-8';

        /*
        |--------------------------------------------------------------------------
        | Operación: registrarCancha
        |--------------------------------------------------------------------------
        */

        $server->register(
            'registrarCancha',
            [
                'codigo' => 'xsd:string',
                'nombre' => 'xsd:string',
                'tipo' => 'xsd:string',
                'estado' => 'xsd:string'
            ],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#registrarCancha',
            'rpc',
            'encoded',
            'Registra una nueva cancha deportiva.'
        );

        /*
        |--------------------------------------------------------------------------
        | Operación: consultarCancha
        |--------------------------------------------------------------------------
        */

        $server->register(
            'consultarCancha',
            [
                'id' => 'xsd:int'
            ],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#consultarCancha',
            'rpc',
            'encoded',
            'Consulta una cancha por su identificador.'
        );

        /*
        |--------------------------------------------------------------------------
        | Operación: listarCanchas
        |--------------------------------------------------------------------------
        */

        $server->register(
            'listarCanchas',
            [],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#listarCanchas',
            'rpc',
            'encoded',
            'Lista todas las canchas registradas.'
        );

        /*
        |--------------------------------------------------------------------------
        | Operación: actualizarCancha
        |--------------------------------------------------------------------------
        */

        $server->register(
            'actualizarCancha',
            [
                'id' => 'xsd:int',
                'nombre' => 'xsd:string',
                'tipo' => 'xsd:string',
                'estado' => 'xsd:string'
            ],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#actualizarCancha',
            'rpc',
            'encoded',
            'Actualiza los datos de una cancha.'
        );

        /*
        |--------------------------------------------------------------------------
        | Operación: registrarReserva
        |--------------------------------------------------------------------------
        */

        $server->register(
            'registrarReserva',
            [
                'canchaId' => 'xsd:int',
                'usuarioId' => 'xsd:int',
                'fecha' => 'xsd:string',
                'hora' => 'xsd:string',
                'estado' => 'xsd:string'
            ],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#registrarReserva',
            'rpc',
            'encoded',
            'Registra una reserva de cancha.'
        );

        /*
        |--------------------------------------------------------------------------
        | Operación: consultarReservas
        |--------------------------------------------------------------------------
        */

        $server->register(
            'consultarReservas',
            [],
            ['return' => 'xsd:string'],
            'urn:ReservasCanchas',
            'urn:ReservasCanchas#consultarReservas',
            'rpc',
            'encoded',
            'Consulta todas las reservas registradas.'
        );

        /*
        |--------------------------------------------------------------------------
        | Procesar solicitud SOAP
        |--------------------------------------------------------------------------
        */

        ob_start();

        $server->service($input);

        $response = ob_get_clean();

        return response(
            $response,
            200
        )->header(
            'Content-Type',
            'text/xml; charset=UTF-8'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Validar WS-Security UsernameToken
    |--------------------------------------------------------------------------
    */

    private function validarWSecurity(string $xml)
    {
        if (trim($xml) === '') {
            return 'La solicitud SOAP está vacía.';
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();

        if (!$dom->loadXML($xml, LIBXML_NONET)) {
            return 'El mensaje SOAP no contiene un XML válido.';
        }

        $xpath = new \DOMXPath($dom);

        /*
        | Namespace de WS-Security
        */

        $xpath->registerNamespace(
            'wsse',
            'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd'
        );

        /*
        | Buscar UsernameToken
        */

        $usernameNode = $xpath->query(
            '//wsse:UsernameToken/wsse:Username'
        );

        $passwordNode = $xpath->query(
            '//wsse:UsernameToken/wsse:Password'
        );

        if (
            $usernameNode->length === 0 ||
            $passwordNode->length === 0
        ) {
            return 'Falta la información de WS-Security UsernameToken.';
        }

        $username = trim(
            $usernameNode->item(0)->nodeValue
        );

        $password = trim(
            $passwordNode->item(0)->nodeValue
        );

        /*
        | Leer tipo de contraseña
        */

        $passwordElement = $passwordNode->item(0);

        $passwordType = $passwordElement->getAttribute('Type');

        if (
            $passwordType !== '' &&
            !str_contains(
                $passwordType,
                'PasswordText'
            )
        ) {
            return 'Por ahora el servicio requiere PasswordText.';
        }

        /*
        | Credenciales configuradas en .env
        */

        $usuarioEsperado = env(
            'SOAP_SECURITY_USERNAME'
        );

        $passwordEsperada = env(
            'SOAP_SECURITY_PASSWORD'
        );

        /*
        | Validar usuario y contraseña
        */

        if (
            !hash_equals(
                (string) $usuarioEsperado,
                $username
            )
        ) {
            return 'Usuario WS-Security incorrecto.';
        }

        if (
            !hash_equals(
                (string) $passwordEsperada,
                $password
            )
        ) {
            return 'Contraseña WS-Security incorrecta.';
        }

        return true;
    }


    /*
    |--------------------------------------------------------------------------
    | Generar SOAP Fault
    |--------------------------------------------------------------------------
    */

    private function soapFault(string $mensaje): string
    {
        $mensaje = htmlspecialchars(
            $mensaje,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<SOAP-ENV:Envelope '
            . 'xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<SOAP-ENV:Body>'
            . '<SOAP-ENV:Fault>'
            . '<faultcode>SOAP-ENV:Client</faultcode>'
            . '<faultstring>'
            . $mensaje
            . '</faultstring>'
            . '</SOAP-ENV:Fault>'
            . '</SOAP-ENV:Body>'
            . '</SOAP-ENV:Envelope>';
    }
}