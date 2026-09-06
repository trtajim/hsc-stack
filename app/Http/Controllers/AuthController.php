<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Rules\CleanText;
use App\Services\ChatProfanityFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    //
    public function index()
    {
        if (Auth::check()) {
            return redirect()->route('me');
        }

        return Inertia::render('auth/Login');
    }

    public function redirectToGoogle(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('me');
        }

        if ($request->filled('redirect')) {
            $redirectUrl = $request->query('redirect');

            if (str_starts_with($redirectUrl, '/') && ! str_starts_with($redirectUrl, '//')) {
                session(['url.intended' => url($redirectUrl)]);
            } else {
                $host = parse_url($redirectUrl, PHP_URL_HOST);
                $appHost = parse_url(config('app.url'), PHP_URL_HOST);

                if ($host && $appHost && ($host === $appHost || str_ends_with($host, '.'.$appHost) || $host === 'localhost')) {
                    session(['url.intended' => $redirectUrl]);
                }
            }
        }

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('login')->with('error', 'Failed to authenticate with Google. Please try again.');
        }

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            $user->update([
                'google_id' => $googleUser->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            // If existing user has no profile photo, download and store their Google avatar
            if (! $user->image_path && $googleUser->getAvatar()) {
                $downloadedPath = $this->downloadGoogleAvatar($googleUser->getAvatar());
                if ($downloadedPath) {
                    $user->update(['image_path' => $downloadedPath]);
                }
            }

            Auth::login($user, remember: true);
            $request->session()->regenerate();

            $defaultUrl = $user->username
                ? route('user.profile', $user->username)
                : route('profile.edit');

            return redirect()->intended($defaultUrl);
        }

        $rawName = $googleUser->getName() ?? $googleUser->getNickname() ?? '';
        $cleanGoogleName = ChatProfanityFilter::hasProfanity($rawName) ? 'Student' : $rawName;

        $request->session()->put('onboarding_user', [
            'google_id' => $googleUser->getId(),
            'email' => $googleUser->getEmail(),
            'name' => $cleanGoogleName,
            'avatar' => $googleUser->getAvatar() ?? null,
        ]);

        return redirect()->route('onboarding');
    }

    public function showOnboarding(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('index');
        }

        if (! $request->session()->has('onboarding_user')) {
            return redirect()->route('login')->with('error', 'Please continue with Google to create an account.');
        }

        $onboardingUser = $request->session()->get('onboarding_user');

        return Inertia::render('auth/Onboarding', [
            'user' => $onboardingUser,
        ]);
    }

    public function completeOnboarding(Request $request)
    {
        if (Auth::check()) {
            return redirect()->route('index');
        }

        if (! $request->session()->has('onboarding_user')) {
            return redirect()->route('login')->with('error', 'Session expired. Please continue with Google again.');
        }

        $onboardingData = $request->session()->get('onboarding_user');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', new CleanText],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-zA-Z0-9_]+$/',
                'unique:users,username',
                new CleanText,
            ],
            'school' => ['required', 'string', 'max:255', new CleanText],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'school.required' => 'Please enter your school, college, or institution name.',
            'username.regex' => 'Username can only contain letters, numbers, and underscores.',
            'username.unique' => 'This username is already taken. Please choose another one.',
            'image.image' => 'The uploaded file must be an image (PNG, JPG, JPEG, WEBP).',
            'image.max' => 'The profile image may not be greater than 5MB.',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('users/profile-images');
        } elseif (! empty($onboardingData['avatar'])) {
            $imagePath = $this->downloadGoogleAvatar($onboardingData['avatar']);
        }

        $user = User::where('google_id', $onboardingData['google_id'])
            ->orWhere('email', $onboardingData['email'])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $onboardingData['email'],
                'google_id' => $onboardingData['google_id'],
                'institution' => $validated['school'],
                'image_path' => $imagePath,
                'email_verified_at' => now(),
            ]);

            $user->notify(new WelcomeNotification);
        } else {
            if ($imagePath) {
                if ($user->image_path) {
                    Storage::delete($user->image_path);
                }
                $user->update(['image_path' => $imagePath]);
            }
        }

        $request->session()->forget('onboarding_user');

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $defaultUrl = $user->username
            ? route('user.profile', $user->username)
            : route('profile.edit');

        return redirect()->intended($defaultUrl)->with('success', 'Account created successfully! Welcome to HSCStack.');
    }

    private function downloadGoogleAvatar(?string $avatarUrl): ?string
    {
        if (! $avatarUrl) {
            return null;
        }

        try {
            // Replace small size parameter (=s96-c) with high-res (=s500-c) if present
            $highResUrl = preg_replace('/=s\d+(-c)?$/i', '=s500-c', $avatarUrl);
            $verifySsl = config('services.google.guzzle.verify', true);

            $response = Http::withOptions(['verify' => $verifySsl])->timeout(6)->get($highResUrl);
            if (! $response->successful()) {
                // Fallback to original URL if high-res request fails
                $response = Http::withOptions(['verify' => $verifySsl])->timeout(6)->get($avatarUrl);
            }

            if ($response->successful()) {
                $contents = $response->body();
                $extension = 'jpg';
                $contentType = (string) $response->header('Content-Type');
                if (str_contains($contentType, 'png')) {
                    $extension = 'png';
                } elseif (str_contains($contentType, 'webp')) {
                    $extension = 'webp';
                }

                $filename = 'users/profile-images/'.Str::random(40).'.'.$extension;
                Storage::put($filename, $contents);

                return $filename;
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('index');
    }
}
