<?php

use App\Http\Controllers\AdminBillingController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AdminPaymentMethodController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ChapaController;
use App\Http\Controllers\CompanyBillingController;
use App\Http\Controllers\CompanyUserController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\GymTeamController;
use App\Http\Controllers\TelegramController;
use App\Http\Controllers\TelegramSettingsController;
use App\Http\Controllers\HRController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\PartnerPortalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GymController;
use App\Http\Controllers\GymUpgradeController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipPlanController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WellnessProgramController;
use Illuminate\Support\Facades\Route;

/*
|----------------------------------------------------------------------
| FitAccess API Routes  —  /api/v1/...
|----------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // ── Auth & public ────────────────────────────────────────────────
    Route::post('auth/login',             [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/mfa/setup/confirm', [AuthController::class, 'mfaSetupConfirm'])->middleware('throttle:login');
    Route::post('auth/register/company',  [AuthController::class, 'registerCompany'])->middleware('throttle:register');
    Route::post('auth/register/employee', [AuthController::class, 'registerEmployee'])->middleware('throttle:register');
    Route::post('auth/register/partner',  [AuthController::class, 'registerPartner'])->middleware('throttle:register');
    Route::get('public/companies',        [CompanyController::class, 'publicList']);
    Route::get('public/companies/{company}/branches', [CompanyController::class, 'publicBranches']);
    Route::post('auth/register/member', [\App\Http\Controllers\AuthController::class, 'registerMember'])->middleware('throttle:register');

    // Password reset
    Route::post('auth/forgot-password', function (\Illuminate\Http\Request $request) {
        $request->validate(['email' => 'required|email']);

        $status = \Illuminate\Support\Facades\Password::sendResetLink(
            $request->only('email')
        );

        return $status === \Illuminate\Support\Facades\Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Password reset link sent to your email.'])
            : response()->json(['message' => 'We could not find a user with that email address.'], 422);
    })->middleware('throttle:5,1');

    Route::post('auth/reset-password', function (\Illuminate\Http\Request $request) {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed', new \App\Rules\StrongPassword()],
        ]);

        $status = \Illuminate\Support\Facades\Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => \Illuminate\Support\Facades\Hash::make($password),
                ])->save();
            }
        );

        return $status === \Illuminate\Support\Facades\Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password reset successfully.'])
            : response()->json(['message' => 'Invalid or expired reset token.'], 422);
    })->middleware('throttle:5,1');

    Route::get('auth/reset-password-redirect', function (\Illuminate\Http\Request $request) {
        $token = $request->query('token');
        $email = $request->query('email');

        // Return an HTML page with a button that opens the app
        return response('<html><body style="font-family:sans-serif;text-align:center;padding:40px">
        <h2>Reset Your FitAccess Password</h2>
        <p>Click the button below to open the FitAccess app and reset your password.</p>
        <a href="fitaccess://reset-password?token=' . $token . '&email=' . urlencode($email) . '" 
           style="background:#4CD964;color:white;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;margin-top:20px">
           Open FitAccess App
        </a>
        <p style="color:#999;font-size:12px;margin-top:20px">If the button does not work, copy this token into the app manually:<br><strong>' . $token . '</strong></p>
    </body></html>', 200, ['Content-Type' => 'text/html']);
    });


    // ── Partner app password reset (code-based, mobile-friendly) ────
    // Member sessions: rotate / revoke with the refresh token (no access token needed)
    Route::post('auth/refresh',            [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    Route::post('auth/revoke',             [AuthController::class, 'revokeRefreshToken'])->middleware('throttle:20,1');
    Route::post('auth/partner/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('auth/partner/reset-password',  [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    // ── Telegram webhook (public — Telegram must reach this) ─────────
    Route::post('telegram/webhook',        [TelegramController::class, 'webhook']);

    // ── Chapa payment webhook (public — Chapa must reach this; signature-verified) ─
    Route::post('chapa/webhook',           [ChapaController::class, 'webhook'])->middleware('throttle:30,1');

    // ── File serving (public — filenames are random hashes, no auth needed) ─
    Route::get('files/{path}', function (string $path) {
        // Prevent directory traversal
        $path     = ltrim($path, '/');
        $fullPath = storage_path('app/public/' . $path);
        $realPath = realpath($fullPath);
        $realBase = realpath(storage_path('app/public'));
        if (!$realPath || !str_starts_with($realPath, $realBase)) {
            abort(404);
        }
        return response()->file($realPath);
    })->where('path', '.*');

    // ── Auth & public ────────────────────────────────────────────────
    Route::post('auth/login',              [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/forgot-password',    [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password',     [AuthController::class, 'resetPassword']);
    Route::post('auth/register/company',   [AuthController::class, 'registerCompany'])->middleware('throttle:register');
    Route::post('auth/register/employee',  [AuthController::class, 'registerEmployee'])->middleware('throttle:register');
    Route::post('auth/register/partner',   [AuthController::class, 'registerPartner'])->middleware('throttle:register');
    Route::get('public/companies',         [CompanyController::class, 'publicList']);
    Route::get('public/companies/{company}/branches', [CompanyController::class, 'publicBranches']);
    Route::post('public/card-status',      [\App\Http\Controllers\CardStatusController::class, 'check'])->middleware('throttle:card-check');
    Route::get('public/plans',             [MembershipPlanController::class, 'publicIndex']);

    // ── Protected ───────────────────────────────────────────────────
    // Every group below is gated by account area (area: middleware, see
    // User::area()) and, for the admin panel, by the same granular
    // permissions the web app uses for its menus (permission: middleware).
    // tests/Feature/AccessControlTest fails if a protected route has no gate.
    Route::middleware('auth:sanctum')->group(function () {

        // ── Any signed-in account ─────────────────────────────────────
        Route::post('auth/logout',          [AuthController::class, 'logout']);
        Route::post('auth/logout-all',      [AuthController::class, 'logoutAll']);
        Route::get('auth/sessions',         [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{tokenId}', [AuthController::class, 'revokeSession'])->whereNumber('tokenId');
        Route::get('auth/me',               [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/first-login-reset',  [AuthController::class, 'firstLoginReset']);

        // ── Telegram user settings ────────────────────────────────────
        Route::prefix('telegram')->group(function () {
            Route::get('status',            [TelegramSettingsController::class, 'status']);
            Route::post('generate-code',    [TelegramSettingsController::class, 'generateCode']);
            Route::delete('unlink',         [TelegramSettingsController::class, 'unlink']);
        });

        // Plan catalogue: read by the admin panel, HR and gym portals.
        // Members use public/plans and mobile/packages instead.
        Route::middleware('area:admin,company,gym')->group(function () {
            Route::get('membership-plans',                  [MembershipPlanController::class, 'index']);
            Route::get('membership-plans/{membership_plan}', [MembershipPlanController::class, 'show']);
        });

        // ── MOBILE — Member (User App): members and employees ──────────
        Route::prefix('mobile')->middleware('area:member,employee')->group(function () {
            Route::get('dashboard',              [\App\Http\Controllers\MobileController::class, 'dashboard']);
            Route::get('sessions',               [AuthController::class, 'sessions']);
            Route::get('qr-token',               [\App\Http\Controllers\MobileController::class, 'qrToken'])->middleware('throttle:10,1');
            Route::get('profile',                [\App\Http\Controllers\MobileController::class, 'profile']);
            Route::patch('profile',              [\App\Http\Controllers\MobileController::class, 'updateProfile']);
            Route::post('profile/photo',         [\App\Http\Controllers\MobileController::class, 'uploadPhoto']);
            Route::get('checkins',               [\App\Http\Controllers\MobileController::class, 'checkins']);
            // v2 identity verification (selfie, private storage)
            Route::get('verification',           [\App\Http\Controllers\VerificationController::class, 'status']);
            Route::post('verification',          [\App\Http\Controllers\VerificationController::class, 'submit'])->middleware('throttle:6,60');
            Route::get('verification/photo',     [\App\Http\Controllers\VerificationController::class, 'myPhoto']);
            Route::get('packages',               [\App\Http\Controllers\MobileController::class, 'packages']);
            Route::get('subscription',           [\App\Http\Controllers\MobileController::class, 'subscription']);
            Route::post('subscription',          [\App\Http\Controllers\MobileController::class, 'createSubscription']);
            Route::post('subscription/cancel',   [\App\Http\Controllers\MobileController::class, 'cancelSubscription']);
            Route::get('gyms',                   [\App\Http\Controllers\MobileController::class, 'gyms']);
            Route::get('gyms/{gym}',             [\App\Http\Controllers\MobileController::class, 'gymDetail']);
            Route::get('notifications',          [\App\Http\Controllers\MobileController::class, 'notifications']);
            Route::patch('notifications/read-all', [\App\Http\Controllers\MobileController::class, 'markAllNotificationsRead']);
            Route::patch('notifications/{notification}/read', [\App\Http\Controllers\MobileController::class, 'markNotificationRead']);
        });

        // ── PARTNER MOBILE — Gym Staff / Owner (Partner App) ─────────────────
        Route::prefix('partner')->middleware('area:gym')->group(function () {
            Route::get('me',                     [\App\Http\Controllers\PartnerMobileController::class, 'me']);
            Route::get('dashboard',              [\App\Http\Controllers\PartnerMobileController::class, 'dashboard']);
            Route::post('scan',                  [\App\Http\Controllers\PartnerMobileController::class, 'scan'])->middleware('throttle:30,1');
            Route::get('visits/today',           [\App\Http\Controllers\PartnerMobileController::class, 'visitsToday']);
            Route::get('visits/monthly',         [\App\Http\Controllers\PartnerMobileController::class, 'visitsMonthly']);
            Route::get('reports',                [\App\Http\Controllers\PartnerMobileController::class, 'reports']);
            Route::get('financials',             [\App\Http\Controllers\PartnerMobileController::class, 'financials']);
            Route::get('staff',                  [\App\Http\Controllers\PartnerMobileController::class, 'staffList']);
            Route::post('staff/invite',          [\App\Http\Controllers\PartnerMobileController::class, 'inviteStaff']);
            Route::delete('staff/{gymStaff}',    [\App\Http\Controllers\PartnerMobileController::class, 'removeStaff']);
            Route::patch('facility',             [\App\Http\Controllers\PartnerMobileController::class, 'updateFacility']);
            Route::post('facility/photo',        [\App\Http\Controllers\PartnerMobileController::class, 'uploadFacilityPhoto']);
        }); // closes prefix('partner') mobile group

        // ── Employee portal (web) ──────────────────────────────────────
        Route::prefix('employee')->middleware('area:employee')->group(function () {
            Route::get('dashboard',     [EmployeePortalController::class, 'dashboard']);
            Route::patch('profile',     [EmployeePortalController::class, 'updateProfile']);
            Route::get('checkin-token', [CheckinController::class, 'token']);
            Route::get('gyms',          [CheckinController::class, 'myGyms']);
            Route::get('gyms/{gym}/occupants', [CheckinController::class, 'occupants']);
            Route::get('gym-privacy',   [EmployeePortalController::class, 'gymPrivacy']);
            Route::put('gym-privacy',   [EmployeePortalController::class, 'updateGymPrivacy']);
            Route::post('select-gym',   [CheckinController::class, 'selectGym']);
        });

        // ── Company HR portal ──────────────────────────────────────────
        Route::middleware('area:company')->group(function () {
            // Company Team & Permissions
            Route::prefix('hr/team')->group(function () {
                Route::get('/',                                        [CompanyUserController::class, 'index']);
                Route::post('/',                                       [CompanyUserController::class, 'store']);
                Route::patch('{user}/toggle-active',                   [CompanyUserController::class, 'toggleActive']);
                Route::post('{user}/reset-temp',                       [CompanyUserController::class, 'resetTemp']);
                Route::delete('{user}',                                [CompanyUserController::class, 'destroy']);
                Route::get('permissions',                              [CompanyUserController::class, 'permissions']);
                Route::put('permissions/roles/{role}',                 [CompanyUserController::class, 'updateRolePermissions']);
                Route::put('permissions/users/{user}',                 [CompanyUserController::class, 'updateUserPermissions']);
            });

            // Company Roles (dynamic)
            Route::prefix('hr/roles')->group(function () {
                Route::get('/',          [RolesController::class, 'companyIndex']);
                Route::post('/',         [RolesController::class, 'companyStore']);
                Route::delete('{role}',  [RolesController::class, 'companyDestroy']);
            });

            Route::prefix('hr')->group(function () {
                Route::get('my-company',                          [HRController::class, 'myCompany']);
                Route::get('branches',                            [\App\Http\Controllers\HRBranchController::class, 'index']);
                Route::post('branches',                           [\App\Http\Controllers\HRBranchController::class, 'store']);
                Route::patch('branches/{branch}',                 [\App\Http\Controllers\HRBranchController::class, 'update']);
                Route::delete('branches/{branch}',                [\App\Http\Controllers\HRBranchController::class, 'destroy']);
                Route::post('branches/{branch}/reset-password',   [\App\Http\Controllers\HRBranchController::class, 'resetPassword']);
                Route::get('dashboard',                           [HRController::class, 'dashboard']);
                Route::get('employees',                           [HRController::class, 'employees']);
                Route::post('employees',                          [HRController::class, 'registerEmployee']);
                Route::post('employees/import',                   [HRController::class, 'importEmployees']);
                Route::post('employees/bulk-approve',             [HRController::class, 'bulkApproveEmployees']);
                Route::post('employees/bulk-reject',              [HRController::class, 'bulkRejectEmployees']);
                Route::post('employees/{employee}/approve',       [HRController::class, 'approveEmployee']);
                Route::post('employees/{employee}/reject',        [HRController::class, 'rejectEmployee']);
                Route::post('employees/{employee}/ban',           [HRController::class, 'banEmployee']);
                Route::post('employees/{employee}/unban',         [HRController::class, 'unbanEmployee']);
                Route::post('employees/{employee}/reset-2fa',     [HRController::class, 'resetEmployeeTwoFactor']);

                // Billing (company-side)
                Route::get('billing/invoices',                                      [CompanyBillingController::class, 'index']);
                Route::get('billing/invoices/{billingInvoice}',                     [CompanyBillingController::class, 'show']);
                Route::get('billing/payment-methods',                               [CompanyBillingController::class, 'paymentMethods']);
                Route::get('billing/coverage',                                      [CompanyBillingController::class, 'coverage']);
                Route::put('billing/preference',                                    [CompanyBillingController::class, 'updatePreference']);
                Route::post('billing/invoices/{billingInvoice}/pay',                [CompanyBillingController::class, 'submitPayment']);
                Route::post('billing/invoices/{billingInvoice}/negotiate',          [CompanyBillingController::class, 'submitNegotiation']);
                Route::post('billing/invoices/{billingInvoice}/chapa/initialize',   [ChapaController::class, 'initialize']);
                Route::get('billing/chapa/verify',                                  [ChapaController::class, 'verify']);
            });
        });

        // ── Partner / Gym portal (web) ─────────────────────────────────
        Route::middleware('area:gym')->group(function () {
            // Gym Team & Permissions
            Route::prefix('partner/team')->middleware('branch.write')->group(function () {
                Route::get('/',                                        [GymTeamController::class, 'index']);
                Route::post('/',                                       [GymTeamController::class, 'store']);
                Route::patch('{user}/toggle-active',                   [GymTeamController::class, 'toggleActive']);
                Route::post('{user}/reset-temp',                       [GymTeamController::class, 'resetTemp']);
                Route::delete('{user}',                                [GymTeamController::class, 'destroy']);
                Route::get('permissions',                              [GymTeamController::class, 'permissions']);
                Route::put('permissions/roles/{role}',                 [GymTeamController::class, 'updateRolePermissions']);
                Route::put('permissions/users/{user}',                 [GymTeamController::class, 'updateUserPermissions']);
            });

            // Gym Roles (dynamic)
            Route::prefix('partner/roles')->middleware('branch.write')->group(function () {
                Route::get('/',          [RolesController::class, 'gymIndex']);
                Route::post('/',         [RolesController::class, 'gymStore']);
                Route::delete('{role}',  [RolesController::class, 'gymDestroy']);
            });

            Route::prefix('partner-portal')->middleware('branch.write')->group(function () {
                Route::get('dashboard',          [PartnerPortalController::class, 'dashboard']);
                Route::get('expected-visitors',  [CheckinController::class, 'expectedVisitors']);
                Route::put('profile',            [GymUpgradeController::class, 'updateProfile']);
                Route::post('upgrade-request',   [GymUpgradeController::class, 'submitRequest']);
                Route::get('upgrade-requests',   [GymUpgradeController::class, 'myRequests']);
                Route::get('branches',           [PartnerApplicationController::class, 'myBranches']);
                Route::post('branches',          [PartnerApplicationController::class, 'requestBranch']);
                Route::post('branches/{gym}/reset-password', [PartnerApplicationController::class, 'resetBranchPassword']);
            });
        });

        // Portal check-in scan (web): gym staff for their own branch, or an admin.
        Route::post('checkins/scan', [CheckinController::class, 'scan'])
            ->middleware(['area:gym,admin', 'permission:gym.checkins.create|memberships.edit', 'branch.write']);

        // ── Admin panel ────────────────────────────────────────────────
        Route::middleware('area:admin')->group(function () {

            // Diagnostic: super admins only (the response reveals mail settings)
            Route::post('admin/test-email', function (\Illuminate\Http\Request $request) {
                $request->validate(['email' => 'required|email']);
                try {
                    \Illuminate\Support\Facades\Mail::to($request->email)->send(
                        new \App\Mail\FitAccessNotificationMail(
                            recipientName: 'Test User',
                            emailSubject:  'FitAccess Mail Test',
                            heading:       'Mail is Working',
                            message:       'If you received this email, the mail configuration on the server is working correctly.',
                            buttonText:    'Go to FitAccess',
                            color:         '#22c55e',
                        )
                    );
                    return response()->json(['success' => true, 'message' => 'Test email sent to ' . $request->email]);
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'class'   => get_class($e),
                        'config'  => [
                            'mailer'   => config('mail.default'),
                            'host'     => config('mail.mailers.smtp.host'),
                            'port'     => config('mail.mailers.smtp.port'),
                            'from'     => config('mail.from.address'),
                            'queue'    => config('queue.default'),
                        ],
                    ], 500);
                }
            })->middleware('area:fulladmin');

            // Admin Team & Permissions
            Route::prefix('admin/team')->group(function () {
                Route::get('/',                                        [AdminUserController::class, 'index'])->middleware('permission:team.view');
                Route::post('/',                                       [AdminUserController::class, 'store'])->middleware('permission:team.create');
                Route::patch('{user}/toggle-active',                   [AdminUserController::class, 'toggleActive'])->middleware('permission:team.edit');
                Route::post('{user}/reset-temp',                       [AdminUserController::class, 'resetTemp'])->middleware('permission:team.edit');
                Route::post('{user}/reset-2fa',                        [AdminUserController::class, 'resetTwoFactor'])->middleware('permission:team.edit');
                Route::delete('{user}',                                [AdminUserController::class, 'destroy'])->middleware('permission:team.delete');
                Route::get('permissions',                              [AdminUserController::class, 'permissions'])->middleware('permission:permissions.view|team.view');
                Route::put('permissions/roles/{role}',                 [AdminUserController::class, 'updateRolePermissions'])->middleware('permission:permissions.manage');
                Route::put('permissions/users/{user}',                 [AdminUserController::class, 'updateUserPermissions'])->middleware('permission:permissions.manage');
            });

            // Admin Roles (dynamic)
            Route::prefix('admin/roles')->group(function () {
                Route::get('/',          [RolesController::class, 'adminIndex'])->middleware('permission:permissions.view|team.view');
                Route::post('/',         [RolesController::class, 'adminStore'])->middleware('permission:permissions.manage');
                Route::delete('{role}',  [RolesController::class, 'adminDestroy'])->middleware('permission:permissions.manage');
            });

            // Admin Notifications (bell in the admin layout, every admin role)
            Route::get( 'notifications',                                  [AdminNotificationController::class, 'index']);
            Route::get( 'notifications/unread-count',                     [AdminNotificationController::class, 'unreadCount']);
            Route::post('notifications/read-all',                         [AdminNotificationController::class, 'markAllRead']);
            Route::post('notifications/{adminNotification}/read',         [AdminNotificationController::class, 'markRead']);

            // Dashboard
            Route::prefix('dashboard')->group(function () {
                Route::middleware('permission:dashboard.view|reports.view|gyms.view')->group(function () {
                    Route::get('stats',                [DashboardController::class, 'stats']);
                    Route::get('recent-checkins',      [DashboardController::class, 'recentCheckins']);
                    Route::get('top-gyms',             [DashboardController::class, 'topGyms']);
                    Route::get('package-distribution',  [DashboardController::class, 'packageDistribution']);
                    Route::get('revenue-by-company',    [DashboardController::class, 'revenueByCompany']);
                    Route::get('checkin-trend',         [DashboardController::class, 'checkinTrend']);
                    Route::get('gym-stats',             [DashboardController::class, 'gymStats']);
                    Route::get('recent-registrations',  [DashboardController::class, 'recentRegistrations']);
                });
                Route::get('activity-log',          [DashboardController::class, 'activityLog'])->middleware('permission:activity_log.view');
                Route::get('attendance-report',     [DashboardController::class, 'attendanceReport'])->middleware('permission:reports.view');
            });

            // Admin → company/gym custom role management
            Route::get('companies/{company}/roles',          [RolesController::class, 'adminCompanyRoles'])->middleware('permission:permissions.view|companies.view');
            Route::post('companies/{company}/roles',         [RolesController::class, 'adminCompanyStore'])->middleware('permission:permissions.manage');
            Route::delete('companies/{company}/roles/{role}',[RolesController::class, 'adminCompanyDestroy'])->middleware('permission:permissions.manage');
            Route::get('gyms/{gym}/roles',                   [RolesController::class, 'adminGymRoles'])->middleware('permission:permissions.view|gyms.view');
            Route::post('gyms/{gym}/roles',                  [RolesController::class, 'adminGymStore'])->middleware('permission:permissions.manage');
            Route::delete('gyms/{gym}/roles/{role}',         [RolesController::class, 'adminGymDestroy'])->middleware('permission:permissions.manage');

            // Companies (the billing page also reads them)
            Route::post('companies/admin-create', [CompanyController::class, 'adminCreate'])->middleware('permission:companies.create');
            Route::apiResource('companies', CompanyController::class)
                ->middlewareFor(['index', 'show'], 'permission:companies.view|billing.view')
                ->middlewareFor('store', 'permission:companies.create')
                ->middlewareFor('update', 'permission:companies.edit')
                ->middlewareFor('destroy', 'permission:companies.delete');
            Route::patch('companies/{company}/toggle-active',      [CompanyController::class, 'toggleActive'])->middleware('permission:companies.edit');
            Route::post('companies/{company}/reset-2fa',           [AdminUserController::class, 'resetCompanyTwoFactor'])->middleware('permission:companies.edit');
            Route::get('companies/{company}/branches',             [AdminUserController::class, 'companyBranches'])->middleware('permission:companies.view|billing.view');
            Route::post('companies/{company}/branches/{branch}/reset-2fa', [AdminUserController::class, 'resetCompanyBranchTwoFactor'])->middleware('permission:companies.edit');
            Route::patch('companies/{company}/license-status',     [CompanyController::class, 'updateLicenseStatus'])->middleware('permission:companies.edit');
            Route::post('companies/{company}/deactivate',          [AdminBillingController::class, 'deactivateCompany'])->middleware('permission:companies.edit|billing.create');

            // v2 identity verification review
            Route::get('verifications',                              [\App\Http\Controllers\VerificationController::class, 'index'])->middleware('permission:employee_approvals.view|employees.view');
            Route::get('verifications/{verification}/photo',         [\App\Http\Controllers\VerificationController::class, 'photo'])->middleware('permission:employee_approvals.view|employees.view');
            Route::post('verifications/{verification}/approve',      [\App\Http\Controllers\VerificationController::class, 'approve'])->middleware('permission:employees.approve');
            Route::post('verifications/{verification}/reject',       [\App\Http\Controllers\VerificationController::class, 'reject'])->middleware('permission:employees.approve');

            // Employer applications from the public form (no account exists until approved)
            Route::get('company-applications', [\App\Http\Controllers\CompanyApplicationController::class, 'index'])->middleware('permission:companies.view');
            Route::post('company-applications/{companyApplication}/approve', [\App\Http\Controllers\CompanyApplicationController::class, 'approve'])->middleware('permission:companies.create');
            Route::post('company-applications/{companyApplication}/reject', [\App\Http\Controllers\CompanyApplicationController::class, 'reject'])->middleware('permission:companies.create');

            // Employees (the billing page also reads them)
            // pending-admin-approval also feeds the badge every admin layout polls.
            Route::get( 'employees/pending-admin-approval',    [EmployeeController::class, 'pendingAdminApproval']);
            Route::post('employees/import',                    [EmployeeController::class, 'importEmployees'])->middleware('permission:employees.create');
            Route::post('employees/bulk-admin-approve',        [EmployeeController::class, 'bulkAdminApprove'])->middleware('permission:employees.approve');
            Route::post('employees/bulk-admin-reject',         [EmployeeController::class, 'bulkAdminReject'])->middleware('permission:employees.approve');
            Route::post('employees/{employee}/admin-approve',  [EmployeeController::class, 'adminApprove'])->middleware('permission:employees.approve');
            Route::post('employees/{employee}/admin-reject',   [EmployeeController::class, 'adminReject'])->middleware('permission:employees.approve');
            Route::apiResource('employees', EmployeeController::class)
                ->middlewareFor(['index', 'show'], 'permission:employees.view|billing.view')
                ->middlewareFor('store', 'permission:employees.create')
                ->middlewareFor('update', 'permission:employees.edit')
                ->middlewareFor('destroy', 'permission:employees.delete');
            Route::post('employees/{employee}/enroll',        [EmployeeController::class, 'enroll'])->middleware('permission:employees.edit');
            Route::post('employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive'])->middleware('permission:employees.edit');
            Route::post('employees/{employee}/reset-2fa',     [AdminUserController::class, 'resetEmployeeTwoFactor'])->middleware('permission:employees.edit');
            Route::post('employees/{employee}/ban',           [EmployeeController::class, 'ban'])->middleware('permission:employees.ban');
            Route::post('employees/{employee}/unban',         [EmployeeController::class, 'unban'])->middleware('permission:employees.ban');

            // Gyms (the attendance and memberships pages also read them)
            Route::apiResource('gyms', GymController::class)
                ->middlewareFor(['index', 'show'], 'permission:gyms.view|memberships.view|reports.view')
                ->middlewareFor('store', 'permission:gyms.create')
                ->middlewareFor('update', 'permission:gyms.edit')
                ->middlewareFor('destroy', 'permission:gyms.delete');
            Route::post('gyms/{gym}/reset-2fa',                     [AdminUserController::class, 'resetGymTwoFactor'])->middleware('permission:gyms.edit');

            // Partner Applications
            Route::get('partner-applications',                              [PartnerApplicationController::class, 'index'])->middleware('permission:gyms.view');
            Route::post('partner-applications/{partnerApplication}/approve', [PartnerApplicationController::class, 'approve'])->middleware('permission:gyms.create');
            Route::post('partner-applications/{partnerApplication}/reject', [PartnerApplicationController::class, 'reject'])->middleware('permission:gyms.create');

            // Membership Plans (reads are above, for admin, HR and gyms)
            Route::post('membership-plans',                          [MembershipPlanController::class, 'store'])->middleware('permission:plans.create');
            Route::match(['put', 'patch'], 'membership-plans/{membership_plan}', [MembershipPlanController::class, 'update'])->middleware('permission:plans.edit');
            Route::delete('membership-plans/{membership_plan}',      [MembershipPlanController::class, 'destroy'])->middleware('permission:plans.delete');
            Route::patch('membership-plans/{membershipPlan}/toggle-active', [MembershipPlanController::class, 'toggleActive'])->middleware('permission:plans.toggle_active');

            // Memberships
            Route::apiResource('memberships', MembershipController::class)->except(['update'])
                ->middlewareFor(['index', 'show'], 'permission:memberships.view|employees.view')
                ->middlewareFor('store', 'permission:memberships.create')
                ->middlewareFor('destroy', 'permission:memberships.delete');
            Route::post('memberships/{membership}/suspend',   [MembershipController::class, 'suspend'])->middleware('permission:memberships.suspend');
            Route::post('memberships/{membership}/reinstate', [MembershipController::class, 'reinstate'])->middleware('permission:memberships.suspend');

            // Check-ins (admin records; gyms check members in via scan endpoints)
            Route::get('checkins',                     [CheckinController::class, 'index'])->middleware('permission:reports.view|memberships.view');
            Route::get('checkins/{checkin}',           [CheckinController::class, 'show'])->middleware('permission:reports.view|memberships.view');
            Route::post('checkins',                    [CheckinController::class, 'store'])->middleware('permission:memberships.edit');
            Route::post('checkins/{checkin}/checkout', [CheckinController::class, 'checkout'])->middleware('permission:memberships.edit');

            // Subscriptions
            Route::apiResource('subscriptions', SubscriptionController::class)->except(['update', 'destroy'])
                ->middlewareFor(['index', 'show'], 'permission:billing.view')
                ->middlewareFor('store', 'permission:billing.create');
            Route::post('subscriptions/preview',                  [SubscriptionController::class, 'preview'])->middleware('permission:billing.view');
            Route::post('subscriptions/{subscription}/activate',  [SubscriptionController::class, 'activate'])->middleware('permission:billing.create');
            Route::post('subscriptions/{subscription}/cancel',    [SubscriptionController::class, 'cancel'])->middleware('permission:billing.create');

            // Invoices
            Route::get('invoices',           [InvoiceController::class, 'index'])->middleware('permission:billing.view');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:billing.view');
            Route::post('subscriptions/{subscription}/invoices', [InvoiceController::class, 'generateForSubscription'])->middleware('permission:billing.create');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->middleware('permission:payments.verify');

            // Wellness Programs and Appointments (not used by the web app yet)
            Route::apiResource('wellness-programs', WellnessProgramController::class)
                ->middlewareFor(['index', 'show'], 'permission:dashboard.view')
                ->middlewareFor(['store', 'update', 'destroy'], 'area:fulladmin');
            Route::post('wellness-programs/{wellnessProgram}/enroll', [WellnessProgramController::class, 'enroll'])->middleware('area:fulladmin');
            Route::apiResource('appointments', AppointmentController::class)
                ->middlewareFor(['index', 'show'], 'permission:dashboard.view')
                ->middlewareFor(['store', 'update', 'destroy'], 'area:fulladmin');
            Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus'])->middleware('area:fulladmin');

            // Admin billing
            Route::prefix('admin/billing')->group(function () {
                // Invoices
                Route::get('invoices',                                      [AdminBillingController::class, 'index'])->middleware('permission:billing.view');
                Route::post('invoices/preview',                             [AdminBillingController::class, 'preview'])->middleware('permission:billing.create');
                Route::post('invoices/generate',                            [AdminBillingController::class, 'generate'])->middleware('permission:billing.create');
                Route::get('invoices/{billingInvoice}',                     [AdminBillingController::class, 'show'])->middleware('permission:billing.view');
                Route::post('invoices/{billingInvoice}/send',               [AdminBillingController::class, 'send'])->middleware('permission:billing.send');
                Route::delete('invoices/{billingInvoice}',                  [AdminBillingController::class, 'destroy'])->middleware('permission:billing.delete');

                // Payments / receipts
                Route::get('pending-payments',                              [AdminBillingController::class, 'pendingPayments'])->middleware('permission:billing.view');
                Route::post('payments/{billingPayment}/verify',             [AdminBillingController::class, 'verifyPayment'])->middleware('permission:payments.verify');
                Route::post('payments/{billingPayment}/reject',             [AdminBillingController::class, 'rejectPayment'])->middleware('permission:payments.reject');

                // Negotiations
                Route::get('negotiations',                                          [AdminBillingController::class, 'pendingNegotiations'])->middleware('permission:billing.view');
                Route::post('negotiations/{billingNegotiation}/approve',            [AdminBillingController::class, 'approveNegotiation'])->middleware('permission:negotiations.approve');
                Route::post('negotiations/{billingNegotiation}/reject',             [AdminBillingController::class, 'rejectNegotiation'])->middleware('permission:negotiations.reject');

                // Payment methods management
                Route::get('payment-methods',                               [AdminPaymentMethodController::class, 'index'])->middleware('permission:payment_methods.view|billing.view');
                Route::post('payment-methods',                              [AdminPaymentMethodController::class, 'store'])->middleware('permission:payment_methods.create');
                Route::put('payment-methods/{paymentMethod}',               [AdminPaymentMethodController::class, 'update'])->middleware('permission:payment_methods.edit');
                Route::delete('payment-methods/{paymentMethod}',            [AdminPaymentMethodController::class, 'destroy'])->middleware('permission:payment_methods.delete');
                Route::patch('payment-methods/{paymentMethod}/toggle',      [AdminPaymentMethodController::class, 'toggleActive'])->middleware('permission:payment_methods.toggle');
            });

            // Admin gym upgrade requests
            Route::prefix('admin/gym-upgrades')->group(function () {
                Route::get('/',                                    [GymUpgradeController::class, 'adminIndex'])->middleware('permission:gyms.view');
                Route::post('{gymUpgradeRequest}/approve',         [GymUpgradeController::class, 'adminApprove'])->middleware('permission:gyms.upgrade_approve');
                Route::post('{gymUpgradeRequest}/reject',          [GymUpgradeController::class, 'adminReject'])->middleware('permission:gyms.upgrade_reject');
            });
        });
    });
});
