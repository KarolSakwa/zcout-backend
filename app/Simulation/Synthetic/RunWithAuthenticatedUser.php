<?php

namespace App\Simulation\Synthetic;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RunWithAuthenticatedUser
{
    /**
     * Temporarily authenticate $user and bind a request, then restore prior auth/request state.
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function execute(User $user, callable $callback): mixed
    {
        $previousRequestBound = app()->bound('request');
        $previousRequest = $previousRequestBound ? app('request') : null;
        $previousUser = Auth::user();

        Auth::login($user);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(static fn () => $user);
        app()->instance('request', $request);

        try {
            return $callback();
        } finally {
            if ($previousRequestBound && $previousRequest !== null) {
                app()->instance('request', $previousRequest);
            } else {
                app()->forgetInstance('request');
            }

            if ($previousUser instanceof Authenticatable) {
                Auth::login($previousUser);
            } else {
                Auth::logout();
            }
        }
    }
}
