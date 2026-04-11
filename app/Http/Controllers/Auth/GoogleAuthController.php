<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $next = (string) $request->query('next', '/duels');

        if ($next === '' || !str_starts_with($next, '/')) {
            $next = '/duels';
        }

        $request->session()->put('google_auth_next', $next);

        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    public function callback(Request $request)
    {
        $googleUser = Socialite::driver('google')
            ->stateless()
            ->user();

        $email = $googleUser->getEmail();

        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName() ?: ($googleUser->getNickname() ?: 'User'),
                'email' => $email,
                'password' => bcrypt(str()->random(32)),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
            ]);
        } else {
            $user->google_id = $user->google_id ?: $googleUser->getId();
            $user->avatar_url = $googleUser->getAvatar();
            $user->save();
        }

        Auth::login($user, true);

        $next = (string) $request->session()->pull('google_auth_next', '/duels');

        if ($next === '' || !str_starts_with($next, '/')) {
            $next = '/duels';
        }

        return redirect(config('app.frontend_url') . '/auth/success?next=' . urlencode($next));
    }
}
