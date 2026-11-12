<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Checkin;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Gym;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

    /** Donut chart — companies grouped by subscription tier */
    public function packageDistribution(): JsonResponse
    {
        $tiers = Company::whereNull('deleted_at')
            ->selectRaw('tier, COUNT(*) as count')
            ->groupBy('tier')
            ->pluck('count', 'tier');

        return response()->json([
            'basic'      => (int) ($tiers->get('basic', 0)),
            'basic_plus' => (int) ($tiers->get('basic_plus', 0)),
            'platinum'   => (int) ($tiers->get('platinum', 0)),
        ]);
    }

    /** Bar chart — active subscription revenue per company */
    public function revenueByCompany(): JsonResponse
    {
        $data = DB::table('subscriptions')
            ->join('companies', 'subscriptions.company_id', '=', 'companies.id')
            ->where('subscriptions.status', 'active')
            ->whereNull('companies.deleted_at')
            ->selectRaw('companies.name, SUM(subscriptions.total_amount_etb) as revenue')
            ->groupBy('companies.id', 'companies.name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        return response()->json($data);
    }

    /** Line chart — daily check-in counts for the last 30 days */
    public function checkinTrend(): JsonResponse
    {
        $trend = collect(range(29, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo)->toDateString();
            return [
                'date'  => $date,
                'count' => Checkin::whereDate('checked_in_at', $date)->count(),
            ];
        });

        return response()->json($trend);
    }

    /** Activity log — most recent audit entries */
    public function activityLog(): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,email,role')
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($log) => [
                'id'         => $log->id,
                'action'     => $log->action,
                'model_type' => $log->model_type,
                'model_id'   => $log->model_id,
                'user'       => $log->user ? [
                    'name'  => $log->user->name,
                    'email' => $log->user->email,
                    'role'  => $log->user->role,
                ] : null,
                'created_at' => $log->created_at,
            ]);

        return response()->json($logs);
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

    /** Gym cards page — per-gym today / total-members stats */
    public function gymStats(): JsonResponse
    {
        $gyms = Gym::whereNull('deleted_at')
            ->withCount([
                'checkins as checkins_today'  => fn($q) => $q->whereDate('checked_in_at', today()),
                'memberships as total_members',
            ])
            ->orderBy('name')
            ->get();

        return response()->json($gyms);
    }

    /** Recently enrolled employees (for dashboard Recent Registrations) */
    public function recentRegistrations(): JsonResponse
    {
        $employees = \App\Models\Employee::with(['user:id,name,email', 'company:id,name,tier'])
            ->where('is_enrolled', true)
            ->latest('enrolled_at')
            ->limit(10)
            ->get()
            ->map(fn($e) => [
                'id'          => $e->id,
                'name'        => $e->user?->name,
                'company'     => $e->company?->name,
                'tier'        => $e->company?->tier,
                'enrolled_at' => $e->enrolled_at?->toDateString() ?? $e->created_at->toDateString(),
            ]);

        return response()->json($employees);
    }

    /** Attendance report — gym summaries + period breakdown */
    public function attendanceReport(\Illuminate\Http\Request $request): JsonResponse
    {
        $gymId  = $request->input('gym_id');
        $from   = $request->input('from');
        $to     = $request->input('to');
        $period = $request->input('period', 'monthly');

        $formatStr = match ($period) {
            'daily'  => '%Y-%m-%d',
            'weekly' => '%Y-%u',
            default  => '%Y-%m',
        };

        // Per-gym summary
        $gymSummaries = DB::table('checkins')
            ->join('gyms', 'checkins.gym_id', '=', 'gyms.id')
            ->whereNull('gyms.deleted_at')
            ->when($gymId, fn($q) => $q->where('checkins.gym_id', $gymId))
            ->when($from,  fn($q) => $q->whereDate('checkins.checked_in_at', '>=', $from))
            ->when($to,    fn($q) => $q->whereDate('checkins.checked_in_at', '<=', $to))
            ->selectRaw('
                gyms.id,
                gyms.name,
                gyms.tier,
                COUNT(*)                                        AS total_checkins,
                COUNT(DISTINCT checkins.employee_id)            AS unique_members,
                COUNT(DISTINCT DATE(checkins.checked_in_at))    AS active_days,
                MIN(checkins.checked_in_at)                     AS first_checkin,
                MAX(checkins.checked_in_at)                     AS last_checkin
            ')
            ->groupBy('gyms.id', 'gyms.name', 'gyms.tier')
            ->orderByDesc('total_checkins')
            ->get();

        // Period breakdown per gym
        $periodData = DB::table('checkins')
            ->join('gyms', 'checkins.gym_id', '=', 'gyms.id')
            ->whereNull('gyms.deleted_at')
            ->when($gymId, fn($q) => $q->where('checkins.gym_id', $gymId))
            ->when($from,  fn($q) => $q->whereDate('checkins.checked_in_at', '>=', $from))
            ->when($to,    fn($q) => $q->whereDate('checkins.checked_in_at', '<=', $to))
            ->selectRaw("
                DATE_FORMAT(checkins.checked_in_at, '{$formatStr}') AS period,
                gyms.id                                              AS gym_id,
                gyms.name                                            AS gym_name,
                gyms.tier                                            AS gym_tier,
                COUNT(*)                                             AS checkins,
                COUNT(DISTINCT checkins.employee_id)                 AS unique_members,
                COUNT(DISTINCT DATE(checkins.checked_in_at))         AS active_days
            ")
            ->groupByRaw("period, gyms.id, gyms.name, gyms.tier")
            ->orderBy('period')
            ->get();

        return response()->json([
            'gym_summaries' => $gymSummaries,
            'period_data'   => $periodData,
        ]);
    }
}
