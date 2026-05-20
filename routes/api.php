<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\HRController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\PartnerPortalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CheckinController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\GymController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\MembershipPlanController;
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

    // ── Protected ───────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout',          [AuthController::class, 'logout']);
        Route::get('auth/me',               [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Dashboard
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

        // Companies
        Route::post('companies/admin-create', [CompanyController::class, 'adminCreate']);
        Route::apiResource('companies', CompanyController::class);
        Route::patch('companies/{company}/toggle-active',      [CompanyController::class, 'toggleActive']);
        Route::patch('companies/{company}/license-status',     [CompanyController::class, 'updateLicenseStatus']);

        // Employees
        Route::apiResource('employees', EmployeeController::class);
        Route::post('employees/{employee}/enroll', [EmployeeController::class, 'enroll']);

        // Gyms
        Route::apiResource('gyms', GymController::class);

        // Partner Applications
        Route::get('partner-applications',                              [PartnerApplicationController::class, 'index']);
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
        });

        // ── Partner / Gym portal ───────────────────────────────────
        Route::prefix('partner')->group(function () {
            Route::get('dashboard', [PartnerPortalController::class, 'dashboard']);
        });

        // ── Company HR portal ──────────────────────────────────────
        Route::prefix('hr')->group(function () {
            Route::get('my-company',                          [HRController::class, 'myCompany']);
            Route::get('dashboard',                           [HRController::class, 'dashboard']);
            Route::get('employees',                           [HRController::class, 'employees']);
            Route::post('employees',                          [HRController::class, 'registerEmployee']);
            Route::post('employees/{employee}/approve',       [HRController::class, 'approveEmployee']);
            Route::post('employees/{employee}/reject',        [HRController::class, 'rejectEmployee']);
        });
    });
});
