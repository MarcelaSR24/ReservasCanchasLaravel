<?php

namespace App\Http\Controllers;

use App\Services\SoapConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class SoapAuthController extends Controller
{
    public function validateToken(Request $request, SoapConnectionService $soap): JsonResponse
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return response()->json(['exito' => false, 'mensaje' => 'Envía el JWT en Authorization: Bearer.'], 401);
        }

        try {
            $result = $soap->validateToken($token);

            return response()->json($result, ($result['exito'] ?? false) === true ? 200 : 401);
        } catch (Throwable) {
            return response()->json(['exito' => false, 'mensaje' => 'No fue posible comunicarse con el servicio SOAP.'], 502);
        }
    }

    public function protectedOperation(Request $request): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'usuario_id' => (int) ($request->attributes->get('jwt_claims')['sub'] ?? 0),
        ]);
    }
}
