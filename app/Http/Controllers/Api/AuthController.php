<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetOtp;
use App\Models\Role;
use App\Models\Garage;
use App\Models\Driver;
use App\Models\Technician;
use App\Models\TransportOwner;
use App\Models\User;
use App\Services\EmploymentService;
use App\Support\AuthUserPresenter;
use Vonage\Client as VonageClient;
use Vonage\Client\Credentials\Basic as VonageBasic;
use Vonage\SMS\Message\SMS as VonageSMS;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Rule;
use Resend\Laravel\Facades\Resend;

class AuthController extends Controller
{
    private function ensurePivotRoles(User $user): void
    {
        // If the user_roles pivot is empty (common for accounts created before
        // the pivot migration), backfill from the single active role_id.
        // This keeps older accounts compatible with the new multi-role UI.
        if ($user->role_id) {
            $legacyRole = Role::find($user->role_id);
            // Skip if a pivot row already exists (including inactive / unenrolled).
            if ($legacyRole && ! $user->roles()->where('roles.id', $legacyRole->id)->exists()) {
                $user->enrollCapability($legacyRole);
            }
        }

        // Backfill pivot rows based on existing legacy module profile records.
        // This keeps the dashboard tiles accurate even if older accounts were
        // created before `user_roles` was introduced.
        // Do not reactivate capabilities the user has explicitly unenrolled.
        $maybeRoles = [
            'owner' => $user->transportOwner()->exists(),
            // Driver capability is granted by fleet owners on hire — not by a seeker profile alone.
            'driver' => $user->driver()->whereNotNull('owner_id')->exists(),
            'garage_owner' => $user->garages()->exists(),
            'technician' => $user->technicians()->exists(),
        ];

        foreach ($maybeRoles as $name => $shouldAttach) {
            if (! $shouldAttach) {
                continue;
            }
            if ($user->roles()->where('roles.name', $name)->exists()) {
                continue;
            }
            $user->enrollCapability($name);
        }
    }

    private function authUserPayload(User $user): array
    {
        return AuthUserPresenter::present($user->fresh());
    }

    /** Return all common formats for a Tanzanian phone (handles legacy DB formats). */
    private function phoneFormatsForLookup(string $input): array
    {
        $normalized = \App\Helpers\PhoneHelper::normalize($input) ?? $input;
        $formats = [$normalized];
        if (preg_match('/^0(\d{9})$/', $normalized, $m)) {
            $formats[] = '255' . $m[1];
            $formats[] = '+255' . $m[1];
            $formats[] = $m[1];
        }
        return array_unique(array_filter($formats));
    }

    /** Find user by email or phone (normalized). Used for login and password reset. */
    private function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', strtolower($identifier))->first();
        }
        $formats = $this->phoneFormatsForLookup($identifier);
        return User::where(function ($q) use ($formats) {
            $q->whereIn('phone', $formats)->orWhereIn('whatsapp_number', $formats);
        })->first();
    }

    private function findUserByPhone(string $phone): ?User
    {
        $formats = $this->phoneFormatsForLookup($phone);

        return User::where(function ($q) use ($formats) {
            $q->whereIn('phone', $formats)->orWhereIn('whatsapp_number', $formats);
        })->first();
    }

    /**
     * Send OTP to a number that already belongs to another account so the
     * new registrant can prove they hold the SIM (numbers can be reassigned).
     */
    public function claimPhone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/'],
        ]);

        $phone = \App\Helpers\PhoneHelper::normalize($validated['phone']) ?? trim($validated['phone']);
        $holder = $this->findUserByPhone($phone);

        if (! $holder) {
            return response()->json([
                'message' => 'This phone number is available. Continue registration.',
                'code' => 'PHONE_AVAILABLE',
                'phone' => $phone,
            ]);
        }

        if (! app()->environment('local')) {
            $rateLimitKey = 'otp-claim:' . $phone;
            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);

                return response()->json([
                    'message' => "Too many OTP requests. Please wait {$seconds} seconds before trying again.",
                ], 429);
            }
            RateLimiter::hit($rateLimitKey, 900);
        }

        $devOtp = $this->issuePhoneClaimOtp($phone);

        return response()->json([
            'message' => 'OTP sent to this number. After verification it can be moved to your new account.',
            'code' => 'PHONE_REQUIRES_CLAIM',
            'phone' => $phone,
            'dev_otp' => $devOtp,
        ]);
    }

    /** @return string|null Plain OTP in local so the UI can show it; null in production. */
    private function issuePhoneClaimOtp(string $phone): ?string
    {
        PasswordResetOtp::where('identifier', $phone)
            ->where('type', 'claim')
            ->where('used', false)
            ->update(['used' => true]);

        $plainOtp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        PasswordResetOtp::create([
            'identifier' => $phone,
            'type' => 'claim',
            'otp' => Hash::make($plainOtp),
            'used' => false,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $error = null;
        $this->sendOtpSms($phone, $plainOtp, $error, 'claim');
        if ($error) {
            Log::warning("Phone-claim OTP SMS failed for {$phone}: {$error}");
        }

        if (app()->environment('local')) {
            Log::info("════ DEV PHONE-CLAIM OTP for {$phone} ════ {$plainOtp} ════");

            return $plainOtp;
        }

        return null;
    }

    private function consumePhoneClaimOtp(string $phone, string $otp): bool
    {
        $formats = $this->phoneFormatsForLookup($phone);
        $record = PasswordResetOtp::where('type', 'claim')
            ->where('used', false)
            ->whereIn('identifier', $formats)
            ->latest()
            ->first();

        if (! $record || $record->isExpired() || ! Hash::check($otp, $record->otp)) {
            return false;
        }

        $record->update(['used' => true]);

        return true;
    }

    private function releasePhoneFromPreviousOwner(User $previous, string $phone): void
    {
        $normalized = \App\Helpers\PhoneHelper::normalize($phone) ?? $phone;
        $formats = $this->phoneFormatsForLookup($normalized);
        $updates = [];

        $prevPhone = \App\Helpers\PhoneHelper::normalize((string) $previous->phone);
        if ($prevPhone && in_array($prevPhone, $formats, true)) {
            $updates['phone'] = null;
        }

        $prevWa = \App\Helpers\PhoneHelper::normalize((string) $previous->whatsapp_number);
        if ($prevWa && in_array($prevWa, $formats, true)) {
            $updates['whatsapp_number'] = null;
        }

        if ($updates !== []) {
            $previous->update($updates);
        }
    }

    private function sendPhoneTransferredEmail(User $previous, string $phone): void
    {
        if (! $previous->email) {
            return;
        }

        $masked = $this->maskPhone($phone);
        $html = $this->phoneTransferredEmailHtml($previous->name ?: 'there', $masked);

        try {
            $apiKey = config('resend.api_key');
            if ($apiKey && $apiKey !== 'your-resend-api-key') {
                $this->clearProxyEnv();
                $deliverTo = app()->environment('local')
                    ? ['mchaurembo@gmail.com']
                    : [$previous->email];

                Resend::emails()->send([
                    'from' => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
                    'to' => $deliverTo,
                    'subject' => 'Safari Hub — Phone number moved from your account',
                    'html' => $html,
                    'text' => "Hi {$previous->name},\n\nThe phone number {$masked} was verified by SMS and is now linked to a different Safari Hub account. Mobile numbers can be reassigned by the operator.\n\nYour email login still works. Add a new phone number in Profile if you still use Safari Hub.\n\n— Safari Hub",
                ]);

                return;
            }

            \Illuminate\Support\Facades\Mail::html($html, function ($message) use ($previous) {
                $message->to($previous->email, $previous->name)
                    ->subject('Safari Hub — Phone number moved from your account');
            });
        } catch (\Exception $e) {
            Log::warning("Phone-transfer email failed for user {$previous->id}: {$e->getMessage()}");
        }
    }

    private function sendEmailChangedNotice(User $user, string $oldEmail): void
    {
        $safeName = e($user->name ?: 'there');
        $safeOld = e($oldEmail);
        $safeNew = e($user->email);
        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
          <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;">
            <h1 style="color:#7D1B28;font-size:20px;">Safari Hub</h1>
            <p>Hi {$safeName},</p>
            <p>The email on your Safari Hub account was changed from <strong>{$safeOld}</strong> to <strong>{$safeNew}</strong>.</p>
            <p>If you did not do this, sign in with your phone (if still linked) or contact support.</p>
          </div>
        </body>
        </html>
        HTML;

        try {
            $apiKey = config('resend.api_key');
            $deliverTo = app()->environment('local')
                ? ['mchaurembo@gmail.com']
                : [$oldEmail];
            if ($apiKey && $apiKey !== 'your-resend-api-key') {
                $this->clearProxyEnv();
                Resend::emails()->send([
                    'from' => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
                    'to' => $deliverTo,
                    'subject' => 'Safari Hub — Email address changed',
                    'html' => $html,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Email-changed notice failed for user {$user->id}: {$e->getMessage()}");
        }
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '****';
        }

        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    private function profileUpdateQueryError(QueryException $e): JsonResponse
    {
        $msg = $e->getMessage();
        Log::error('Profile update failed', ['error' => $msg]);

        if (str_contains($msg, 'already registered')) {
            if (str_contains(strtolower($msg), 'whatsapp')) {
                return response()->json([
                    'message' => 'This WhatsApp number is already registered to another account.',
                ], 422);
            }

            return response()->json([
                'message' => 'This phone number is already registered to another account.',
            ], 422);
        }

        if (str_contains($msg, 'chk_phone_10_to_13_digits')
            || str_contains($msg, 'chk_whatsapp_10_to_13_digits')
            || str_contains($msg, 'Check constraint')) {
            return response()->json([
                'message' => 'Phone or WhatsApp number must be 10-13 digits. Fix the number and try again.',
            ], 422);
        }

        if (str_contains($msg, 'Unknown column')) {
            return response()->json([
                'message' => 'Profile update is not available until the server database is updated. Please contact support.',
            ], 503);
        }

        return response()->json([
            'message' => 'Could not update profile. Please try again or contact support.',
        ], 500);
    }

    private function phoneTransferredEmailHtml(string $name, string $maskedPhone): string
    {
        $safeName = e($name);
        $safePhone = e($maskedPhone);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
          <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#7D1B28;padding:24px 32px;">
              <h1 style="color:#fff;margin:0;font-size:22px;">Safari Hub</h1>
            </div>
            <div style="padding:32px;">
              <p style="color:#333;font-size:16px;margin-top:0;">Hi <strong>{$safeName}</strong>,</p>
              <p style="color:#555;font-size:15px;line-height:1.5;">The phone number <strong>{$safePhone}</strong> was verified by SMS and is now linked to a different Safari Hub account.</p>
              <p style="color:#555;font-size:15px;line-height:1.5;">This can happen when a mobile number is reassigned by the operator. Your email login is unchanged. Add a new number in Profile if you still use Safari Hub.</p>
            </div>
            <div style="background:#f9f9f9;padding:16px 32px;text-align:center;">
              <p style="color:#aaa;font-size:12px;margin:0;">© Safari Hub</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|string|email|max:255',
            'phone'    => ['nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('Phone number must be 10-13 digits.');
                    }
                }
            }],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role'     => 'required|in:admin,owner,customer,garage_owner,technician',
            'phone_otp' => 'nullable|string|size:6',
        ]);

        $email = strtolower(trim($validated['email']));
        $phone = isset($validated['phone']) && $validated['phone'] !== ''
            ? \App\Helpers\PhoneHelper::normalize($validated['phone'])
            : null;

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && ! Hash::check($validated['password'], $existingUser->password)) {
            return response()->json(['message' => 'Email already used or password mismatch'], 422);
        }

        if ($existingUser && $existingUser->status !== 'active') {
            return response()->json(['message' => 'Account is not active'], 403);
        }

        $previousPhoneOwner = null;

        // Phone numbers can be reassigned by the operator. If this number is on
        // another account, require OTP from the handset, then move it.
        if ($phone !== null) {
            $holder = $this->findUserByPhone($phone);
            if ($holder && $holder->id !== ($existingUser?->id)) {
                $phoneOtp = $validated['phone_otp'] ?? null;
                if (! $phoneOtp) {
                    $devOtp = $this->issuePhoneClaimOtp($phone);

                    return response()->json([
                        'message' => 'This phone number is on another account. Enter the OTP sent to the number to move it here. The previous account will be notified by email.',
                        'code' => 'PHONE_REQUIRES_CLAIM',
                        'phone' => $phone,
                        'dev_otp' => $devOtp,
                    ], 409);
                }

                if (! $this->consumePhoneClaimOtp($phone, $phoneOtp)) {
                    return response()->json([
                        'message' => 'Invalid or expired OTP. Request a new code and try again.',
                        'code' => 'PHONE_CLAIM_OTP_INVALID',
                    ], 422);
                }

                $this->releasePhoneFromPreviousOwner($holder, $phone);
                $previousPhoneOwner = $holder;
            }
        }

        $role = Role::firstOrCreate(['name' => $validated['role']]);

        if ($existingUser) {
            // Registration with an existing email is treated as a "role update" for the same user.
            // This enables the same identity to enroll into different module roles.
            $existingUser->update([
                'name'     => $validated['name'],
                'phone'    => $phone,
                'password' => Hash::make($validated['password']),
            ]);

            $user = $existingUser;
        } else {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'phone'    => $phone,
                'password' => Hash::make($validated['password']),
                'status'   => 'active',
            ]);
        }

        // Enroll capability — legacy role_id is mirrored from preferred capability.
        $user->enrollCapability($role);
        $user->refreshLegacyPrimaryRole();

        if ($validated['role'] === 'owner') {
            // If the user already exists, don't duplicate transport owner records.
            if (! $user->transportOwner) {
                TransportOwner::create([
                    'user_id'        => $user->id,
                    'company_name'   => $request->input('company_name', 'My Transport Company'),
                    'license_number' => $request->input('license_number', 'TEMP-LICENSE'),
                    'address'        => $request->input('address'),
                    'status'         => 'pending',
                ]);
            }
        }

        if ($validated['role'] === 'garage_owner') {
            // Minimal garage creation for coming-soon UI.
            // Technician assignment is handled later (needs a garage_id).
            if (! $user->garages()->exists()) {
                Garage::create([
                    'owner_id' => $user->id,
                    'name'     => $request->input('garage_name', 'My Garage'),
                    'location' => $request->input('location'),
                    'status'   => 'active',
                ]);
            }
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        if ($previousPhoneOwner && $phone) {
            $this->sendPhoneTransferredEmail($previousPhoneOwner, $phone);
        }

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => $this->authUserPayload($user),
        ], 201);
    }

    /**
     * Enroll the authenticated user into an additional capability.
     * Does NOT create fleet/garage resources — use create/join endpoints next.
     */
    public function enrollRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:customer,owner,garage_owner,technician',
        ]);

        $role = Role::firstOrCreate(['name' => $validated['role']]);
        $user = $request->user();

        $user->enrollCapability($role);
        $this->ensurePivotRoles($user);
        $user->refreshLegacyPrimaryRole();

        $nextStep = match ($validated['role']) {
            'owner' => $user->transportOwner ? null : 'create_fleet',
            'garage_owner' => $user->garages()->exists() ? null : 'create_garage',
            'technician' => $user->technicians()->exists() ? null : 'join_garage',
            default => null,
        };

        return response()->json([
            'message' => 'Capability enrolled',
            'next_step' => $nextStep,
            'user' => $this->authUserPayload($user),
        ]);
    }

    /**
     * Remove a self-enrolled capability. Admin and driver stay employer/admin-granted.
     * Does not delete bookings, payments, fleet, garage, or other history —
     * re-enrolling the same capability restores access to those records.
     */
    public function unenrollRole(Request $request, EmploymentService $employment): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:customer,owner,garage_owner,technician',
        ]);

        $user = $request->user();
        $role = $validated['role'];

        if (! $user->hasCapability($role)) {
            return response()->json(['message' => 'You are not enrolled in this capability'], 422);
        }

        if (count($user->activeCapabilityCodes()) <= 1) {
            return response()->json(['message' => 'You must keep at least one capability'], 422);
        }

        $releasedDrivers = 0;

        if ($role === 'owner') {
            $fleet = $user->transportOwner;
            if ($fleet) {
                if ($employment->fleetHasActiveWork($fleet)) {
                    return response()->json([
                        'message' => 'Finish or cancel active trips before leaving Transport Owner. Drivers stay assigned until then.',
                    ], 422);
                }
                $releasedDrivers = $employment->releaseFleetDrivers($fleet);
            }
        }

        $user->unenrollCapability($role);

        $message = 'Capability removed. Bookings, payments, and history stay on this account.';
        if ($releasedDrivers > 0) {
            $message = "Capability removed. {$releasedDrivers} driver(s) were released from your vehicles. They can apply to jobs again. Fleet and payment history stay on this account.";
        }

        return response()->json([
            'message' => $message,
            'history_preserved' => true,
            'released_drivers' => $releasedDrivers,
            'user' => $this->authUserPayload($user),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required_without:email|nullable|string',
            'email'      => 'required_without:identifier|nullable|string',
            'password'   => 'required',
        ], [
            'identifier.required_without' => 'Email or phone number is required.',
            'email.required_without'      => 'Email or phone number is required.',
        ]);

        $identifier = trim((string) ($validated['identifier'] ?? $validated['email'] ?? ''));
        if ($identifier === '') {
            return response()->json(['message' => 'Email or phone number is required.'], 422);
        }

        $password = (string) $validated['password'];
        $user = $this->findUserByIdentifier($identifier);
        $hash = $user?->getRawOriginal('password') ?? $user?->getAttributes()['password'] ?? null;
        if (! $user || ! is_string($hash) || $hash === '' || ! Hash::check($password, $hash)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        Auth::login($user);
        if ($user->status !== 'active') {
            Auth::logout();
            return response()->json(['message' => 'Account is not active'], 403);
        }

        $this->ensurePivotRoles($user);
        $user->refreshLegacyPrimaryRole();

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => $this->authUserPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensurePivotRoles($user);
        $user->refreshLegacyPrimaryRole();

        return response()->json(['user' => $this->authUserPayload($user)]);
    }

    /** GET /me — used by mobile app */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensurePivotRoles($user);
        $user->refreshLegacyPrimaryRole();

        return response()->json(['user' => $this->authUserPayload($user)]);
    }

    /** PUT /profile — update name, email, phone, whatsapp_number */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:255',
            'email'           => 'sometimes|string|email|max:255',
            'phone'           => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('Phone number must be 10-13 digits.');
                    }
                }
            }],
            'whatsapp_number' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('WhatsApp number must be 10-13 digits.');
                    }
                }
            }],
            'phone_otp'       => 'nullable|string|size:6',
        ]);

        $user = $request->user();
        $updates = [];
        $previousPhoneOwner = null;
        $previousEmail = null;

        if (array_key_exists('name', $validated) && $validated['name'] !== $user->name) {
            $updates['name'] = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $email = strtolower(trim($validated['email']));
            $taken = User::where('email', $email)->where('id', '!=', $user->id)->exists();
            if ($taken) {
                return response()->json(['message' => 'This email is already registered to another account.'], 422);
            }
            if ($email !== strtolower((string) $user->email)) {
                $previousEmail = $user->email;
                $updates['email'] = $email;
            }
        }

        $phone = null;
        if (array_key_exists('phone', $validated)) {
            $phone = $validated['phone'] !== null && $validated['phone'] !== ''
                ? \App\Helpers\PhoneHelper::normalize($validated['phone'])
                : null;
            $currentPhone = \App\Helpers\PhoneHelper::normalize((string) $user->phone);

            if ($phone && $phone !== $currentPhone) {
                $holder = $this->findUserByPhone($phone);
                if ($holder && $holder->id !== $user->id) {
                    $phoneOtp = $validated['phone_otp'] ?? null;
                    if (! $phoneOtp) {
                        $devOtp = $this->issuePhoneClaimOtp($phone);

                        return response()->json([
                            'message' => 'This phone number is on another account. Enter the OTP sent to the number to move it here. The previous account will be notified by email.',
                            'code' => 'PHONE_REQUIRES_CLAIM',
                            'phone' => $phone,
                            'dev_otp' => $devOtp,
                        ], 409);
                    }
                    if (! $this->consumePhoneClaimOtp($phone, $phoneOtp)) {
                        return response()->json([
                            'message' => 'Invalid or expired OTP. Request a new code and try again.',
                            'code' => 'PHONE_CLAIM_OTP_INVALID',
                        ], 422);
                    }
                    $this->releasePhoneFromPreviousOwner($holder, $phone);
                    $previousPhoneOwner = $holder;
                }
            }
            if ($phone !== $currentPhone) {
                $updates['phone'] = $phone;
            }
        }

        if (array_key_exists('whatsapp_number', $validated)) {
            $whatsapp = $validated['whatsapp_number'] !== null && $validated['whatsapp_number'] !== ''
                ? \App\Helpers\PhoneHelper::normalize($validated['whatsapp_number'])
                : null;
            $currentWhatsapp = \App\Helpers\PhoneHelper::normalize((string) $user->whatsapp_number);
            if ($whatsapp) {
                $waHolder = $this->findUserByPhone($whatsapp);
                $incomingPhone = $updates['phone'] ?? \App\Helpers\PhoneHelper::normalize((string) $user->phone);
                if ($waHolder && $waHolder->id !== $user->id && $whatsapp !== $incomingPhone) {
                    return response()->json([
                        'message' => 'This WhatsApp number is already registered to another account.',
                    ], 422);
                }
            }
            if ($whatsapp !== $currentWhatsapp) {
                $updates['whatsapp_number'] = $whatsapp;
            }
        }

        if ($updates === []) {
            return $this->profileUpdateResponse($user, 'Profile updated successfully');
        }

        try {
            $user->update($updates);
        } catch (QueryException $e) {
            return $this->profileUpdateQueryError($e);
        }

        if ($previousPhoneOwner && $phone) {
            $this->sendPhoneTransferredEmail($previousPhoneOwner, $phone);
        }

        if ($previousEmail && $previousEmail !== ($updates['email'] ?? $previousEmail)) {
            $this->sendEmailChangedNotice($user->fresh(), $previousEmail);
        }

        return $this->profileUpdateResponse($user->fresh(), 'Profile updated successfully');
    }

    private function profileUpdateResponse(User $user, string $message): JsonResponse
    {
        try {
            return response()->json([
                'message' => $message,
                'user'    => $this->authUserPayload($user),
            ]);
        } catch (\Throwable $e) {
            Log::error('Profile saved but auth payload failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $message,
                'user'    => $user->only(['id', 'name', 'email', 'phone', 'whatsapp_number', 'role_id', 'status']),
            ]);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required',
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect'], 422);
        }

        $user->update(['password' => $validated['password']]);
        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Password changed successfully',
            'token'   => $token,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────
     |  FORGOT PASSWORD — Step 1: send OTP
     ─────────────────────────────────────────────────────────────── */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => 'required|string',
            'type'       => 'required|in:email,phone',
        ]);

        $type       = $request->type;

        // Normalize identifier: lowercase for email, normalize phone (+255/255/0)
        $identifier = trim($request->identifier);
        if ($type === 'email') {
            $identifier = strtolower($identifier);
        } else {
            $identifier = \App\Helpers\PhoneHelper::normalize($identifier) ?? $identifier;
        }

        // ── Rate limiting: max 5 OTP requests per identifier per 15 minutes (production only) ──
        if (!app()->environment('local')) {
            $rateLimitKey = 'otp:' . $identifier;
            if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                return response()->json([
                    'message' => "Too many OTP requests. Please wait {$seconds} seconds before trying again.",
                ], 429);
            }
            RateLimiter::hit($rateLimitKey, 900); // 15-minute window
        }

        // Find the user — always return a vague success to prevent user enumeration
        $user = $type === 'email'
            ? User::where('email', $identifier)->first()
            : User::where(function ($q) use ($identifier) {
                $formats = $this->phoneFormatsForLookup($identifier);
                $q->whereIn('phone', $formats)->orWhereIn('whatsapp_number', $formats);
            })->first();

        if (!$user) {
            return response()->json([
                'message' => 'If an account with that ' . $type . ' exists, an OTP has been sent.',
            ]);
        }

        // Invalidate all previous unused OTPs for this identifier
        PasswordResetOtp::where('identifier', $identifier)
            ->where('type', $type)
            ->where('used', false)
            ->update(['used' => true]);

        // Generate a cryptographically secure 6-digit OTP
        $plainOtp  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hashedOtp = Hash::make($plainOtp); // store hashed, never plain

        PasswordResetOtp::create([
            'identifier' => $identifier,
            'type'       => $type,
            'otp'        => $hashedOtp,
            'used'       => false,
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        // ── Send OTP ──────────────────────────────────────────────
        $sent = false;
        $error = null;

        if ($type === 'email') {
            $sent = $this->sendOtpEmail($user, $identifier, $plainOtp, $error);
        } else {
            $sent = $this->sendOtpSms($identifier, $plainOtp, $error);
        }

        if (!$sent) {
            Log::warning("OTP delivery failed [{$type}] to {$identifier}: {$error}");
        }

        // Always return OTP in local dev (shown on screen as large dev-mode box)
        $devOtp = app()->environment('local') ? $plainOtp : null;

        // Also log it clearly so it's always findable in the log
        if (app()->environment('local')) {
            Log::info("════ DEV OTP for {$identifier} ════ {$plainOtp} ════");
        }

        return response()->json([
            'message' => 'OTP sent successfully. Check your ' . $type . '.',
            'dev_otp' => $devOtp,
        ]);
    }

    /* ── Send OTP via Resend (email) ─────────────────────────────── */
    private function sendOtpEmail(User $user, string $to, string $otp, ?string &$error): bool
    {
        $apiKey = config('resend.api_key');

        if ($apiKey && $apiKey !== 'your-resend-api-key') {
            try {
                // Bypass any proxy (e.g. Cursor IDE proxy) for outbound API calls
                $this->clearProxyEnv();

                // In local dev with onboarding@resend.dev sender, Resend only
                // delivers to the Resend account owner's email. We force-redirect
                // all test emails to that address so delivery always works.
                $deliverTo = app()->environment('local')
                    ? ['mchaurembo@gmail.com']
                    : [$to];

                Resend::emails()->send([
                    'from'    => config('mail.from.name') . ' <' . config('mail.from.address') . '>',
                    'to'      => $deliverTo,
                    'subject' => 'Trans-Cargo — OTP for ' . $to,
                    'html'    => $this->otpEmailHtml($user->name, $otp),
                    'text'    => "Hi {$user->name},\n\nYour Trans-Cargo password reset OTP is: {$otp}\n\nThis code expires in 10 minutes. Do not share it with anyone.\n\n— Trans-Cargo Team",
                ]);
                return true;
            } catch (\Exception $e) {
                $error = $e->getMessage();
                return false;
            }
        }

        // Fallback: Laravel built-in mailer (SMTP / log / etc.)
        try {
            \Illuminate\Support\Facades\Mail::html(
                $this->otpEmailHtml($user->name, $otp),
                function ($message) use ($to, $user) {
                    $message->to($to, $user->name)
                            ->subject('Trans-Cargo — Your Password Reset OTP');
                }
            );
            return true;
        } catch (\Exception $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /* ── Clear proxy env vars injected by Cursor IDE ─────────────── */
    private function clearProxyEnv(): void
    {
        foreach (['http_proxy', 'https_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY'] as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    /* ── Send OTP via SMS (Vonage) ───────────────────────────────────── */
    private function sendOtpSms(string $phone, string $otp, ?string &$error, string $purpose = 'reset'): bool
    {
        // Normalize phone to E.164 (+255XXXXXXXXX for Tanzania)
        $normalized = $phone;
        if (preg_match('/^0[67]\d{8}$/', $phone)) {
            $normalized = '+255' . substr($phone, 1);
        } elseif (preg_match('/^255[67]\d{8}$/', $phone)) {
            $normalized = '+' . $phone;
        } elseif (!str_starts_with($phone, '+')) {
            $normalized = '+' . $phone;
        }

        $smsText = $purpose === 'claim'
            ? "Safari Hub OTP: {$otp}. Use it to move this number to your new account. Expires in 10 minutes. Do not share."
            : "Your Safari Hub OTP is: {$otp}\nExpires in 10 minutes. Do not share.";

        $this->clearProxyEnv();

        $vonageKey    = config('services.vonage.key');
        $vonageSecret = config('services.vonage.secret');
        $vonageFrom   = config('services.vonage.from', 'TransCargo');

        if (!$vonageKey || !$vonageSecret) {
            Log::warning("Vonage not configured — OTP for {$normalized}: {$otp}");
            return true;
        }

        try {
            $vonage = new VonageClient(new VonageBasic($vonageKey, $vonageSecret));
            $vonage->sms()->send(new VonageSMS($normalized, $vonageFrom, $smsText));
            Log::info("SMS OTP sent via Vonage to {$normalized}");
            return true;
        } catch (\Exception $e) {
            $error = $e->getMessage();
            Log::error("Vonage SMS failed to {$normalized}: {$error}");
            return false;
        }
    }

    /* ── OTP email HTML template ─────────────────────────────────── */
    private function otpEmailHtml(string $name, string $otp): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"></head>
        <body style="font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:20px;">
          <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
            <div style="background:#1677ff;padding:24px 32px;">
              <h1 style="color:#fff;margin:0;font-size:22px;">🚛 Trans-Cargo</h1>
            </div>
            <div style="padding:32px;">
              <p style="color:#333;font-size:16px;margin-top:0;">Hi <strong>{$name}</strong>,</p>
              <p style="color:#555;font-size:15px;">Use the OTP below to reset your password. It expires in <strong>10 minutes</strong>.</p>
              <div style="text-align:center;margin:28px 0;">
                <span style="display:inline-block;background:#f0f7ff;border:2px dashed #1677ff;border-radius:10px;padding:16px 40px;font-size:36px;font-weight:900;letter-spacing:12px;color:#1677ff;font-family:monospace;">{$otp}</span>
              </div>
              <p style="color:#888;font-size:13px;">If you did not request this, you can safely ignore this email. Do not share this code with anyone.</p>
            </div>
            <div style="background:#f9f9f9;padding:16px 32px;text-align:center;">
              <p style="color:#aaa;font-size:12px;margin:0;">© Trans-Cargo · Secure Password Reset</p>
            </div>
          </div>
        </body>
        </html>
        HTML;
    }

    /* ──────────────────────────────────────────────────────────────
     |  RESET PASSWORD — Step 2: verify OTP + set new password
     ─────────────────────────────────────────────────────────────── */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'identifier'        => 'required|string',
            'type'              => 'required|in:email,phone',
            'otp'               => 'required|string|size:6',
            'password'          => ['required', 'confirmed', Password::min(8)],
        ]);

        $type       = $request->type;
        $identifier = trim($request->identifier);
        if ($type === 'email') {
            $identifier = strtolower($identifier);
        } else {
            $identifier = \App\Helpers\PhoneHelper::normalize($identifier) ?? $identifier;
        }

        // Fetch the latest unused OTP record and verify with Hash::check (OTP is stored hashed)
        $record = PasswordResetOtp::where('identifier', $identifier)
            ->where('type', $type)
            ->where('used', false)
            ->latest()
            ->first();

        if (!$record || $record->isExpired() || !Hash::check($request->otp, $record->otp)) {
            return response()->json([
                'message' => 'Invalid or expired OTP. Please request a new one.',
            ], 422);
        }

        // Find the user — login uses email, so we must update the same user
        $user = $type === 'email'
            ? User::where('email', $identifier)->first()
            : User::where(function ($q) use ($identifier) {
                $formats = $this->phoneFormatsForLookup($identifier);
                $q->whereIn('phone', $formats)->orWhereIn('whatsapp_number', $formats);
            })->first();

        if (!$user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Mark OTP as used
        $record->update(['used' => true]);

        // Update password via DB to bypass User model cast (avoids double-hash / cast quirks)
        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($request->password),
        ]);
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password reset successfully. You can now log in with your new password.',
        ]);
    }
}
