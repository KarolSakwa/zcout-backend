<?php

namespace App\Simulation\Actions;

use App\Http\Controllers\Api\DuelController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class FetchNextDuelPayload
{
    public function __construct(
        private readonly DuelController $duelController = new DuelController(),
    ) {
    }

    public function handle(string $attributeKey, ?string $anonId = null, ?int $appUserId = null): ?array
    {
        $request = Request::create('/api/duels/next', 'GET', [
            'attribute' => $attributeKey,
        ]);

        if ($appUserId !== null) {
            Auth::onceUsingId($appUserId);
        } elseif ($anonId !== null && $anonId !== '') {
            $request->headers->set('X-Zcout-Anon', $anonId);
        }

        app()->instance('request', $request);

        $response = $this->duelController->next();

        Auth::forgetGuards();

        if (! method_exists($response, 'getData')) {
            return null;
        }

        $data = $response->getData(true);

        return is_array($data) ? $data : null;
    }
}
