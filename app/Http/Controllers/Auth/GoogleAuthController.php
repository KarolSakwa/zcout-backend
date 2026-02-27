<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    public function callback()
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

        return redirect(config('app.frontend_url') . '/auth/success?next=/duels');
    }
}
