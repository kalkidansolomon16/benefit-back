<?php

namespace App\Http\Controllers;

use App\Models\Checkin;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json([
            'overview' => [
                'total_companies'    => Company::count(),
                'active_companies'   => Company::where('is_active', true)->count(),
                'total_employees'    => Employee::count(),
                'enrolled_employees' => Employee::where('is_enrolled', true)->count(),
                'total_gyms'         => Gym::count(),
                'partner_gyms'       => Gym::where('is_partner', true)->count(),
                'active_memberships' => Membership::where('status', 'active')->count(),
            ],
            'revenue' => [
                'total_subscriptions_etb' => Subscription::where('status', 'active')->sum('total_amount_etb'),
                'total_service_fees_etb'  => Subscription::where('status', 'active')->sum('service_fee_etb'),
                'total_absenteeism_etb'   => Subscription::where('status', 'active')->sum('absenteeism_fee_etb'),
                'paid_invoices_etb'       => Invoice::where('status', 'paid')->sum('total_etb'),
                'pending_invoices_etb'    => Invoice::where('status', 'sent')->sum('total_etb'),
            ],
            'activity' => [
                'checkins_today' => Checkin::whereDate('checked_in_at', today())->count(),
                'checkins_week'  => Checkin::where('checked_in_at', '>=', now()->startOfWeek())->count(),
                'checkins_month' => Checkin::where('checked_in_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function recentCheckins(): JsonResponse
    {
        $checkins = Checkin::with(['employee.user', 'gym'])
            ->latest('checked_in_at')
            ->limit(20)
            ->get();

        return response()->json($checkins);
    }

    public function topGyms(): JsonResponse
    {
        $gyms = Gym::withCount(['checkins' => fn($q) => $q->where('checked_in_at', '>=', now()->startOfMonth())])
            ->orderByDesc('checkins_count')
            ->limit(10)
            ->get();

        return response()->json($gyms);
    }
}
