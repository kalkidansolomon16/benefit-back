<?php

namespace App\Http\Controllers;

use App\Http\Resources\CompanyResource;
use App\Http\Resources\UserResource;
use App\Mail\PasswordResetCodeMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

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

        $token = $user->createToken('fitaccess-token', [$user->role])->plainTextToken;

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
                'must_change_password' => $gymStaff?->must_change_password ?? ($user->must_reset_password ?? false),
                'gym_staff_role'       => $gymStaff?->role,
                'gym_id'               => $gymStaff?->gym_id,
            ]),
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(new UserResource($request->user()->load('employee.company')));
    }

    /**
     * Public: self-service company registration from the sign-up page.
     * Accepts multipart/form-data so the business licence file can be uploaded.
     */
    public function registerCompany(Request $request): JsonResponse
    {
        $request->validate([
            'company_name'     => 'required|string|max:255',
            'industry'         => 'required|string|max:100',
            'company_size'     => 'nullable|string|max:50',
            'tin'              => 'nullable|string|max:50',
            'contact_name'     => 'required|string|max:255',
            'job_title'        => 'nullable|string|max:255',
            'email'            => 'required|email|unique:users,email|unique:companies,contact_email',
            'phone'            => 'required|string|max:20',
            'password'         => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/',      // at least one uppercase
                'regex:/[a-z]/',      // at least one lowercase
                'regex:/[0-9]/',      // at least one number
                'regex:/[^A-Za-z0-9]/', // at least one special character
            ],
            // Business licence: required PDF/image, max 5 MB
            'business_license'         => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'preferred_payment_method' => 'nullable|in:cbe_transfer,telebirr_enterprise,awash_bank,chapa,cash,other',
        ], [
            'business_license.required'  => 'A business licence document (PDF or image) is required.',
            'business_license.mimes'     => 'The business licence must be a PDF, JPG, or PNG file.',
            'business_license.max'       => 'The business licence file must not exceed 5 MB.',
            'password.min'               => 'Password must be at least 8 characters.',
            'password.regex'             => 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.',
        ]);

        // Store the file BEFORE the DB transaction so we can clean it up
        // regardless of whether the transaction commits or rolls back.
        $licensePath = $request->file('business_license')
            ->store('licenses', 'public');

        try {
            return DB::transaction(function () use ($request, $licensePath): JsonResponse {

                // 1. Create the company record
                $company = Company::create([
                    'name'                    => $request->company_name,
                    'industry'                => $this->mapIndustry($request->industry),
                    'contact_person'          => $request->contact_name,
                    'contact_email'           => $request->email,
                    'contact_phone'           => $request->phone,
                    'city'                    => 'Addis Ababa',
                    'tin_number'              => $request->tin,
                    'business_license_path'    => $licensePath,
                    'business_license_status'  => 'pending',
                    'preferred_payment_method' => $request->preferred_payment_method,
                    'is_active'                => false,
                ]);

                // 2. Create the HR user account
                $user = User::create([
                    'name'      => $request->contact_name,
                    'email'     => $request->email,
                    'password'  => Hash::make($request->password),
                    'role'      => 'company_hr',
                    'phone'     => $request->phone,
                    'is_active' => false,
                ]);

                // 3. Issue a Sanctum token
                $token = $user->createToken('fitaccess-token')->plainTextToken;

                // Notify admins via Telegram
                app(TelegramService::class)->notifyAdminsNewCompany($company, $user);

                return response()->json([
                    'message' => 'Registration submitted. Your account will be activated after licence review (2–3 business days).',
                    'user'    => new UserResource($user),
                    'company' => new CompanyResource($company),
                    'token'   => $token,
                ], 201);
            });
        } catch (\Throwable $e) {
            // If anything fails, delete the uploaded file so no orphans are left on disk
            Storage::disk('public')->delete($licensePath);
            throw $e;
        }
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
            'password'        => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/', 'regex:/[a-z]/',
                'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/',
            ],
            'company_id'      => 'required|exists:companies,id',
            'staff_id'        => 'required|string|max:50|unique:employees,fan_number',
            'job_position'    => 'nullable|string|max:255',
            'department'      => 'nullable|string|max:255',
            'branch'          => 'nullable|string|max:255',
            'request_note'    => 'nullable|string|max:1000',
        ], [
            'staff_id.size'   => 'Staff ID must be exactly 13 digits.',
            'staff_id.unique' => 'This Staff ID is already registered.',
            'email.unique'    => 'This email is already registered.',
            'password.min'    => 'Password must be at least 8 characters.',
            'password.regex'  => 'Password must include uppercase, lowercase, number and special character.',
        ]);

        $fullName = trim("{$request->first_name} {$request->fathers_name} {$request->grandfathers_name}");

        return DB::transaction(function () use ($request, $fullName): JsonResponse {
            $user = User::create([
                'name'       => $fullName,
                'email'      => $request->email,
                'password'   => Hash::make($request->password),
                'role'       => 'employee',
                'phone'      => $request->phone,
                'fan_number' => $request->staff_id,
                'is_active'  => false, // pending HR approval
            ]);

            $employee = Employee::create([
                'user_id'               => $user->id,
                'company_id'            => $request->company_id,
                'fan_number'            => $request->staff_id,
                'job_title'             => $request->job_position,
                'department'            => $request->department,
                'branch'                => $request->branch,
                'level'                 => 'staff',
                'request_note'          => $request->request_note,
                'registration_status'   => 'pending',   // awaiting HR approval
                'admin_approval_status' => 'pending',   // awaiting admin approval
                'is_enrolled'           => false,
            ]);

            // Notify HR & admins via Telegram
            app(TelegramService::class)->notifyHRNewEmployee($employee->load('user', 'company'));

            return response()->json([
                'message' => 'Your application has been submitted! Your HR team will review and activate your account.',
            ], 201);
        });
    }

    /**
     * Public: facility/partner self-registration from the partner sign-up page.
     * Accepts multipart/form-data so the business licence file can be uploaded.
     */
    public function registerPartner(Request $request): JsonResponse
    {
        $request->validate([
            'facility_name'    => 'required|string|max:255',
            'categories'       => 'required|string',   // JSON-encoded array
            'contact_person'   => 'required|string|max:255',
            'contact_phone'    => 'required|string|max:20',
            'contact_email'    => 'required|email|unique:users,email',
            'tin_number'       => 'required|string|max:50',
            'business_license' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'city'             => 'required|string|max:100',
            'sub_city'         => 'nullable|string|max:100',
            'woreda'           => 'required|string|max:255',
            'landmark'         => 'nullable|string|max:255',
            'google_maps_link' => 'nullable|string|max:500',
            'weekday_open'     => 'required|string|max:10',
            'weekday_close'    => 'required|string|max:10',
            'weekend_open'     => 'nullable|string|max:10',
            'weekend_close'    => 'nullable|string|max:10',
            'operating_hours'  => 'nullable|string|max:255',
            'max_capacity'     => 'required|integer|min:1',
            'amenities'        => 'nullable|string',   // JSON-encoded array
            'password'         => [
                'required', 'string', 'min:8',
                'regex:/[A-Z]/', 'regex:/[a-z]/', 'regex:/[0-9]/',
            ],
        ], [
            'contact_email.unique'  => 'This email address is already registered.',
            'business_license.mimes'=> 'Business licence must be PDF, JPG, or PNG.',
            'business_license.max'  => 'Business licence must not exceed 10 MB.',
            'password.min'          => 'Password must be at least 8 characters.',
            'password.regex'        => 'Password must include uppercase, lowercase, and a number.',
        ]);

        // Decode JSON-encoded arrays from FormData
        $categories = json_decode($request->categories, true) ?? [];
        $amenities  = json_decode($request->amenities  ?? '[]', true) ?? [];

        // Store licence file if provided
        $licensePath = null;
        if ($request->hasFile('business_license')) {
            $licensePath = $request->file('business_license')
                ->store('partner-licenses', 'public');
        }

        try {
            return DB::transaction(function () use ($request, $categories, $amenities, $licensePath): JsonResponse {

                // 1. Create User account (inactive until approved by admin)
                $user = User::create([
                    'name'      => $request->contact_person,
                    'email'     => $request->contact_email,
                    'password'  => Hash::make($request->password),
                    'role'      => 'gym_partner',
                    'phone'     => $request->contact_phone,
                    'is_active' => false,
                ]);

                // 2. Store the partner application
                $partnerApplication = PartnerApplication::create([
                    'user_id'                 => $user->id,
                    'facility_name'           => $request->facility_name,
                    'categories'              => $categories,
                    'contact_person'          => $request->contact_person,
                    'contact_phone'           => $request->contact_phone,
                    'contact_email'           => $request->contact_email,
                    'tin_number'              => $request->tin_number,
                    'business_license_path'   => $licensePath,
                    'city'                    => $request->city,
                    'sub_city'                => $request->sub_city,
                    'woreda'                  => $request->woreda,
                    'landmark'                => $request->landmark,
                    'google_maps_link'        => $request->google_maps_link,
                    'weekday_open'            => $request->weekday_open,
                    'weekday_close'           => $request->weekday_close,
                    'weekend_open'            => $request->weekend_open ?: null,
                    'weekend_close'           => $request->weekend_close ?: null,
                    'operating_hours_summary' => $request->operating_hours,
                    'max_capacity'            => $request->max_capacity,
                    'amenities'               => $amenities,
                    'status'                  => 'pending',
                ]);

                // Notify admins via Telegram
                app(TelegramService::class)->notifyAdminsNewPartner($partnerApplication);

                return response()->json([
                    'message' => 'Partner application submitted! Our team will review it within 2–3 business days.',
                ], 201);
            });
        } catch (\Throwable $e) {
            if ($licensePath) {
                Storage::disk('public')->delete($licensePath);
            }
            throw $e;
        }
    }

    /**
     * Map the frontend-friendly industry label to the DB enum value.
     */
    private function mapIndustry(string $industry): string
    {
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
            'new_password'     => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update([
        'password'             => Hash::make($request->new_password),
        'must_change_password' => false,
        ]);
        \App\Models\GymStaff::where('user_id', $user->id)
        ->update(['must_change_password' => false]);

        return response()->json(['message' => 'Password changed successfully.']);
    }

    public function registerMember(Request $request): JsonResponse
{
    $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'phone'    => 'required|string|max:20|unique:users,phone',
        'password' => 'required|string|min:8',
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

    $token = $user->createToken('fitaccess-token', ['member'])->plainTextToken;

    return response()->json([
        'token' => $token,
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
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'password'            => Hash::make($request->password),
            'must_reset_password' => false,
        ]);

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
            'password' => 'required|string|min:8|confirmed',
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

        return response()->json(['message' => 'Password has been reset. You can now log in.']);
    }
}
