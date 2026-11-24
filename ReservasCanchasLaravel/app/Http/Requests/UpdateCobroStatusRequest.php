<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCobroStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', 'string', Rule::in(['pendiente', 'pagado', 'anulado'])],
            'metodo_pago' => [
                'exclude_unless:estado,pagado',
                'required_if:estado,pagado',
                'string',
                Rule::in(['efectivo', 'transferencia', 'tarjeta']),
            ],
            'referencia_transaccion' => [
                'exclude_unless:estado,pagado',
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }
}
