<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class EventLogController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'created_at' => ['nullable', 'date'],
        ]);

        $anonId = trim((string) $request->header('X-Zcout-Anon'));
        $voterHash = $anonId !== ''
            ? hash_hmac('sha256', $anonId, (string) config('app.key'))
            : null;

        try {
            EventLog::create([
                'event_type' => $data['event_type'],
                'user_id' => $request->user()?->id,
                'voter_hash' => $voterHash,
                'payload' => $data['payload'] ?? null,
                'created_at' => $data['created_at'] ?? now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('telemetry.event_log_failed', [
                'event_type' => $data['event_type'] ?? null,
                'user_id' => $request->user()?->id,
                'voter_hash_present' => $voterHash !== null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true], 202);
    }
}
