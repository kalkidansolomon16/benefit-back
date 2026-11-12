<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Mail\PasswordResetCodeMail;
use App\Models\Employee;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Services\RecaptchaService;
use App\Services\SessionTokenService;
use App\Services\TelegramService;
use App\Services\OneTimeCodeService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    /**
     * Number of consecutive failed attempts (per account) before locking out.
     */
    private const LOGIN_MAX_ATTEMPTS = 5;

    /**
     * Window in which failed attempts accumulate toward a lockout.
     */
    private const LOGIN_ATTEMPTS_WINDOW_MINUTES = 15;

    /**
     * After this many failed attempts for an account, a valid reCAPTCHA
     * token must accompany further login attempts.
     */
    private const CAPTCHA_FAILURE_THRESHOLD = 3;

    /**
     * Roles that must complete TOTP two-factor authentication to log in.
     */
    private const MFA_EXTRA_ROLES = ['company_hr', 'gym_partner', 'employee'];

    /**
     * Roles whose second step is a code sent to their email: gym owners
     * (partner app) and company employees (member app and employee portal).
     */
    private const EMAIL_MFA_ROLES = ['gym_partner', 'employee'];

    #[OA\Post(
        path: '/auth/login',
        tags: ['Auth'],
        summary: 'Login',
        description: 'Authenticate a user and return a Bearer token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@fitaccess.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Account inactive or banned'),
            new OA\Response(response: 422, description: 'Invalid credentials'),
        ]
    )]
    public function login(Request $request, RecaptchaService $recaptcha): JsonResponse
    {
        $request->validate([
            'email'       => 'required|email',
            'password'    => 'required|string',
            'device_name' => 'nullable|string|max:100',
        ]);

        $email   = strtolower((string) $request->input('email'));
        $lockKey = "login_lockout:{$email}";
        $failKey = "login_fails:{$email}";

        if ($lockedUntil = Cache::get($lockKey)) {
            $retryAfter = max($lockedUntil - time(), 1);

            return response()->json([
                'message' => 'Too many failed login attempts. Please try again in ' . ceil($retryAfter / 60) . ' minute(s).',
            ], 429)->header('Retry-After', $retryAfter);
        }

        // After repeated failures, require a human-verification challenge
        // before spending another guess against this account.
        $priorFailures = (int) Cache::get($failKey, 0);
        if ($priorFailures >= self::CAPTCHA_FAILURE_THRESHOLD) {
            if (!$recaptcha->verify($request->input('recaptcha_token'), $request->ip())) {
                return response()->json([
                    'message'          => 'Please complete the CAPTCHA challenge to continue.',
                    'requires_captcha' => true,
                ], 422);
            }
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            $this->registerFailedLoginAttempt($email, $lockKey);

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Correct credentials: this attempt was not a guess, so clear any
        // accumulated failure count/lockout tied to this account.
        $this->clearLoginAttempts($email);

        $user = Auth::user();

        // Check if employee is currently banned
        if ($user->role === 'employee') {
            $employee = \App\Models\Employee::where('user_id', $user->id)->first();
            if ($employee && $employee->banned_until && $employee->banned_until->isFuture()) {
                Auth::logout();
                return response()->json([
                    'message' => 'Your gym access is suspended until ' . $employee->banned_until->toDateString() . '. Please contact your HR team.',
                ], 403);
            }
        }

        // Employees need both company (HR) and admin approval, even if is_active was flipped on
        if ($user->role === 'employee' && $user->is_active) {
            $employee = \App\Models\Employee::where('user_id', $user->id)->first();
            if ($employee && $employee->registration_status === 'pending') {
                Auth::logout();
                return response()->json(['message' => 'Your application is pending HR approval. You will be notified once your company reviews it.'], 403);
            }
            if ($employee && $employee->registration_status === 'rejected') {
                Auth::logout();
                return response()->json(['message' => 'Your registration was rejected by your company. Please contact your HR team.'], 403);
            }
        }

        if (!$user->is_active) {
            Auth::logout();

            if ($user->role === 'employee') {
                $employee = \App\Models\Employee::where('user_id', $user->id)->first();
                if ($employee) {
                    if ($employee->registration_status === 'pending') {
                        return response()->json(['message' => 'Your application is pending HR approval. You will be notified once your company reviews it.'], 403);
                    }
                    if ($employee->admin_approval_status === 'rejected') {
                        return response()->json(['message' => 'Your account has been rejected. Please contact your HR team for more information.'], 403);
                    }
                    if ($employee->admin_approval_status === 'pending') {
                        return response()->json(['message' => 'Your account has been approved by HR and is now pending admin final approval. You will be notified once activated.'], 403);
                    }
                }
            }

            return response()->json(['message' => 'Your account is inactive. Please contact support.'], 403);
        }

        if (in_array($user->role, $this->mfaRequiredRoles(), true)) {
            Auth::logout();
            return $this->beginMfaChallenge($user);
        }

        return $this->respondWithSession($request, $user);
    }

    /**
     * Roles that must complete TOTP two-factor authentication to log in.
     */
    private function mfaRequiredRoles(): array
    {
        return array_merge(User::ADMIN_ROLES, self::MFA_EXTRA_ROLES);
    }

    /**
     * Password was correct but the account requires MFA before a session
     * token is issued. The QR code is only shown the first time (until the
     * user confirms a code); after that the saved secret is reused and the
     * user just enters the 6-digit code from their authenticator app.
     */
    private function beginMfaChallenge(User $user): JsonResponse
    {
        $mfaToken = Str::random(64);
        Cache::put("mfa_pending:{$mfaToken}", $user->id, now()->addMinutes(10));

        // Gym owners and employees: a 6-digit code to the account's email.
        // Sent only after the password was right, so it can't be used to
        // find out which emails are registered.
        if (in_array($user->role, self::EMAIL_MFA_ROLES, true)) {
            $this->sendLoginCode($user, $mfaToken);

            return response()->json([
                'mfa_required'   => true,
                'mfa_method'     => 'email',
                'mfa_token'      => $mfaToken,
                'masked_contact' => OneTimeCodeService::mask($user->email),
                'expires_in'     => OneTimeCodeService::TTL_MINUTES * 60,
                'message'        => 'We sent a 6-digit code to your email. Enter it to finish signing in.',
            ]);
        }

        // Already enrolled: skip the QR and ask for the code only
        if ($user->google2fa_enabled && $user->google2fa_secret) {
            return response()->json([
                'mfa_setup_required' => true,
                'mfa_enrolled'       => true,
                'mfa_token'          => $mfaToken,
                'qr_code'            => null,
                'secret'             => null,
                'message'            => 'Enter the 6-digit code from your authenticator app to finish signing in.',
            ]);
        }

        $tfa    = app(TwoFactorService::class);
        $secret = $tfa->generateSecretKey();
        $user->forceFill(['google2fa_secret' => $secret])->save();

        return response()->json([
            'mfa_setup_required' => true,
            'mfa_enrolled'       => false,
            'mfa_token'          => $mfaToken,
            'qr_code'            => $tfa->getQrCodeDataUri($user->email, $secret),
            'secret'             => $secret,
            'message'            => 'Two-factor authentication is required for your role. Scan the QR code with an authenticator app (Google Authenticator, Authy, etc.), then enter the 6-digit code to finish signing in.',
        ]);
    }

    /**
     * Step 2 of login: verify the code (against the new secret on first
     * setup, or the saved one afterwards) and issue the real session.
     */
    public function mfaSetupConfirm(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'code'      => 'required|digits:6',
        ]);

        $pendingKey = "mfa_pending:{$request->input('mfa_token')}";
        $userId     = Cache::get($pendingKey);
        $user       = $userId ? User::find($userId) : null;

        if (!$user || !$user->google2fa_secret) {
            return response()->json(['message' => 'This login session has expired. Please sign in again.'], 422);
        }
        if (in_array($user->role, self::EMAIL_MFA_ROLES, true)) {
            // These accounts confirm with the emailed code (mfa/verify) only.
            return response()->json(['message' => 'Enter the code we sent to your email.', 'code' => 'use_email_code'], 422);
        }

        $tfa = app(TwoFactorService::class);
        if (!$tfa->verifyKey($user->google2fa_secret, $request->input('code'))) {
            return response()->json(['message' => 'Invalid authentication code. Please try again.'], 422);
        }

        $user->forceFill(['google2fa_enabled' => true])->save();

        Cache::forget($pendingKey);

        return $this->respondWithSession($request, $user);
    }

    /**
     * Step 2 for email-code accounts: check the code sent to the account's
     * email (10 minutes, 5 tries) and issue the session.
     */
    public function mfaVerify(Request $request): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string|max:100',
            'code'      => 'required|digits:6',
        ]);

        $pendingKey = "mfa_pending:{$request->input('mfa_token')}";
        $userId     = Cache::get($pendingKey);
        $user       = $userId ? User::find($userId) : null;

        if (!$user || !$user->is_active) {
            return response()->json(['message' => 'This sign-in has expired. Please sign in again.', 'code' => 'session_expired'], 422);
        }
        if (!in_array($user->role, self::EMAIL_MFA_ROLES, true)) {
            // Authenticator accounts confirm through mfa/setup/confirm.
            return $this->mfaSetupConfirm($request);
        }

        $result = app(OneTimeCodeService::class)->verify('login', $this->loginCodeSubject($user, $request->input('mfa_token')), $request->input('code'));
        if ($result !== 'ok') {
            if ($result === 'expired') {
                Cache::forget($pendingKey);
            }
            Log::warning('auth.mfa_email.failed', ['user_id' => $user->id, 'result' => $result]);

            return response()->json([
                'message' => $result === 'wrong' ? 'That code isn’t right. Check the email and try again.' : 'This code has expired. Sign in again to get a new one.',
                'code'    => $result === 'wrong' ? 'wrong_code' : 'code_expired',
            ], 422);
        }

        Cache::forget($pendingKey);

        return $this->respondWithSession($request, $user);
    }

    /** POST /auth/mfa/resend {mfa_token}: a new code for the same sign-in. */
    public function mfaResend(Request $request): JsonResponse
    {
        $request->validate(['mfa_token' => 'required|string|max:100']);
        $userId = Cache::get("mfa_pending:{$request->input('mfa_token')}");
        $user   = $userId ? User::find($userId) : null;

        if (!$user || !in_array($user->role, self::EMAIL_MFA_ROLES, true)) {
            return response()->json(['message' => 'This sign-in has expired. Please sign in again.', 'code' => 'session_expired'], 422);
        }

        $this->sendLoginCode($user, $request->input('mfa_token'));

        return response()->json(['data' => [
            'masked_contact' => OneTimeCodeService::mask($user->email),
            'expires_in'     => OneTimeCodeService::TTL_MINUTES * 60,
        ]]);
    }

    private function sendLoginCode(User $user, string $mfaToken): void
    {
        app(OneTimeCodeService::class)->send('login', $this->loginCodeSubject($user, $mfaToken), 'email', $user->email, $user->name, $user->role === 'gym_partner' ? 'signing in to FitAccess Partner' : 'signing in to FitAccess');
    }

    /** The code is tied to this one sign-in attempt. */
    private function loginCodeSubject(User $user, string $mfaToken): string
    {
        return "user:{$user->id}|".hash('sha256', $mfaToken);
    }

    /**
     * Issues the Sanctum token and builds the standard post-login payload.
     * Shared by direct login (no MFA required) and both MFA completion steps.
     */
    private function respondWithSession(Request $request, User $user): JsonResponse
    {
        // Server-side expiry: idle timeout + absolute lifetime by account type;
        // members also get a rotating refresh token (15-minute access token).
        $pair    = app(SessionTokenService::class)->issueSession($user, $request->input('device_name'), [$user->role]);
        $session = $pair['access'];
        $token   = $session->plainTextToken;

        // Load gym staff role if partner app user
        $gymStaff = null;
        if (in_array($user->role, ['gym_staff', 'gym_partner'])) {
            $gymStaff = \App\Models\GymStaff::where('user_id', $user->id)
                ->where('is_active', true)
                ->first();
        }

        return response()->json([
            'user'  => array_merge((new UserResource($user))->toArray($request), [
                'member_code'          => $user->member_code,
                'must_change_password' => ($user->must_change_password ?? false) || ($user->must_reset_password ?? false),
                'gym_staff_role'       => $gymStaff?->role,
                'gym_id'               => $gymStaff?->gym_id,
            ]),
            'token'                 => $token,
            'token_expires_at'      => $session->accessToken->expires_at?->toIso8601String(),
            'idle_timeout_minutes'  => SessionTokenService::policyFor($user)['idle'],
            ...self::refreshFields($pair),
            'permissions'           => $user->effectivePermissions(),
            'must_change_password'  => $gymStaff?->must_change_password ?? ($user->must_reset_password ?? false),
            'gym_staff_role'        => $gymStaff?->role,
            'gym_id'                => $gymStaff?->gym_id,
        ]);
    }

    /**
     * Record a failed login attempt for the account (independent of the
     * caller's IP, so IP rotation cannot be used to bypass the lockout)
     * and lock the account with a progressively longer delay once the
     * attempt threshold is reached.
     */
    private function registerFailedLoginAttempt(string $email, string $lockKey): void
    {
        $failKey  = "login_fails:{$email}";
        $attempts = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $attempts, now()->addMinutes(self::LOGIN_ATTEMPTS_WINDOW_MINUTES));

        if ($attempts < self::LOGIN_MAX_ATTEMPTS) {
            return;
        }

        Cache::forget($failKey);

        $lockoutMinutes = $this->nextLockoutMinutes($email);
        Cache::put($lockKey, time() + ($lockoutMinutes * 60), now()->addMinutes($lockoutMinutes));
    }

    /**
     * Escalating lockout duration: repeated lockouts for the same account
     * (tracked for 24h) get progressively longer delays.
     */
    private function nextLockoutMinutes(string $email): int
    {
        $countKey = "login_lockout_count:{$email}";
        $count    = (int) Cache::get($countKey, 0) + 1;
        Cache::put($countKey, $count, now()->addDay());

        return match (true) {
            $count <= 1 => 15,
            $count === 2 => 30,
            $count === 3 => 60,
            default      => 240,
        };
    }

    private function clearLoginAttempts(string $email): void
    {
        Cache::forget("login_fails:{$email}");
        Cache::forget("login_lockout:{$email}");
        Cache::forget("login_lockout_count:{$email}");
    }

    public function logout(Request $request): JsonResponse
    {
        // Ends the whole session (a member's refresh token too), not just
        // this access token.
        $current = $request->user()->currentAccessToken();
        if ($current instanceof PersonalAccessToken) {
            app(SessionTokenService::class)->revokeSessionOf($current);
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load('employee.company')));
    }

    /**
     * Public: company (employer) application from the sign-up page.
     *
     * Nothing is created except one application row: the admin verifies it and
     * only then creates the company, the HR login and branch logins, each with a
     * temporary password that is emailed to them.
     */
    public function registerCompany(Request $request): JsonResponse
    {
        $request->validate([
            'company_name'     => 'required|string|max:255',
            'industry'         => 'required|string|max:100',
            'company_size'     => 'nullable|string|max:50',
            'tin'              => 'required|string|max:50',
            'contact_name'     => 'required|string|max:255',
            'job_title'        => 'nullable|string|max:255',
            'email'            => 'required|email|max:255|unique:users,email|unique:companies,contact_email',
            'phone'            => 'required|string|max:20',
            'preferred_payment_method' => 'nullable|in:cbe_transfer,telebirr_enterprise,awash_bank,chapa,cash,other',
            'branches'                 => 'nullable|string',   // JSON-encoded array of branches
        ], [
            'tin.required' => 'The TIN number is required.',
            'email.unique' => 'This email address is already registered with FitAccess.',
        ]);

        if (\App\Models\CompanyApplication::where('contact_email', $request->email)->where('status', 'pending')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => 'An application with this email address is already being reviewed. We will contact you soon.',
            ]);
        }

        // Branch details only — their logins are created on approval
        $branches = \App\Services\BranchLoginService::validate(
            json_decode($request->branches ?? '[]', true) ?? [],
            \App\Services\BranchLoginService::COMPANY,
            [$request->email],
            false
        );

        $application = DB::transaction(function () use ($request, $branches): \App\Models\CompanyApplication {
            $app = \App\Models\CompanyApplication::create([
                'company_name'             => $request->company_name,
                'industry'                 => $this->mapIndustry($request->industry),
                'company_size'             => $request->company_size,
                'tin_number'               => trim($request->tin),
                'preferred_payment_method' => $request->preferred_payment_method,
                'contact_name'             => $request->contact_name,
                'job_title'                => $request->job_title,
                'contact_email'            => strtolower(trim($request->email)),
                'contact_phone'            => $request->phone,
                'branches'                 => $branches ?: null,
                'status'                   => 'pending',
            ]);
            $app->update(['reference' => \App\Models\CompanyApplication::makeReference($app->id)]);

            return $app;
        });

        \App\Services\ApplicationMailer::received(
            \App\Services\ApplicationMailer::COMPANY,
            $application->contact_name,
            $application->contact_email,
            $application->reference,
            $application->company_name
        );
        \App\Services\ApplicationMailer::notifyAdmins(
            \App\Services\ApplicationMailer::COMPANY,
            $application->reference,
            $application->company_name,
            array_filter([
                'Company'        => $application->company_name,
                'Industry'       => $application->industry,
                'Company size'   => $application->company_size,
                'TIN'            => $application->tin_number,
                'Contact person' => trim($application->contact_name . ($application->job_title ? " ({$application->job_title})" : '')),
                'Email'          => $application->contact_email,
                'Phone'          => $application->contact_phone,
                'Branches'       => (string) count($branches),
            ], fn($v) => $v !== null && $v !== '')
        );
        try {
            app(TelegramService::class)->notifyAdminsNewCompanyApplication($application);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Company application Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'   => 'Thank you! Your application has been received. Our team will contact you within 2 business days.',
            'reference' => $application->reference,
        ], 201);
    }

    /**
     * Public: employee self-registration from the employee sign-up page.
     */
    public function registerEmployee(Request $request): JsonResponse
    {
        $request->validate([
            'first_name'      => 'required|string|max:100',
            'fathers_name'    => 'required|string|max:100',
            'grandfathers_name' => 'required|string|max:100',
            'phone'           => 'required|string|max:20',
            'email'           => 'required|email|unique:users,email',
            'password'        => ['required', 'string', new \App\Rules\StrongPassword()],
            'company_id'      => 'required|exists:companies,id',
            'staff_id'        => 'required|string|max:50|unique:employees,fan_number',
            'job_position'    => 'nullable|string|max:255',
            'department'      => 'nullable|string|max:255',
            'branch'          => 'nullable|string|max:255',
            'company_branch_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('company_branches', 'id')
                ->where(fn ($q) => $q->where('company_id', $request->company_id)->where('is_active', true)->whereNull('deleted_at'))],
            'request_note'    => 'nullable|string|max:1000',
        ], [
            'staff_id.size'   => 'Staff ID must be exactly 13 digits.',
            'staff_id.unique' => 'This Staff ID is already registered.',
            'email.unique'    => 'This email is already registered.',
        ]);

        $fullName = trim("{$request->first_name} {$request->fathers_name} {$request->grandfathers_name}");

        // A company with branches: the employee picks one from the list
        $companyBranch = $request->company_branch_id
            ? \App\Models\CompanyBranch::find($request->company_branch_id)
            : null;

        $employee = DB::transaction(function () use ($request, $fullName, $companyBranch): Employee {
            $user = User::create([
                'name'       => $fullName,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'role'       => 'employee',
                'phone'      => $request->phone,
                'fan_number' => $request->staff_id,
                'is_active'  => false,
            ]);

            return Employee::create([
                'user_id'               => $user->id,
                'company_id'            => $request->company_id,
                'fan_number'            => $request->staff_id,
                'job_title'             => $request->job_position,
                'department'            => $request->department,
                'company_branch_id'     => $companyBranch?->id,
                'branch'                => $companyBranch?->name ?? $request->branch,
                'level'                 => 'staff',
                'request_note'          => $request->request_note,
                'registration_status'   => 'pending',
                'admin_approval_status' => 'pending',
                'is_enrolled'           => false,
            ]);
        });

        // Notify AFTER transaction so a Telegram failure never rolls back the registration
        try {
            app(TelegramService::class)->notifyHRNewEmployee($employee->load('user', 'company'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Employee registration Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Your application has been submitted! Your HR team will review and activate your account.',
        ], 201);
    }

    /**
     * Public: facility/partner application from the partner sign-up page.
     *
     * Only the application row is stored. The owner login, the gyms and the
     * branch logins are created when an admin approves it, each with a
     * temporary password that is emailed to them.
     */
    public function registerPartner(Request $request): JsonResponse
    {
        $request->validate([
            'facility_name'    => 'required|string|max:255',
            'categories'       => 'required|string',   // JSON-encoded array
            'contact_person'   => 'required|string|max:255',
            'contact_phone'    => 'required|string|max:20',
            'contact_email'    => 'required|email|max:255|unique:users,email',
            'tin_number'       => 'required|string|max:50',
            'website'          => 'nullable|url:http,https|max:255',
            'has_permits'             => 'required|boolean',
            'has_certified_equipment' => 'required|boolean',
            'has_reception'           => 'required|boolean',
            'city'             => 'required|string|max:100',
            'sub_city'         => 'nullable|string|max:100',
            'woreda'           => 'required|string|max:255',
            'landmark'         => 'nullable|string|max:255',
            'google_maps_link' => 'nullable|string|max:500',
            'latitude'         => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude'        => 'nullable|numeric|between:-180,180|required_with:latitude',
            'weekday_open'     => 'required|string|max:10',
            'weekday_close'    => 'required|string|max:10',
            'weekend_open'     => 'nullable|string|max:10',
            'weekend_close'    => 'nullable|string|max:10',
            'operating_hours'  => 'nullable|string|max:255',
            'max_capacity'     => 'required|integer|min:1',
            'amenities'        => 'nullable|string',   // JSON-encoded array
            'branch_name'      => 'nullable|string|max:100',
            'branches'         => 'nullable|string',   // JSON-encoded array of extra branches
        ], [
            'contact_email.unique'  => 'This email address is already registered with FitAccess.',
            'tin_number.required'   => 'The TIN number is required.',
            'website.url'           => 'Please enter a valid website address, e.g. https://www.example.com.',
            'has_permits.required'             => 'Please answer whether the facility has the required permits.',
            'has_certified_equipment.required' => 'Please answer whether the facility has certified equipment.',
            'has_reception.required'           => 'Please answer whether the facility has a staffed reception.',
        ]);

        if (PartnerApplication::whereNull('parent_gym_id')
                ->where('contact_email', $request->contact_email)
                ->where('status', 'pending')->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contact_email' => 'An application with this email address is already being reviewed. We will contact you soon.',
            ]);
        }

        // Decode JSON-encoded arrays from FormData
        $categories = json_decode($request->categories, true) ?? [];
        $amenities  = json_decode($request->amenities  ?? '[]', true) ?? [];
        // Branch details only — their logins are created on approval
        $branches   = \App\Services\BranchLoginService::validate(
            json_decode($request->branches ?? '[]', true) ?? [],
            \App\Services\BranchLoginService::GYM,
            [$request->contact_email],
            false
        );

        $partnerApplication = DB::transaction(function () use ($request, $categories, $amenities, $branches): PartnerApplication {
            $app = PartnerApplication::create([
                'user_id'                 => null,
                'facility_name'           => $request->facility_name,
                'branch_name'             => $branches ? ($request->branch_name ?: null) : null,
                'categories'              => $categories,
                'contact_person'          => $request->contact_person,
                'contact_phone'           => $request->contact_phone,
                'contact_email'           => strtolower(trim($request->contact_email)),
                'tin_number'              => trim($request->tin_number),
                'website'                 => $request->filled('website') ? trim($request->website) : null,
                'has_permits'             => $request->boolean('has_permits'),
                'has_certified_equipment' => $request->boolean('has_certified_equipment'),
                'has_reception'           => $request->boolean('has_reception'),
                'city'                    => $request->city,
                'sub_city'                => $request->sub_city,
                'woreda'                  => $request->woreda,
                'landmark'                => $request->landmark,
                'google_maps_link'        => $request->google_maps_link,
                'latitude'                => $request->filled('latitude') ? (float) $request->latitude : null,
                'longitude'               => $request->filled('longitude') ? (float) $request->longitude : null,
                'weekday_open'            => $request->weekday_open,
                'weekday_close'           => $request->weekday_close,
                'weekend_open'            => $request->weekend_open ?: null,
                'weekend_close'           => $request->weekend_close ?: null,
                'operating_hours_summary' => $request->operating_hours,
                'max_capacity'            => $request->max_capacity,
                'amenities'               => $amenities,
                'branches'                => $branches ?: null,
                'status'                  => 'pending',
            ]);
            $app->update(['reference' => PartnerApplication::makeReference($app->id)]);

            return $app;
        });

        \App\Services\ApplicationMailer::received(
            \App\Services\ApplicationMailer::PARTNER,
            $partnerApplication->contact_person,
            $partnerApplication->contact_email,
            $partnerApplication->reference,
            $partnerApplication->facility_name
        );
        \App\Services\ApplicationMailer::notifyAdmins(
            \App\Services\ApplicationMailer::PARTNER,
            $partnerApplication->reference,
            $partnerApplication->facility_name,
            array_filter([
                'Facility'       => $partnerApplication->facility_name,
                'Categories'     => implode(', ', $categories),
                'TIN'            => $partnerApplication->tin_number,
                'Website'        => $partnerApplication->website,
                'Permits'             => $partnerApplication->has_permits ? 'Yes' : 'No',
                'Certified equipment' => $partnerApplication->has_certified_equipment ? 'Yes' : 'No',
                'Staffed reception'   => $partnerApplication->has_reception ? 'Yes' : 'No',
                'Contact person' => $partnerApplication->contact_person,
                'Email'          => $partnerApplication->contact_email,
                'Phone'          => $partnerApplication->contact_phone,
                'Address'        => trim($partnerApplication->woreda . ', ' . $partnerApplication->city, ', '),
                'Capacity'       => (string) $partnerApplication->max_capacity,
                'Branches'       => (string) count($branches),
            ], fn($v) => $v !== null && $v !== '')
        );
        // Notify AFTER transaction so a Telegram failure never rolls back the application
        try {
            app(TelegramService::class)->notifyAdminsNewPartner($partnerApplication);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Partner application Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'   => 'Thank you! Your application has been received. Our team will contact you within 3 business days.',
            'reference' => $partnerApplication->reference,
        ], 201);
    }

    /**
     * Map the frontend-friendly industry label to the DB enum value.
     */
    private function mapIndustry(string $industry): string
    {
        // The sign-up form already sends the stored key (e.g. "banking")
        $keys = ['banking', 'ngo', 'government', 'international_school', 'hospital', 'real_estate',
                 'telecom', 'airline', 'insurance', 'tech', 'embassy', 'other'];
        if (in_array($industry, $keys, true)) {
            return $industry;
        }

        $map = [
            'Banking & Finance'                => 'banking',
            'Telecom & Technology'             => 'telecom',
            'Aviation & Transport'             => 'airline',
            'Government & Public Sector'       => 'government',
            'NGO / International Organisation' => 'ngo',
            'Healthcare & Pharmaceuticals'     => 'hospital',
            'Manufacturing & Industry'         => 'other',
            'Media & Entertainment'            => 'other',
            'Education'                        => 'other',
            'Other'                            => 'other',
        ];

        return $map[$industry] ?? 'other';
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password'     => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
            'password'             => Hash::make($request->new_password),
            'must_change_password' => false,
            'must_reset_password'  => false,
        ]);
        \App\Models\GymStaff::where('user_id', $user->id)
        ->update(['must_change_password' => false]);

        // A new password ends every other session, e.g. one opened with the
        // old (possibly stolen) password.
        app(SessionTokenService::class)->revokeOtherSessions($user);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function registerMember(Request $request): JsonResponse
{
    $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'required|string|max:20|unique:users,phone',
        'password' => ['required', 'string', new \App\Rules\StrongPassword()],
    ]);

    $user = User::create([
        'name'      => $request->name,
        'email'     => $request->email,
        'phone'     => $request->phone,
        'password'  => Hash::make($request->password),
        'role'      => 'member',
        'is_active' => true,
    ]);

    $user->member_code = 'FA-' . str_pad($user->id, 5, '0', STR_PAD_LEFT);
    $user->save();

    $pair    = app(SessionTokenService::class)->issueSession($user, $request->input('device_name'), ['member']);
    $session = $pair['access'];

    return response()->json([
        'token'                => $session->plainTextToken,
        'token_expires_at'     => $session->accessToken->expires_at?->toIso8601String(),
        'idle_timeout_minutes' => SessionTokenService::policyFor($user)['idle'],
        ...self::refreshFields($pair),
        'user'  => [
            'id'                   => $user->id,
            'name'                 => $user->name,
            'email'                => $user->email,
            'phone'                => $user->phone,
            'role'                 => $user->role,
            'member_code'          => $user->member_code,
            'is_active'            => $user->is_active,
            'must_change_password' => false,
        ],
    ], 201);
}
    /**
     * Forced first-login password reset.
     * User must already be authenticated. Clears the must_reset_password flag.
     */
    public function firstLoginReset(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->must_reset_password) {
            return response()->json(['message' => 'Password reset is not required.'], 422);
        }

        $request->validate([
            'password' => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
        ]);

        $user->update([
            'password'            => Hash::make($request->password),
            'must_reset_password' => false,
        ]);

        app(SessionTokenService::class)->revokeOtherSessions($user);

        return response()->json([
            'message'     => 'Password updated successfully.',
            'permissions' => $user->fresh()->effectivePermissions(),
        ]);
    }

    /**
     * Forgot-password: generate a reset token and return it.
     * In production you would email it; here we return it directly so
     * the flow works without an SMTP server.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('is_active', true)->first();

        // Deliberate vague response to avoid user enumeration
        if (!$user) {
            return response()->json(['message' => 'If this email is registered, a reset code has been sent.']);
        }

        $code    = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 8));
        $expires = now()->addHour();

        $user->update([
            'password_reset_token'      => Hash::make($code),
            'password_reset_expires_at' => $expires,
        ]);

        Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code));

        return response()->json(['message' => 'If this email is registered, a reset code has been sent.']);
    }

    /**
     * Reset password using email + token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user
            || !$user->password_reset_token
            || !Hash::check($request->token, $user->password_reset_token)
            || now()->gt($user->password_reset_expires_at)
        ) {
            return response()->json(['message' => 'Invalid or expired reset code.'], 422);
        }

        $user->update([
            'password'                  => Hash::make($request->password),
            'must_reset_password'       => false,
            'password_reset_token'      => null,
            'password_reset_expires_at' => null,
        ]);

        // Whoever triggered a reset may not be the only one holding sessions.
        app(SessionTokenService::class)->revokeAll($user);

        return response()->json(['message' => 'Password has been reset. You can now log in.']);
    }

    // ── Sessions ──────────────────────────────────────────────────────────────

    /** The caller's active sessions (devices), newest activity first. */
    public function sessions(Request $request): JsonResponse
    {
        $user      = $request->user();
        $current   = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->id : null;

        $currentFamily = $current instanceof PersonalAccessToken ? $current->family_id : null;
        $tokens        = $user->tokens()->orderByDesc('last_used_at')->get()
            ->each(fn (PersonalAccessToken $t) => $t->setRelation('tokenable', $user));

        // Last activity of a member session = its most recent access token.
        $familyLastUsed = $tokens->whereNotNull('family_id')->groupBy('family_id')
            ->map(fn ($group) => $group->max('last_used_at'));

        // One row per session: a member session (token family) is shown by
        // its live refresh token; other accounts by their access token.
        $sessions = $tokens
            ->filter(fn (PersonalAccessToken $t) => $t->family_id
                ? SessionTokenService::refreshTokenUsable($t) && !$t->used_at
                : SessionTokenService::isStillValid($t))
            ->map(fn (PersonalAccessToken $t) => [
                'id'           => $t->id,
                'device'       => $t->name,
                'created_at'   => ($t->family_id && $t->session_started_at ? \Illuminate\Support\Carbon::parse($t->session_started_at) : $t->created_at)?->toIso8601String(),
                'last_used_at' => ($t->family_id ? ($familyLastUsed[$t->family_id] ?? null) : $t->last_used_at)?->toIso8601String(),
                'expires_at'   => SessionTokenService::expiresAt($t)?->toIso8601String(),
                'current'      => $t->family_id ? $t->family_id === $currentFamily : $t->id === $currentId,
            ])
            ->values();

        return response()->json(['data' => $sessions]);
    }

    /** Ends one of the caller's own sessions. */
    public function revokeSession(Request $request, int $tokenId): JsonResponse
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->first();

        if (!$token) {
            return response()->json(['message' => 'Session not found.'], 404);
        }

        app(SessionTokenService::class)->revokeSessionOf($token);

        return response()->json(['message' => 'Session signed out.']);
    }

    /** "Sign out everywhere": ends all of the caller's sessions, this one included. */
    public function logoutAll(Request $request): JsonResponse
    {
        $count = app(SessionTokenService::class)->revokeAll($request->user());

        return response()->json(['message' => 'Signed out of all devices.', 'revoked' => $count]);
    }

    /**
     * Members: exchange a refresh token for a new access + refresh pair.
     * The refresh token is single-use; presenting a used one ends the
     * whole session (it was copied).
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string|max:200']);

        $pair = app(SessionTokenService::class)->refresh($request->input('refresh_token'));
        if (!$pair) {
            return response()->json(['message' => 'Session expired. Please sign in again.'], 401);
        }

        return response()->json([
            'token'            => $pair['access']->plainTextToken,
            'token_expires_at' => $pair['access']->accessToken->expires_at?->toIso8601String(),
            ...self::refreshFields($pair),
        ]);
    }

    /**
     * Members: sign out with the refresh token, which works even when the
     * access token has already expired. Always 200, so it can't be used to
     * probe for valid tokens.
     */
    public function revokeRefreshToken(Request $request): JsonResponse
    {
        $request->validate(['refresh_token' => 'required|string|max:200']);

        $token = PersonalAccessToken::findToken($request->input('refresh_token'));
        if ($token && SessionTokenService::isRefreshToken($token)) {
            app(SessionTokenService::class)->revokeSessionOf($token);
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /** @param array{access: \Laravel\Sanctum\NewAccessToken, refresh: ?\Laravel\Sanctum\NewAccessToken} $pair */
    private static function refreshFields(array $pair): array
    {
        if (!$pair['refresh']) {
            return [];
        }

        return [
            'refresh_token'      => $pair['refresh']->plainTextToken,
            'refresh_expires_at' => $pair['refresh']->accessToken->expires_at?->toIso8601String(),
        ];
    }
}
