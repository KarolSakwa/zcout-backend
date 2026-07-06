<?php

namespace App\Support;

use Illuminate\Http\Request;

final class VoteRequestPayloadResolver
{
    public function resolve(Request $request): array
    {
        if (strlen((string) $request->getContent()) > 8192) {
            abort(response()->json(['message' => 'Payload too large.'], 413));
        }

        $json = $request->json()->all();
        if (is_array($json) && count($json) > 0) {
            return $json;
        }

        $raw = $request->getContent();
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $all = $request->all();

        return is_array($all) ? $all : [];
    }
}
