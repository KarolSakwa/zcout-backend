<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttributeRankingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'position'  => ['nullable', 'string', 'max:5'],
            'min_votes' => ['nullable', 'integer', 'min:0'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor'    => ['nullable', 'string'],
        ];
    }
}
