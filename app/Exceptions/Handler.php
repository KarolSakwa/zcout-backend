<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (!app()->bound('request')) {
                return;
            }

            $request = request();

            if (!$request->is('api/*')) {
                return;
            }

            $anonId = trim((string) $request->header('X-Zcout-Anon'));
            $voterHash = $anonId !== ''
                ? hash_hmac('sha256', $anonId, (string) config('app.key'))
                : null;

            Log::error('api.unhandled_exception', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'method' => $request->method(),
                'path' => $request->path(),
                'full_url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
                'voter_hash' => $voterHash,
            ]);
        });
    }
}
