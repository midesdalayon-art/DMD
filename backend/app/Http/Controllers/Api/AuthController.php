<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\EmailVerificationCode;
use App\Models\User;
use App\Services\SystemSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use App\Services\AuditLogger;

class AuthController extends Controller
{
    public function redirectToGoogle(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        try {
            $googleUser = Socialite::driver('google')->user();
            $email = strtolower(trim((string) $googleUser->getEmail()));
            $googleId = trim((string) $googleUser->getId());

            if ($email === '' || $googleId === '') {
                throw new \RuntimeException('Google did not return the required identity fields.');
            }

            $user = User::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($user && $user->normalizedRole() !== User::ROLE_GUEST) {
                return redirect($frontendUrl.'/?google_error='.rawurlencode('Google sign-in is available for customer accounts only.'));
            }

            if ($user && $user->google_id && $user->google_id !== $googleId) {
                throw new \RuntimeException('The Google identity is already linked to another account.');
            }

            if (! $user) {
                $name = trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: 'Google Guest'));
                $nameParts = preg_split('/\s+/', $name, 2);

                $user = User::create([
                    'name' => $name,
                    'first_name' => $nameParts[0] ?? 'Google',
                    'last_name' => $nameParts[1] ?? 'Guest',
                    'email' => $email,
                    'password' => Str::random(64),
                    'role' => User::ROLE_GUEST,
                    'google_id' => $googleId,
                ]);

                $user->forceFill(['email_verified_at' => now()])->save();
            } else {
                $user->forceFill([
                    'google_id' => $googleId,
                    'email_verified_at' => $user->email_verified_at ?? now(),
                    'email_verification_code_hash' => null,
                    'email_verification_expires_at' => null,
                    'email_verification_sent_at' => null,
                ])->save();
            }

            Auth::guard('web')->login($user, true);
            $request->session()->regenerate();

            return redirect($frontendUrl.'/');
        } catch (InvalidStateException $exception) {
            Log::warning('Google OAuth state validation failed.', ['exception' => $exception]);

            return redirect($frontendUrl.'/?google_error='.rawurlencode('Google sign-in could not be completed. Please try again.'));
        } catch (\Throwable $exception) {
            Log::error('Google OAuth sign-in failed.', [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return redirect($frontendUrl.'/?google_error='.rawurlencode('Google sign-in could not be completed. Please try again.'));
        }
    }

    public function register(Request $request, SystemSettings $settings): JsonResponse
    {
        $contactNumber = trim((string) $request->input('contact_number', ''));
        $digits = preg_replace('/\D/', '', $contactNumber);
        if (preg_match('/^09[0-9]{9}$/', $digits)) {
            $request->merge(['contact_number' => '+63'.substr($digits, 1)]);
        } elseif (str_starts_with($contactNumber, '+63') && strlen($digits) === 12) {
            $request->merge(['contact_number' => '+'.$digits]);
        }

        $attributes = $request->validate([
            'first_name' => ['required', 'string', 'max:80', 'regex:/^\p{L}+(?:[ \x27\x{2019}-]\p{L}+)*$/u'],
            'last_name' => ['required', 'string', 'max:80', 'regex:/^\p{L}+(?:[ \x27\x{2019}-]\p{L}+)*$/u'],
            'email' => ['required', 'email', 'max:255'],
            'contact_number' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'first_name.regex' => 'Use letters, spaces, hyphens, or apostrophes only.',
            'last_name.regex' => 'Use letters, spaces, hyphens, or apostrophes only.',
            'contact_number.regex' => 'Enter a valid international phone number in E.164 format.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        $existingUser = User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($attributes['email']))])
            ->first();

        if ($existingUser) {
            if ($existingUser->normalizedRole() === User::ROLE_GUEST && ! $existingUser->email_verified_at) {
                $mailSent = true;
                $cancelToken = DB::transaction(fn () => $this->issueRegistrationCancellationToken($existingUser));
                if (! $existingUser->email_verification_code_hash || ! $existingUser->email_verification_expires_at || $existingUser->email_verification_expires_at->isPast()) {
                    $code = DB::transaction(fn () => $this->issueVerificationCode($existingUser));
                    $mailSent = $this->deliverVerificationCode($existingUser, $code);
                }

                return response()->json([
                    'email_verification_required' => true,
                    'email' => $existingUser->email,
                    'registration_cancel_token' => $cancelToken,
                    'message' => $mailSent
                        ? 'This account still needs email verification. Continue to the verification page.'
                        : 'This account still needs email verification, but we could not deliver the code. Use Resend Code from the verification page.',
                ]);
            }

            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        [$user, $code, $cancelToken] = DB::transaction(function () use ($attributes) {
            $user = User::create([
                'name' => trim($attributes['first_name'].' '.$attributes['last_name']),
                'first_name' => $attributes['first_name'],
                'last_name' => $attributes['last_name'],
                'email' => $attributes['email'],
                'contact_number' => $attributes['contact_number'],
                'password' => Hash::make($attributes['password']),
                'role' => User::ROLE_GUEST,
            ]);

            return [
                $user,
                $this->issueVerificationCode($user),
                $this->issueRegistrationCancellationToken($user),
            ];
        });

        $mailSent = $this->deliverVerificationCode($user, $code);

        return response()->json([
            'email_verification_required' => true,
            'email' => $user->email,
            'registration_cancel_token' => $cancelToken,
            'user' => $user->publicProfile(),
            'message' => $mailSent
                ? 'Account created. We sent a verification code to your email address.'
                : 'Account created, but we could not deliver the verification email. Open the verification page and try Resend Code.',
        ], 201);
    }

    public function login(Request $request, SystemSettings $settings): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::guard('web')->attempt($credentials, $remember)) {
            app(AuditLogger::class)->log($request, 'authentication', 'failed_login', 'Failed login attempt.', null, [
                'email' => $credentials['email'] ?? null,
            ]);

            throw ValidationException::withMessages([
                'email' => ['Email or password is incorrect.'],
            ]);
        }

        if (! $request->user()->is_active) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['This account is inactive. Please contact '.$settings->get()['general']['resort_name'].'.'],
            ]);
        }

        if ($request->user()->normalizedRole() === User::ROLE_GUEST && ! $request->user()->email_verified_at) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                'email' => ['Please verify your email before signing in.'],
            ]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        app(AuditLogger::class)->log($request, 'authentication', 'login', 'User signed in.', $request->user());

        return response()->json([
            'user' => $request->user()->publicProfile(),
        ]);
    }

    public function verifyEmailCode(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);

        $result = DB::transaction(function () use ($attributes) {
            $user = $this->findCustomerByEmail($attributes['email']);
            if (! $user || $user->email_verified_at) {
                return 'invalid';
            }

            $user = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if ($user->email_verification_locked_at) {
                return 'locked';
            }

            if (! $user->email_verification_expires_at || $user->email_verification_expires_at->isPast() || ! $user->email_verification_code_hash) {
                return 'expired';
            }

            if (! Hash::check($attributes['code'], $user->email_verification_code_hash)) {
                $attempts = (int) $user->email_verification_attempts + 1;
                $locked = $attempts >= (int) config('auth.email_verification.max_attempts', 5);
                $user->forceFill([
                    'email_verification_attempts' => $attempts,
                    'email_verification_locked_at' => $locked ? now() : null,
                    'email_verification_code_hash' => $locked ? null : $user->email_verification_code_hash,
                    'email_verification_expires_at' => $locked ? null : $user->email_verification_expires_at,
                ])->save();

                return $locked ? 'locked' : 'incorrect';
            }

            $user->forceFill([
                'email_verified_at' => now(),
                'email_verification_code_hash' => null,
                'email_verification_expires_at' => null,
                'email_verification_sent_at' => null,
                'email_verification_attempts' => 0,
                'email_verification_locked_at' => null,
                'registration_cancel_token_hash' => null,
                'registration_cancel_token_issued_at' => null,
            ])->save();

            return 'success';
        });

        if ($result !== 'success') {
            $message = match ($result) {
                'locked' => 'Too many incorrect attempts. Please request a new verification code.',
                'expired' => 'This verification code has expired. Please request a new code.',
                'incorrect' => 'The verification code is incorrect.',
                default => 'This verification code is invalid or has expired.',
            };

            throw ValidationException::withMessages(['code' => [$message]]);
        }

        return response()->json([
            'message' => 'Email verified successfully.',
        ]);
    }

    public function resendEmailCode(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->findCustomerByEmail($attributes['email']);

        if (! $user || $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['We could not send a verification code for this email address.'],
            ]);
        }

        $cooldownSeconds = (int) config('auth.email_verification.resend_cooldown_seconds', 60);
        if ($user->email_verification_sent_at) {
            $retryAt = $user->email_verification_sent_at->copy()->addSeconds($cooldownSeconds);
            if (now()->lt($retryAt)) {
                return response()->json([
                    'message' => 'Please wait before requesting another code.',
                    'retry_after' => now()->diffInSeconds($retryAt),
                ], 429);
            }
        }

        [$code, $cancelToken] = DB::transaction(function () use ($user) {
            return [
                $this->issueVerificationCode($user),
                $this->issueRegistrationCancellationToken($user),
            ];
        });
        $mailSent = $this->deliverVerificationCode($user, $code);

        return response()->json([
            'message' => $mailSent
                ? 'A new verification code has been sent.'
                : 'The code was generated, but we could not deliver the email. Please try again shortly.',
            'registration_cancel_token' => $cancelToken,
        ]);
    }

    public function cancelPendingRegistration(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $rawToken = trim((string) $request->header('X-Registration-Cancel-Token', ''));
        $user = $this->findCustomerByEmail($attributes['email']);

        if (! $user || $user->email_verified_at || $rawToken === '' || ! $user->registration_cancel_token_hash || ! hash_equals($user->registration_cancel_token_hash, hash('sha256', $rawToken))) {
            throw ValidationException::withMessages([
                'email' => ['This pending registration could not be cancelled.'],
            ]);
        }

        if ($user->reservations()->exists()) {
            throw ValidationException::withMessages([
                'email' => ['This registration cannot be cancelled because it has reservation history.'],
            ]);
        }

        $user->delete();

        return response()->json([
            'message' => 'Pending registration cancelled.',
        ]);
    }

    private function findCustomerByEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
            ->where('role', User::ROLE_GUEST)
            ->first();
    }

    private function issueVerificationCode(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresInMinutes = (int) config('auth.email_verification.expire_minutes', 10);

        $user->forceFill([
            'email_verification_code_hash' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes($expiresInMinutes),
            'email_verification_sent_at' => now(),
            'email_verification_attempts' => 0,
            'email_verification_locked_at' => null,
        ])->save();

        return $code;
    }

    private function issueRegistrationCancellationToken(User $user): string
    {
        $token = Str::random(64);

        $user->forceFill([
            'registration_cancel_token_hash' => hash('sha256', $token),
            'registration_cancel_token_issued_at' => now(),
        ])->save();

        return $token;
    }

    private function deliverVerificationCode(User $user, string $code): bool
    {
        $expiresInMinutes = (int) config('auth.email_verification.expire_minutes', 10);

        try {
            Mail::to($user->email)->send(new EmailVerificationCode($user, $code, $expiresInMinutes));

            return true;
        } catch (\Throwable $exception) {
            Log::error('Email verification delivery failed.', [
                'user_id' => $user->id,
                'email' => $user->email,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->publicProfile(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,20}$/'],
        ], [
            'contact_number.regex' => 'Enter a valid contact number.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'name' => trim($attributes['first_name'].' '.$attributes['last_name']),
            'first_name' => $attributes['first_name'],
            'last_name' => $attributes['last_name'],
            'contact_number' => $attributes['contact_number'],
        ])->save();

        app(AuditLogger::class)->log($request, 'authentication', 'profile_updated', 'Guest profile updated.', $user, [
            'updated_fields' => ['first_name', 'last_name', 'contact_number'],
        ]);

        return response()->json([
            'user' => $user->fresh()->publicProfile(),
            'message' => 'Profile updated.',
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'The current password is incorrect.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => Hash::make($attributes['password']),
        ])->save();

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        app(AuditLogger::class)->log($request, 'authentication', 'password_updated', 'Guest password updated.', $user, [
            'updated_fields' => ['password'],
        ]);

        return response()->json([
            'message' => 'Password updated.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        app(AuditLogger::class)->log($request, 'authentication', 'logout', 'User signed out.', $user, actor: $user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Signed out.',
        ]);
    }
}
