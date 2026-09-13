<?php

namespace Tests\Feature;

use App\Mail\EmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_a_verification_code_without_exposing_it(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register', $this->registrationData('new-customer@example.com'));

        $response->assertCreated()->assertJsonMissingPath('verification_code');
        Mail::assertSent(EmailVerificationCode::class, function (EmailVerificationCode $mail) {
            return $mail->hasTo('new-customer@example.com')
                && preg_match('/^[0-9]{6}$/', $mail->code) === 1;
        });

        $user = User::where('email', 'new-customer@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->verificationCode(), $user->email_verification_code_hash));
    }

    public function test_registration_uses_brevo_https_when_an_api_key_is_configured(): void
    {
        config(['services.brevo.api_key' => 'test-brevo-api-key']);
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'verification-message'], 201),
        ]);

        $response = $this->postJson('/api/register', $this->registrationData('brevo-customer@example.com'));

        $response->assertCreated()
            ->assertJsonMissingPath('verification_code')
            ->assertJsonPath('email_verification_required', true);

        Http::assertSent(function (HttpRequest $request): bool {
            $payload = $request->data();

            return $request->hasHeader('api-key', 'test-brevo-api-key')
                && $payload['to'][0]['email'] === 'brevo-customer@example.com'
                && preg_match('/>\s*[0-9]{6}\s*</', $payload['htmlContent']) === 1;
        });
    }

    public function test_valid_code_verifies_the_customer_and_clears_the_code(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('valid-code@example.com'))->assertCreated();
        $code = $this->verificationCode();

        $this->postJson('/api/email/verify-code', [
            'email' => 'valid-code@example.com',
            'code' => $code,
        ])->assertOk()->assertJsonPath('message', 'Email verified successfully.');

        $user = User::where('email', 'valid-code@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_code_hash);
        $this->assertNull($user->email_verification_expires_at);
    }

    public function test_invalid_code_is_rejected(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('invalid-code@example.com'))->assertCreated();

        $this->postJson('/api/email/verify-code', [
            'email' => 'invalid-code@example.com',
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);

        $this->assertDatabaseHas('users', [
            'email' => 'invalid-code@example.com',
            'email_verified_at' => null,
            'email_verification_attempts' => 1,
        ]);
    }

    public function test_repeated_incorrect_codes_invalidate_the_code(): void
    {
        Mail::fake();
        config(['auth.email_verification.max_attempts' => 5]);
        $this->postJson('/api/register', $this->registrationData('locked-code@example.com'))->assertCreated();

        foreach (range(1, 4) as $attempt) {
            $this->postJson('/api/email/verify-code', [
                'email' => 'locked-code@example.com',
                'code' => '000000',
            ])->assertUnprocessable()->assertJsonPath('errors.code.0', 'The verification code is incorrect.');
        }

        $this->postJson('/api/email/verify-code', [
            'email' => 'locked-code@example.com',
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonPath('errors.code.0', 'Too many incorrect attempts. Please request a new verification code.');

        $user = User::where('email', 'locked-code@example.com')->firstOrFail();
        $this->assertSame(5, $user->email_verification_attempts);
        $this->assertNotNull($user->email_verification_locked_at);
        $this->assertNull($user->email_verification_code_hash);

        $this->postJson('/api/email/verify-code', [
            'email' => 'locked-code@example.com',
            'code' => $this->verificationCode(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code'])
            ->assertJsonPath('errors.code.0', 'Too many incorrect attempts. Please request a new verification code.');
    }

    public function test_resend_resets_verification_attempts_and_unlocks_code(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('reset-code@example.com'))->assertCreated();
        User::where('email', 'reset-code@example.com')->update([
            'email_verification_attempts' => 5,
            'email_verification_locked_at' => now(),
            'email_verification_sent_at' => now()->subMinute(),
            'email_verification_code_hash' => null,
        ]);

        $response = $this->postJson('/api/email/resend-code', ['email' => 'reset-code@example.com'])->assertOk();
        $response->assertJsonStructure(['registration_cancel_token']);

        $user = User::where('email', 'reset-code@example.com')->firstOrFail();
        $this->assertSame(0, $user->email_verification_attempts);
        $this->assertNull($user->email_verification_locked_at);
        $this->assertNotNull($user->email_verification_code_hash);
    }

    public function test_pending_registration_cancellation_requires_the_registration_token(): void
    {
        Mail::fake();
        $response = $this->postJson('/api/register', $this->registrationData('cancel-token@example.com'))->assertCreated();
        $token = $response->json('registration_cancel_token');

        $this->postJson('/api/email/cancel-registration', [
            'email' => 'cancel-token@example.com',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['email' => 'cancel-token@example.com']);

        $this->postJson('/api/email/cancel-registration', [
            'email' => 'cancel-token@example.com',
        ], ['X-Registration-Cancel-Token' => $token])->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'cancel-token@example.com']);
    }

    public function test_expired_code_is_rejected(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('expired-code@example.com'))->assertCreated();
        User::where('email', 'expired-code@example.com')->update([
            'email_verification_expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/verify-code', [
            'email' => 'expired-code@example.com',
            'code' => $this->verificationCode(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_resend_invalidates_the_previous_code_and_resets_the_expiration(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('resend-code@example.com'))->assertCreated();
        $firstCode = $this->verificationCode();

        User::where('email', 'resend-code@example.com')->update([
            'email_verification_sent_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/resend-code', [
            'email' => 'resend-code@example.com',
        ])->assertOk();

        Mail::assertSent(EmailVerificationCode::class, 2);
        $mails = Mail::sent(EmailVerificationCode::class);
        $secondCode = $mails[1]->code;
        $this->assertNotSame($firstCode, $secondCode);

        $this->postJson('/api/email/verify-code', [
            'email' => 'resend-code@example.com',
            'code' => $firstCode,
        ])->assertUnprocessable();

        $this->postJson('/api/email/verify-code', [
            'email' => 'resend-code@example.com',
            'code' => $secondCode,
        ])->assertOk();
    }

    public function test_unverified_customer_cannot_log_in(): void
    {
        Mail::fake();
        $this->postJson('/api/register', $this->registrationData('unverified-login@example.com'))->assertCreated();

        $this->postJson('/api/login', [
            'email' => 'unverified-login@example.com',
            'password' => 'Password1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_existing_unverified_customer_is_sent_to_verification_instead_of_duplicate_error(): void
    {
        Mail::fake();
        User::factory()->unverified()->create([
            'email' => 'existing-unverified@example.com',
        ]);

        $response = $this->postJson('/api/register', $this->registrationData('existing-unverified@example.com'));

        $response
            ->assertOk()
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonPath('email', 'existing-unverified@example.com')
            ->assertJsonMissingPath('verification_code');

        Mail::assertSent(EmailVerificationCode::class, function (EmailVerificationCode $mail) {
            return $mail->hasTo('existing-unverified@example.com');
        });
        $this->assertDatabaseCount('users', 1);
    }

    private function registrationData(string $email): array
    {
        return [
            'first_name' => 'New',
            'last_name' => 'Customer',
            'email' => $email,
            'contact_number' => '09171234567',
            'password' => 'Password1',
            'password_confirmation' => 'Password1',
        ];
    }

    private function verificationCode(): string
    {
        $mail = Mail::sent(EmailVerificationCode::class)->last();

        return $mail->code;
    }
}
