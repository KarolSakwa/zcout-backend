<?php

namespace App\Simulation\Actions;

use App\Http\Controllers\Api\DuelController;
use Illuminate\Http\Request;

final class FetchNextDuelPayload
{
    public function __construct(
        private readonly DuelController $duelController = new DuelController(),
    ) {
    }

    public function handle(string $attributeKey, ?string $anonId = null): ?array
    {
        $req = Request::create('/api/duels/next', 'GET', [
            'attribute' => $attributeKey,
        ]);

        if ($anonId !== null && $anonId !== '') {
            $req->headers->set('X-Zcout-Anon', $anonId);
        }

        app()->instance('request', $req);

        $resp = $this->duelController->next();

        if (! method_exists($resp, 'getData')) {
            return null;
        }

        $data = $resp->getData(true);

        return is_array($data) ? $data : null;
    }
}
