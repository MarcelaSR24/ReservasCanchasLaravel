<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreImplementoRequest extends FormRequest
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
            'codigo' => ['required', 'string', 'max:40'],
            'nombre' => ['required', 'string', 'max:100'],
            'categoria' => ['required', 'string', 'max:60'],
            'descripcion' => ['nullable', 'string'],
            'cantidad_disponible' => ['required', 'integer', 'min:0'],
            'estado' => ['sometimes', 'string', Rule::in(['activo', 'mantenimiento', 'retirado'])],
            'ubicacion' => ['nullable', 'string', 'max:100'],
        ];
    }
}
