<?php

use App\Http\Controllers\AppointmentController;
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

    // ── Auth (public) ───────────────────────────────────────────────
    Route::post('auth/login',  [AuthController::class, 'login']);

    // ── Protected ───────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('auth/logout',          [AuthController::class, 'logout']);
        Route::get('auth/me',               [AuthController::class, 'me']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Dashboard
        Route::prefix('dashboard')->group(function () {
            Route::get('stats',           [DashboardController::class, 'stats']);
            Route::get('recent-checkins', [DashboardController::class, 'recentCheckins']);
            Route::get('top-gyms',        [DashboardController::class, 'topGyms']);
        });

        // Companies
        Route::apiResource('companies', CompanyController::class);
        Route::patch('companies/{company}/toggle-active', [CompanyController::class, 'toggleActive']);

        // Employees
        Route::apiResource('employees', EmployeeController::class);
        Route::post('employees/{employee}/enroll', [EmployeeController::class, 'enroll']);

        // Gyms
        Route::apiResource('gyms', GymController::class);

        // Membership Plans
        Route::apiResource('membership-plans', MembershipPlanController::class);

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
    });
});
