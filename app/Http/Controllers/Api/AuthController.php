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
        if (! $user->role_id) return;
        // Always ensure the legacy active role is present in the pivot.
        // This fixes cases where pivot rows exist but the current `users.role_id`
        // is missing from `user_roles`.
        $user->roles()->syncWithoutDetaching([$user->role_id]);

        // Backfill pivot rows based on existing legacy module profile records.
        // This keeps the dashboard tiles accurate even if older accounts were
        // created before `user_roles` was introduced.
        $maybeRoles = [
            'owner' => $user->transportOwner()->exists(),
            'driver' => $user->driver()->exists(),
            'garage_owner' => $user->garages()->exists(),
            'technician' => $user->technicians()->exists(),
        ];

        foreach ($maybeRoles as $name => $shouldAttach) {
            if (! $shouldAttach) continue;
            $role = Role::firstOrCreate(['name' => $name]);
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
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
            'role'     => 'required|in:admin,owner,driver,customer,garage_owner,technician',
        ]);

        $email = strtolower(trim($validated['email']));
        $phone = isset($validated['phone']) && $validated['phone'] !== ''
            ? \App\Helpers\PhoneHelper::normalize($validated['phone'])
            : null;

        // Ensure phone is unique (normalized)
        if ($phone !== null) {
            $q = User::where('phone', $phone)->orWhere('whatsapp_number', $phone);
            $existingByPhone = $q->first();
            $existingUser = User::where('email', $email)->first();
            if ($existingByPhone && $existingByPhone->id !== ($existingUser?->id)) {
                return response()->json(['message' => 'This phone number is already registered.'], 422);
            }
        }

        $role = Role::firstOrCreate(['name' => $validated['role']]);
        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
            // Registration with an existing email is treated as a "role update" for the same user.
            // This enables the same identity to enroll into different module roles.
            if (! Hash::check($validated['password'], $existingUser->password)) {
                return response()->json(['message' => 'Email already used or password mismatch'], 422);
            }

            if ($existingUser->status !== 'active') {
                return response()->json(['message' => 'Account is not active'], 403);
            }

            $existingUser->update([
                'name'     => $validated['name'],
                'phone'    => $phone,
                'password' => Hash::make($validated['password']),
                'role_id'  => $role->id,
            ]);

            $user = $existingUser;
        } else {
            $user = User::create([
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'phone'    => $phone,
                'password' => Hash::make($validated['password']),
                'role_id'  => $role->id,
                'status'   => 'active',
            ]);
        }

        // Enroll the selected role without removing other roles.
        $user->roles()->syncWithoutDetaching([$role->id]);

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

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => $user->load(['role', 'roles', 'transportOwner', 'driver']),
        ], 201);
    }

    /**
     * Enroll the authenticated user into an additional role/module.
     * This is used by the tiles dashboard after login.
     */
    public function enrollRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role' => 'required|in:customer,owner,driver,garage_owner,technician',
            'company_name' => 'nullable|string|max:255',
            'license_number' => 'nullable|string|max:100',
            'address' => 'nullable|string',
            'garage_name' => 'nullable|string|max:255',
            'location' => 'nullable|string',
            'driver_license_number' => 'nullable|string|max:100',
            'driver_experience_years' => 'nullable|integer|min:0',
            'specialization' => 'nullable|string|max:255',
        ]);

        // Auto-create missing roles (older DBs).
        $role = Role::firstOrCreate(['name' => $validated['role']]);
        $user = $request->user();

        // Attach role without removing the others.
        $user->roles()->syncWithoutDetaching([$role->id]);

        // Keep legacy column in sync (some parts still depend on users.role_id).
        $user->update(['role_id' => $role->id]);

        if ($validated['role'] === 'owner') {
            if (! $user->transportOwner) {
                TransportOwner::create([
                    'user_id' => $user->id,
                    'company_name' => $validated['company_name'] ?: 'My Transport Company',
                    'license_number' => $validated['license_number'] ?: 'TEMP-LICENSE',
                    'address' => $validated['address'] ?? null,
                    'status' => 'pending',
                ]);
            }
        }

        if ($validated['role'] === 'driver') {
            // Driver requires an owner profile (TransportOwner).
            $transportOwner = $user->transportOwner;
            if (! $transportOwner) {
                // If the user didn't enroll as owner yet, allow driver enrollment
                // by creating a TransportOwner record from provided details.
                TransportOwner::create([
                    'user_id' => $user->id,
                    'company_name' => $validated['company_name'] ?: 'My Transport Company',
                    'license_number' => $validated['license_number'] ?: 'TEMP-LICENSE',
                    'address' => $validated['address'] ?? null,
                    'status' => 'pending',
                ]);

                $transportOwner = $user->transportOwner()->first();
            }

            if (! $transportOwner) {
                return response()->json(['message' => 'Transport owner profile missing'], 422);
            }

            $driverLicense = $validated['driver_license_number'] ?: 'TEMP-DL';
            $driverExperience = $validated['driver_experience_years'] ?? 0;

            $driver = Driver::firstOrCreate(
                ['user_id' => $user->id, 'owner_id' => $transportOwner->id],
                ['license_number' => $driverLicense, 'experience_years' => $driverExperience, 'status' => 'active']
            );

            // Update minimal fields if already exists.
            $driver->update([
                'license_number' => $driverLicense,
                'experience_years' => $driverExperience,
            ]);
        }

        if ($validated['role'] === 'garage_owner') {
            if (! $user->garages()->exists()) {
                Garage::create([
                    'owner_id' => $user->id,
                    'name' => $validated['garage_name'] ?: 'My Garage',
                    'location' => $validated['location'] ?? null,
                    'status' => 'active',
                ]);
            }
        }

        if ($validated['role'] === 'technician') {
            if (! $user->garages()->exists()) {
                // If the user didn't enroll as garage owner yet, allow technician enrollment
                // by creating a Garage record from provided details.
                Garage::create([
                    'owner_id' => $user->id,
                    'name' => $validated['garage_name'] ?: 'My Garage',
                    'location' => $validated['location'] ?? null,
                    'status' => 'active',
                ]);
            }

            $garage = $user->garages()->orderByDesc('id')->first();

            if (! $garage) {
                return response()->json(['message' => 'Garage profile missing'], 422);
            }

            $spec = $validated['specialization'] ?? 'General';

            Technician::firstOrCreate(
                ['user_id' => $user->id, 'garage_id' => $garage->id],
                ['specialization' => $spec, 'status' => 'active']
            );
        }

        $this->ensurePivotRoles($user);

        return response()->json([
            'message' => 'Role enrolled',
            'user' => $user->fresh()->load(['role', 'roles', 'transportOwner', 'driver', 'garages']),
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

        $user = $this->findUserByIdentifier($identifier);
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        Auth::login($user);
        if ($user->status !== 'active') {
            Auth::logout();
            return response()->json(['message' => 'Account is not active'], 403);
        }

        $this->ensurePivotRoles($user);

        $user->tokens()->delete();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => $user->load(['role', 'roles', 'transportOwner', 'driver']),
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
        $user = $user->load(['role', 'roles', 'transportOwner', 'driver']);

        return response()->json(['user' => $user]);
    }

    /** GET /me — used by mobile app */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensurePivotRoles($user);
        $user = $user->load(['role', 'roles', 'transportOwner', 'driver']);
        return response()->json(['user' => $user]);
    }

    /** PUT /profile — update name, phone, whatsapp_number */
    public function updateProfile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('Phone number must be 10-13 digits.');
                    }
                }
            }],
            'whatsapp_number'  => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[\d\s\+\-\(\)\.]{10,20}$/', function ($attribute, $value, $fail) {
                if ($value) {
                    $digits = preg_replace('/\D/', '', $value);
                    if (strlen($digits) < 10 || strlen($digits) > 13) {
                        $fail('WhatsApp number must be 10-13 digits.');
                    }
                }
            }],
        ]);

        $user = $request->user();
        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => $user->fresh()->load(['role', 'transportOwner', 'driver']),
        ]);
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
    private function sendOtpSms(string $phone, string $otp, ?string &$error): bool
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

        $smsText = "Your Trans-Cargo OTP is: {$otp}\nExpires in 10 minutes. Do not share.";

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
