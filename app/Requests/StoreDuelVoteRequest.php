<?php

namespace App\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDuelVoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_key' => ['required', 'string'],
            'player_a_id' => ['required', 'integer'],
            'player_b_id' => ['required', 'integer', 'different:player_a_id'],
            'winner_id' => ['required', 'integer'],
            'duel_id' => ['required', 'integer'],
        ];
    }
}
