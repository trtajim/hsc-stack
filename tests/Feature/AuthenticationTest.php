<?php

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;

test('login page is accessible for guests', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('login page redirects authenticated users to /me', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/login');

    $response->assertRedirect(route('me'));
});

test('google auth redirects authenticated users to /me', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('auth.google'));

    $response->assertRedirect(route('me'));
});

test('redirects to google for authentication for guests', function () {
    $response = $this->get(route('auth.google'));

    $response->assertRedirect();
    $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
});

test('google auth transfers new user to onboarding without creating account immediately', function () {
    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-12345');
    $abstractUser->shouldReceive('getEmail')->andReturn('newuser@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Google User');
    $abstractUser->shouldReceive('getNickname')->andReturn('googleuser');
    $abstractUser->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('onboarding'));
    $this->assertGuest();
    $this->assertDatabaseMissing('users', [
        'email' => 'newuser@example.com',
    ]);
    $response->assertSessionHas('onboarding_user', function ($data) {
        return $data['email'] === 'newuser@example.com'
            && $data['google_id'] === 'google-id-12345'
            && $data['name'] === 'Google User'
            && $data['avatar'] === 'https://example.com/avatar.jpg';
    });
});

test('onboarding page is accessible with onboarding session', function () {
    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-12345',
            'email' => 'newuser@example.com',
            'name' => 'Google User',
            'avatar' => null,
        ],
    ])->get(route('onboarding'));

    $response->assertStatus(200);
});

test('onboarding page redirects to login without onboarding session', function () {
    $response = $this->get(route('onboarding'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'Please continue with Google to create an account.');
});

test('completing onboarding creates user, sends welcome notification, and logs in', function () {
    Notification::fake();

    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-12345',
            'email' => 'newuser@example.com',
            'name' => 'Google User',
            'avatar' => null,
        ],
    ])->post(route('onboarding.complete'), [
        'name' => 'Custom Name',
        'username' => 'custom_handle',
        'school' => 'Notre Dame College',
    ]);

    $user = User::where('email', 'newuser@example.com')->first();
    $this->assertNotNull($user);
    $this->assertEquals('Custom Name', $user->name);
    $this->assertEquals('custom_handle', $user->username);
    $this->assertEquals('Notre Dame College', $user->institution);
    $this->assertEquals('google-id-12345', $user->google_id);
    $this->assertNotNull($user->email_verified_at);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('user.profile', 'custom_handle'));
    $response->assertSessionHas('success');
    $response->assertSessionMissing('onboarding_user');

    Notification::assertSentTo($user, WelcomeNotification::class);
});

test('completing onboarding with avatar image upload stores image', function () {
    Storage::fake();

    $file = UploadedFile::fake()->image('avatar.jpg');

    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-67890',
            'email' => 'photo_user@example.com',
            'name' => 'Photo User',
            'avatar' => null,
        ],
    ])->post(route('onboarding.complete'), [
        'name' => 'Photo User',
        'username' => 'photo_user',
        'school' => 'Dhaka College',
        'image' => $file,
    ]);

    $user = User::where('email', 'photo_user@example.com')->first();
    $this->assertNotNull($user);
    $this->assertNotNull($user->image_path);
    Storage::assertExists($user->image_path);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('user.profile', 'photo_user'));
});

test('completing onboarding downloads and stores google avatar when available', function () {
    Storage::fake();
    Http::fake([
        'https://lh3.googleusercontent.com/*' => Http::response('fake-avatar-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-99999',
            'email' => 'google_avatar@example.com',
            'name' => 'Google Avatar User',
            'avatar' => 'https://lh3.googleusercontent.com/a/some-photo=s96-c',
        ],
    ])->post(route('onboarding.complete'), [
        'name' => 'Google Avatar User',
        'username' => 'google_avatar_user',
        'school' => 'Rajshahi College',
    ]);

    $user = User::where('email', 'google_avatar@example.com')->first();
    $this->assertNotNull($user);
    $this->assertNotNull($user->image_path);
    Storage::assertExists($user->image_path);
    $this->assertEquals('fake-avatar-bytes', Storage::get($user->image_path));

    $this->assertAuthenticatedAs($user);
});

test('completing onboarding validates username uniqueness and format', function () {
    User::factory()->create([
        'username' => 'taken_handle',
    ]);

    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-12345',
            'email' => 'newuser@example.com',
            'name' => 'Google User',
            'avatar' => null,
        ],
    ])->post(route('onboarding.complete'), [
        'name' => 'Custom Name',
        'username' => 'taken_handle',
        'school' => 'Dhaka College',
    ]);

    $response->assertSessionHasErrors(['username']);
    $this->assertGuest();
});

test('completing onboarding requires school field', function () {
    $response = $this->withSession([
        'onboarding_user' => [
            'google_id' => 'google-id-12345',
            'email' => 'newuser@example.com',
            'name' => 'Google User',
            'avatar' => null,
        ],
    ])->post(route('onboarding.complete'), [
        'name' => 'Custom Name',
        'username' => 'valid_handle',
        'school' => '',
    ]);

    $response->assertSessionHasErrors(['school']);
    $this->assertGuest();
});

test('redirects to custom redirect url after onboarding for new user', function () {
    $this->get('/auth/google?redirect=/ai');

    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-new-redirect');
    $abstractUser->shouldReceive('getEmail')->andReturn('new-redirect@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('New Redirect User');
    $abstractUser->shouldReceive('getNickname')->andReturn('newredirect');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    $callbackResponse = $this->get(route('auth.google.callback'));
    $callbackResponse->assertRedirect(route('onboarding'));

    // Complete onboarding with intended URL in session
    $onboardResponse = $this->post(route('onboarding.complete'), [
        'name' => 'New Redirect User',
        'username' => 'new_redirect_user',
        'school' => 'Dhaka College',
    ]);

    $onboardResponse->assertRedirect(url('/ai'));
});

test('redirects to custom redirect url after authentication for existing user', function () {
    $user = User::factory()->create([
        'email' => 'redirect-test@example.com',
        'google_id' => 'google-id-custom',
    ]);

    $this->get('/auth/google?redirect=/ai');

    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-custom');
    $abstractUser->shouldReceive('getEmail')->andReturn('redirect-test@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Redirect User');
    $abstractUser->shouldReceive('getNickname')->andReturn('redirectuser');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(url('/ai'));
});

test('redirects to trusted subdomain after authentication for existing user', function () {
    config(['app.url' => 'https://hscstack.site']);
    $subdomainUrl = 'https://ssc2026.hscstack.site';

    $user = User::factory()->create([
        'email' => 'subdomain@example.com',
        'google_id' => 'google-id-subdomain',
    ]);

    $this->get('/auth/google?redirect='.urlencode($subdomainUrl));

    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-subdomain');
    $abstractUser->shouldReceive('getEmail')->andReturn('subdomain@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Subdomain User');
    $abstractUser->shouldReceive('getNickname')->andReturn('subdomainuser');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect($subdomainUrl);
});

test('existing user logs in with google directly and links google id', function () {
    $user = User::factory()->create([
        'email' => 'existing@example.com',
        'google_id' => null,
    ]);

    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-99999');
    $abstractUser->shouldReceive('getEmail')->andReturn('existing@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Existing User');
    $abstractUser->shouldReceive('getNickname')->andReturn('existing');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    $response = $this->get(route('auth.google.callback'));

    $this->assertAuthenticatedAs($user);
    $this->assertEquals('google-id-99999', $user->fresh()->google_id);
});

test('failed google auth redirects to login with error', function () {
    Socialite::shouldReceive('driver->user')->andThrow(new Exception('Invalid state'));

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('error', 'Failed to authenticate with Google. Please try again.');
});

test('google auth redirects to intended url if set for existing user', function () {
    $user = User::factory()->create([
        'email' => 'intended@example.com',
        'google_id' => 'google-id-intended',
    ]);

    $abstractUser = Mockery::mock(Laravel\Socialite\Two\User::class);
    $abstractUser->shouldReceive('getId')->andReturn('google-id-intended');
    $abstractUser->shouldReceive('getEmail')->andReturn('intended@example.com');
    $abstractUser->shouldReceive('getName')->andReturn('Intended User');
    $abstractUser->shouldReceive('getNickname')->andReturn('intended');
    $abstractUser->shouldReceive('getAvatar')->andReturn(null);

    Socialite::shouldReceive('driver->user')->andReturn($abstractUser);

    // Attempt to access auth-protected route while logged out
    $this->get('/profile');

    // Authenticate with Google
    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirect(route('profile.edit'));
});
