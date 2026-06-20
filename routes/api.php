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
    Route::post('auth/login',             [AuthController::class, 'login']);
    Route::post('auth/register/company',  [AuthController::class, 'registerCompany']);
    Route::post('auth/register/employee', [AuthController::class, 'registerEmployee']);
    Route::post('auth/register/partner',  [AuthController::class, 'registerPartner']);
    Route::get('public/companies',        [CompanyController::class, 'publicList']);
    Route::post('auth/register/member', [\App\Http\Controllers\AuthController::class, 'registerMember']);

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
            'password' => 'required|min:8|confirmed',
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


    // ── Telegram webhook (public — Telegram must reach this) ─────────
    Route::post('telegram/webhook',        [TelegramController::class, 'webhook']);

    // ── Auth & public ────────────────────────────────────────────────
    Route::post('auth/login',              [AuthController::class, 'login']);
    Route::post('auth/forgot-password',    [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password',     [AuthController::class, 'resetPassword']);
    Route::post('auth/register/company',   [AuthController::class, 'registerCompany']);
    Route::post('auth/register/employee',  [AuthController::class, 'registerEmployee']);
    Route::post('auth/register/partner',   [AuthController::class, 'registerPartner']);
    Route::get('public/companies',         [CompanyController::class, 'publicList']);
    Route::get('public/plans',             [MembershipPlanController::class, 'index']);

    // ── Protected ───────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout',          [AuthController::class, 'logout']);
        Route::get('auth/me',               [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        // ── MOBILE — Member (User App) ────────────────────────────────────────
        Route::prefix('mobile')->group(function () {
            Route::get('dashboard',              [\App\Http\Controllers\MobileController::class, 'dashboard']);
            Route::get('qr-token',               [\App\Http\Controllers\MobileController::class, 'qrToken']);
            Route::get('profile',                [\App\Http\Controllers\MobileController::class, 'profile']);
            Route::patch('profile',              [\App\Http\Controllers\MobileController::class, 'updateProfile']);
            Route::post('profile/photo',         [\App\Http\Controllers\MobileController::class, 'uploadPhoto']);
            Route::get('checkins',               [\App\Http\Controllers\MobileController::class, 'checkins']);
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
        Route::prefix('partner')->group(function () {
            Route::get('me',                     [\App\Http\Controllers\PartnerMobileController::class, 'me']);
            Route::post('scan',                  [\App\Http\Controllers\PartnerMobileController::class, 'scan']);
            Route::get('visits/today',           [\App\Http\Controllers\PartnerMobileController::class, 'visitsToday']);
            Route::get('visits/monthly',         [\App\Http\Controllers\PartnerMobileController::class, 'visitsMonthly']);
            Route::get('reports',                [\App\Http\Controllers\PartnerMobileController::class, 'reports']);
            Route::get('financials',             [\App\Http\Controllers\PartnerMobileController::class, 'financials']);
            Route::get('staff',                  [\App\Http\Controllers\PartnerMobileController::class, 'staffList']);
            Route::post('staff/invite',          [\App\Http\Controllers\PartnerMobileController::class, 'inviteStaff']);
            Route::delete('staff/{gymStaff}',    [\App\Http\Controllers\PartnerMobileController::class, 'removeStaff']);
            Route::patch('facility',             [\App\Http\Controllers\PartnerMobileController::class, 'updateFacility']);
            Route::post('facility/photo',        [\App\Http\Controllers\PartnerMobileController::class, 'uploadFacilityPhoto']);
        // ── Telegram user settings ────────────────────────────────────
        Route::prefix('telegram')->group(function () {
            Route::get('status',            [TelegramSettingsController::class, 'status']);
            Route::post('generate-code',    [TelegramSettingsController::class, 'generateCode']);
            Route::delete('unlink',         [TelegramSettingsController::class, 'unlink']);
        });
        }); // closes prefix('partner') mobile group

        Route::post('auth/logout',             [AuthController::class, 'logout']);
        Route::get('auth/me',                  [AuthController::class, 'me']);
        Route::post('auth/change-password',    [AuthController::class, 'changePassword']);
        Route::post('auth/first-login-reset',  [AuthController::class, 'firstLoginReset']);

        // ── Admin Team & Permissions ──────────────────────────────────
        Route::prefix('admin/team')->group(function () {
            Route::get('/',                                        [AdminUserController::class, 'index']);
            Route::post('/',                                       [AdminUserController::class, 'store']);
            Route::patch('{user}/toggle-active',                   [AdminUserController::class, 'toggleActive']);
            Route::post('{user}/reset-temp',                       [AdminUserController::class, 'resetTemp']);
            Route::delete('{user}',                                [AdminUserController::class, 'destroy']);
            Route::get('permissions',                              [AdminUserController::class, 'permissions']);
            Route::put('permissions/roles/{role}',                 [AdminUserController::class, 'updateRolePermissions']);
            Route::put('permissions/users/{user}',                 [AdminUserController::class, 'updateUserPermissions']);
        });

        // ── Admin Roles (dynamic) ─────────────────────────────────────
        Route::prefix('admin/roles')->group(function () {
            Route::get('/',          [RolesController::class, 'adminIndex']);
            Route::post('/',         [RolesController::class, 'adminStore']);
            Route::delete('{role}',  [RolesController::class, 'adminDestroy']);
        });

        // ── Company Team & Permissions ────────────────────────────────
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

        // ── Company Roles (dynamic) ───────────────────────────────────
        Route::prefix('hr/roles')->group(function () {
            Route::get('/',          [RolesController::class, 'companyIndex']);
            Route::post('/',         [RolesController::class, 'companyStore']);
            Route::delete('{role}',  [RolesController::class, 'companyDestroy']);
        });

        // ── Gym Team & Permissions ────────────────────────────────────
        Route::prefix('partner/team')->group(function () {
            Route::get('/',                                        [GymTeamController::class, 'index']);
            Route::post('/',                                       [GymTeamController::class, 'store']);
            Route::patch('{user}/toggle-active',                   [GymTeamController::class, 'toggleActive']);
            Route::post('{user}/reset-temp',                       [GymTeamController::class, 'resetTemp']);
            Route::delete('{user}',                                [GymTeamController::class, 'destroy']);
            Route::get('permissions',                              [GymTeamController::class, 'permissions']);
            Route::put('permissions/roles/{role}',                 [GymTeamController::class, 'updateRolePermissions']);
            Route::put('permissions/users/{user}',                 [GymTeamController::class, 'updateUserPermissions']);
        });

        // ── Gym Roles (dynamic) ───────────────────────────────────────
        Route::prefix('partner/roles')->group(function () {
            Route::get('/',          [RolesController::class, 'gymIndex']);
            Route::post('/',         [RolesController::class, 'gymStore']);
            Route::delete('{role}',  [RolesController::class, 'gymDestroy']);
        });

        // Dashboard
        // Admin Notifications
        Route::get( 'notifications',                                  [AdminNotificationController::class, 'index']);
        Route::get( 'notifications/unread-count',                     [AdminNotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all',                         [AdminNotificationController::class, 'markAllRead']);
        Route::post('notifications/{adminNotification}/read',         [AdminNotificationController::class, 'markRead']);

        Route::prefix('dashboard')->group(function () {
            Route::get('stats',                [DashboardController::class, 'stats']);
            Route::get('recent-checkins',      [DashboardController::class, 'recentCheckins']);
            Route::get('top-gyms',             [DashboardController::class, 'topGyms']);
            Route::get('package-distribution',  [DashboardController::class, 'packageDistribution']);
            Route::get('revenue-by-company',    [DashboardController::class, 'revenueByCompany']);
            Route::get('checkin-trend',         [DashboardController::class, 'checkinTrend']);
            Route::get('activity-log',          [DashboardController::class, 'activityLog']);
            Route::get('gym-stats',             [DashboardController::class, 'gymStats']);
            Route::get('recent-registrations',  [DashboardController::class, 'recentRegistrations']);
            Route::get('attendance-report',     [DashboardController::class, 'attendanceReport']);
        });

        // Admin → company/gym custom role management
        Route::get('companies/{company}/roles',          [RolesController::class, 'adminCompanyRoles']);
        Route::post('companies/{company}/roles',         [RolesController::class, 'adminCompanyStore']);
        Route::delete('companies/{company}/roles/{role}',[RolesController::class, 'adminCompanyDestroy']);
        Route::get('gyms/{gym}/roles',                   [RolesController::class, 'adminGymRoles']);
        Route::post('gyms/{gym}/roles',                  [RolesController::class, 'adminGymStore']);
        Route::delete('gyms/{gym}/roles/{role}',         [RolesController::class, 'adminGymDestroy']);

        // Companies
        Route::post('companies/admin-create', [CompanyController::class, 'adminCreate']);
        Route::apiResource('companies', CompanyController::class);
        Route::patch('companies/{company}/toggle-active',      [CompanyController::class, 'toggleActive']);
        Route::patch('companies/{company}/license-status',     [CompanyController::class, 'updateLicenseStatus']);
        Route::post('companies/{company}/deactivate',          [AdminBillingController::class, 'deactivateCompany']);

        // Employees
        Route::get( 'employees/pending-admin-approval',    [EmployeeController::class, 'pendingAdminApproval']);
        Route::post('employees/{employee}/admin-approve',  [EmployeeController::class, 'adminApprove']);
        Route::post('employees/{employee}/admin-reject',   [EmployeeController::class, 'adminReject']);
        Route::apiResource('employees', EmployeeController::class);
        Route::post('employees/{employee}/enroll',        [EmployeeController::class, 'enroll']);
        Route::post('employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive']);
        Route::post('employees/{employee}/ban',           [EmployeeController::class, 'ban']);
        Route::post('employees/{employee}/unban',         [EmployeeController::class, 'unban']);

        // Gyms
        Route::apiResource('gyms', GymController::class);

        // Partner Applications
        Route::get('partner-applications',                              [PartnerApplicationController::class, 'index']);
        Route::post('partner-applications/{partnerApplication}/approve', [PartnerApplicationController::class, 'approve']);
        Route::post('partner-applications/{partnerApplication}/approve',[PartnerApplicationController::class, 'approve']);
        Route::post('partner-applications/{partnerApplication}/reject', [PartnerApplicationController::class, 'reject']);

        // Membership Plans
        Route::apiResource('membership-plans', MembershipPlanController::class);
        Route::patch('membership-plans/{membershipPlan}/toggle-active', [MembershipPlanController::class, 'toggleActive']);

        // Memberships
        Route::apiResource('memberships', MembershipController::class)->except(['update']);
        Route::post('memberships/{membership}/suspend',   [MembershipController::class, 'suspend']);
        Route::post('memberships/{membership}/reinstate', [MembershipController::class, 'reinstate']);

        // Check-ins
        Route::get('checkins',                     [CheckinController::class, 'index']);
        Route::post('checkins',                    [CheckinController::class, 'store']);
        Route::post('checkins/scan',               [CheckinController::class, 'scan']);
        Route::get('checkins/{checkin}',           [CheckinController::class, 'show']);
        Route::post('checkins/{checkin}/checkout', [CheckinController::class, 'checkout']);

        // Subscriptions
        Route::apiResource('subscriptions', SubscriptionController::class)->except(['update', 'destroy']);
        Route::post('subscriptions/preview',                  [SubscriptionController::class, 'preview']);
        Route::post('subscriptions/{subscription}/activate',  [SubscriptionController::class, 'activate']);
        Route::post('subscriptions/{subscription}/cancel',    [SubscriptionController::class, 'cancel']);

        // Invoices
        Route::get('invoices',           [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::post('subscriptions/{subscription}/invoices', [InvoiceController::class, 'generateForSubscription']);
        Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid']);

        // Wellness Programs
        Route::apiResource('wellness-programs', WellnessProgramController::class);
        Route::post('wellness-programs/{wellnessProgram}/enroll', [WellnessProgramController::class, 'enroll']);

        // Appointments
        Route::apiResource('appointments', AppointmentController::class);
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

        // ── Employee portal ────────────────────────────────────────
        Route::prefix('employee')->group(function () {
            Route::get('dashboard', [EmployeePortalController::class, 'dashboard']);
            Route::get('dashboard',     [EmployeePortalController::class, 'dashboard']);
            Route::get('checkin-token', [CheckinController::class, 'token']);
            Route::get('gyms',          [CheckinController::class, 'myGyms']);
            Route::post('select-gym',   [CheckinController::class, 'selectGym']);
        });

        // ── Partner / Gym portal ───────────────────────────────────
        Route::prefix('partner')->group(function () {
            Route::get('dashboard', [PartnerPortalController::class, 'dashboard']);
            Route::get('dashboard',                       [PartnerPortalController::class, 'dashboard']);
            Route::get('expected-visitors',               [CheckinController::class, 'expectedVisitors']);
            Route::put('profile',                         [GymUpgradeController::class, 'updateProfile']);
            Route::post('upgrade-request',                [GymUpgradeController::class, 'submitRequest']);
            Route::get('upgrade-requests',                [GymUpgradeController::class, 'myRequests']);
        });

        // ── Company HR portal ──────────────────────────────────────
        Route::prefix('hr')->group(function () {
            Route::get('my-company',                          [HRController::class, 'myCompany']);
            Route::get('dashboard',                           [HRController::class, 'dashboard']);
            Route::get('employees',                           [HRController::class, 'employees']);
            Route::post('employees',                          [HRController::class, 'registerEmployee']);
            Route::post('employees/{employee}/approve',       [HRController::class, 'approveEmployee']);
            Route::post('employees/{employee}/reject',        [HRController::class, 'rejectEmployee']);
            Route::post('employees/{employee}/ban',           [HRController::class, 'banEmployee']);
            Route::post('employees/{employee}/unban',         [HRController::class, 'unbanEmployee']);

            // Billing (company-side)
            Route::get('billing/invoices',                                      [CompanyBillingController::class, 'index']);
            Route::get('billing/invoices/{billingInvoice}',                     [CompanyBillingController::class, 'show']);
            Route::get('billing/payment-methods',                               [CompanyBillingController::class, 'paymentMethods']);
            Route::post('billing/invoices/{billingInvoice}/pay',                [CompanyBillingController::class, 'submitPayment']);
            Route::post('billing/invoices/{billingInvoice}/negotiate',          [CompanyBillingController::class, 'submitNegotiation']);
            Route::post('billing/invoices/{billingInvoice}/chapa/initialize',   [ChapaController::class, 'initialize']);
            Route::get('billing/chapa/verify',                                  [ChapaController::class, 'verify']);
        });

        // ── Admin billing ──────────────────────────────────────────
        Route::prefix('admin/billing')->group(function () {
            // Invoices
            Route::get('invoices',                                      [AdminBillingController::class, 'index']);
            Route::post('invoices/generate',                            [AdminBillingController::class, 'generate']);
            Route::get('invoices/{billingInvoice}',                     [AdminBillingController::class, 'show']);
            Route::post('invoices/{billingInvoice}/send',               [AdminBillingController::class, 'send']);
            Route::delete('invoices/{billingInvoice}',                  [AdminBillingController::class, 'destroy']);

            // Payments / receipts
            Route::get('pending-payments',                              [AdminBillingController::class, 'pendingPayments']);
            Route::post('payments/{billingPayment}/verify',             [AdminBillingController::class, 'verifyPayment']);
            Route::post('payments/{billingPayment}/reject',             [AdminBillingController::class, 'rejectPayment']);

            // Negotiations
            Route::get('negotiations',                                          [AdminBillingController::class, 'pendingNegotiations']);
            Route::post('negotiations/{billingNegotiation}/approve',            [AdminBillingController::class, 'approveNegotiation']);
            Route::post('negotiations/{billingNegotiation}/reject',             [AdminBillingController::class, 'rejectNegotiation']);

            // Payment methods management
            Route::get('payment-methods',                               [AdminPaymentMethodController::class, 'index']);
            Route::post('payment-methods',                              [AdminPaymentMethodController::class, 'store']);
            Route::put('payment-methods/{paymentMethod}',               [AdminPaymentMethodController::class, 'update']);
            Route::delete('payment-methods/{paymentMethod}',            [AdminPaymentMethodController::class, 'destroy']);
            Route::patch('payment-methods/{paymentMethod}/toggle',      [AdminPaymentMethodController::class, 'toggleActive']);
        });

        // ── Admin gym upgrade requests ─────────────────────────────
        Route::prefix('admin/gym-upgrades')->group(function () {
            Route::get('/',                                    [GymUpgradeController::class, 'adminIndex']);
            Route::post('{gymUpgradeRequest}/approve',         [GymUpgradeController::class, 'adminApprove']);
            Route::post('{gymUpgradeRequest}/reject',          [GymUpgradeController::class, 'adminReject']);
        });
    });
});