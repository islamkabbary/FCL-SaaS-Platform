<?php

use App\Http\Middleware\ThrottlePasswordResetLinkRequests;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());

    actingInTenant();
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

test('resetting the password ends every session of the user', function () {
    Notification::fake();
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        ['id' => 'stolen-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ['id' => 'someone-else', 'user_id' => $other->id, 'payload' => '', 'last_activity' => now()->timestamp],
    ]);

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasNoErrors();

        return true;
    });

    // The reset request's own guest session is stored too; only sessions of users matter here.
    expect(DB::table('sessions')->whereNotNull('user_id')->pluck('id')->all())->toBe(['someone-else']);
});

test('a reset link request never reveals whether the email address has an account', function () {
    Notification::fake();
    $user = User::factory()->create();
    $answer = 'If an account exists for that email address, a password reset link has been sent to it.';

    $this->post(route('password.email'), ['email' => 'nobody@acme.test'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', $answer);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', $answer);

    // A second request for the same account is throttled by the broker, with the same answer.
    $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', $answer);

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('reset link requests are limited per client IP', function () {
    Notification::fake();
    $this->freezeTime();

    foreach (range(1, ThrottlePasswordResetLinkRequests::MaxPerMinute) as $attempt) {
        $this->post(route('password.email'), ['email' => "probe{$attempt}@acme.test"])->assertSessionHasNoErrors();
    }

    $this->post(route('password.email'), ['email' => 'probe-next@acme.test'])
        ->assertSessionHasErrors(['email' => 'Too many password reset requests. Please try again in 60 seconds.']);

    // Another client is not affected, and the limit lifts after a minute.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post(route('password.email'), ['email' => 'other@acme.test'])
        ->assertSessionHasNoErrors();

    $this->travel(61)->seconds();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->post(route('password.email'), ['email' => 'later@acme.test'])
        ->assertSessionHasNoErrors();
});
