<?php

namespace App\Services;

use DOMDocument;
use RuntimeException;
use SoapClient;

class UsernameTokenSoapClient extends SoapClient
{
    public function __construct(
        string $wsdl,
        array $options,
        private readonly string $securityUsername,
        private readonly string $securityPassword
    ) {
        parent::__construct($wsdl, $options);
    }

    public function __doRequest(
        string $request,
        string $location,
        string $action,
        int $version,
        bool $oneWay = false
    ): ?string {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = false;
        if (! $document->loadXML($request, LIBXML_NONET)) {
            throw new RuntimeException('No se pudo preparar el encabezado WS-Security.');
        }

        $envelope = $document->documentElement;
        if (! $envelope instanceof \DOMElement) {
            throw new RuntimeException('El cliente SOAP generó un mensaje inválido.');
        }

        $soapNamespace = $envelope->namespaceURI;
        $soapPrefix = $envelope->prefix ?: 'SOAP-ENV';
        $header = null;
        foreach ($envelope->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'Header' && $child->namespaceURI === $soapNamespace) {
                $header = $child;
                break;
            }
        }

        if (! $header instanceof \DOMElement) {
            $header = $document->createElementNS($soapNamespace, $soapPrefix.':Header');
            $envelope->insertBefore($header, $envelope->firstChild);
        }

        $securityNamespace = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';
        $passwordType = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText';
        $security = $document->createElementNS($securityNamespace, 'wsse:Security');
        $security->setAttributeNS($soapNamespace, $soapPrefix.':mustUnderstand', '1');
        $usernameToken = $document->createElementNS($securityNamespace, 'wsse:UsernameToken');
        $username = $document->createElementNS($securityNamespace, 'wsse:Username');
        $username->appendChild($document->createTextNode($this->securityUsername));
        $password = $document->createElementNS($securityNamespace, 'wsse:Password');
        $password->setAttribute('Type', $passwordType);
        $password->appendChild($document->createTextNode($this->securityPassword));
        $usernameToken->appendChild($username);
        $usernameToken->appendChild($password);
        $security->appendChild($usernameToken);
        $header->appendChild($security);

        return parent::__doRequest($document->saveXML(), $location, $action, $version, $oneWay);
    }
}
