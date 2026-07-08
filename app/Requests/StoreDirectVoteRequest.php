<?php

namespace App\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDirectVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_key' => ['required', 'string'],
            'player_id' => ['required', 'integer'],
            'value' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
