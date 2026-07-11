<?php

namespace App\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitScoutReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'votes' => ['array', 'max:6'],
            'votes.*.attribute_key' => ['required', 'string', 'exists:attributes,key'],
            'votes.*.value' => ['required', 'integer', 'min:1', 'max:99'],
            'skipped_attribute_ids' => ['array', 'max:6'],
            'skipped_attribute_ids.*' => ['integer', 'exists:attributes,id'],
        ];
    }
}
