<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmPlayerArchetypeClient
{
    public function generate(string $systemPrompt, string $userPrompt): string
    {
        $response = Http::withToken(config('services.openai.api_key'))
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.player_archetype_model'),
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $systemPrompt,
                    ],
                    [
                        'role' => 'user',
                        'content' => $userPrompt,
                    ],
                ],
            ]);

        $response->throw();

        return $response->json('choices.0.message.content');
    }
}
