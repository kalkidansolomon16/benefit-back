<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PartnerPortalController extends Controller
{
    private function getMyGym(): Gym
    {
        $gym = Gym::where('contact_email', auth()->user()->email)
            ->withCount(['checkins', 'activeMembers'])
            ->first();

        if (!$gym) {
            abort(403, 'No gym is linked to this partner account.');
        }

        return $gym;
    }

    public function dashboard(): JsonResponse
    {
        $gym     = $this->getMyGym();
        $now     = Carbon::now();

        // ── Check-in stats ──────────────────────────────────────────
        $allCheckins = $gym->checkins()->with('employee.user')->get();

        $todayCheckins  = $allCheckins->filter(fn($c) => $c->checked_in_at?->isToday())->count();
        $weekCheckins   = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentWeek())->count();
        $monthCheckins  = $allCheckins->filter(fn($c) => $c->checked_in_at?->isCurrentMonth())->count();
        $totalCheckins  = $allCheckins->count();

        // Avg duration (only completed check-outs)
        $withDuration = $allCheckins->filter(fn($c) => $c->checked_out_at !== null);
        $avgDuration  = $withDuration->count()
            ? (int) round($withDuration->avg(fn($c) => $c->checked_in_at->diffInMinutes($c->checked_out_at)))
            : null;

        // ── Capacity utilisation ────────────────────────────────────
        $capacityPct = $gym->max_capacity > 0
            ? round(($gym->active_members_count / $gym->max_capacity) * 100)
            : 0;

        // ── Checkins per day (last 7 days) ──────────────────────────
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $day   = $now->copy()->subDays($i);
            $label = $day->format('D');   // Mon, Tue …
            $count = $allCheckins->filter(
                fn($c) => $c->checked_in_at?->isSameDay($day)
            )->count();
            $trend[] = ['day' => $label, 'date' => $day->toDateString(), 'count' => $count];
        }

        // ── Recent check-ins (last 10) ──────────────────────────────
        $recent = $allCheckins
            ->sortByDesc('checked_in_at')
            ->take(10)
            ->map(fn($c) => [
                'id'             => $c->id,
                'employee_name'  => $c->employee?->user?->name ?? 'Unknown',
                'fan_number'     => $c->employee?->fan_number,
                'checked_in_at'  => $c->checked_in_at?->format('Y-m-d H:i'),
                'checked_out_at' => $c->checked_out_at?->format('Y-m-d H:i'),
                'duration_min'   => $c->checked_in_at && $c->checked_out_at
                    ? $c->checked_in_at->diffInMinutes($c->checked_out_at)
                    : null,
                'status'         => $c->checked_out_at ? 'completed' : 'active',
            ])->values();

        // ── Gym info ────────────────────────────────────────────────
        return response()->json([
            'gym' => [
                'id'             => $gym->id,
                'name'           => $gym->name,
                'tier'           => $gym->tier,
                'city'           => $gym->city,
                'sub_city'       => $gym->sub_city,
                'address'        => $gym->address,
                'contact_person' => $gym->contact_person,
                'contact_phone'  => $gym->contact_phone,
                'contact_email'  => $gym->contact_email,
                'max_capacity'   => $gym->max_capacity,
                'facilities'     => $gym->facilities ?? [],
                'opening_hours'  => $gym->opening_hours,
                'is_active'      => $gym->is_active,
                'partnership_start' => $gym->partnership_start?->toDateString(),
            ],
            'stats' => [
                'today_checkins'   => $todayCheckins,
                'week_checkins'    => $weekCheckins,
                'month_checkins'   => $monthCheckins,
                'total_checkins'   => $totalCheckins,
                'active_members'   => $gym->active_members_count,
                'max_capacity'     => $gym->max_capacity,
                'capacity_pct'     => $capacityPct,
                'avg_duration_min' => $avgDuration,
            ],
            'checkin_trend'  => $trend,
            'recent_checkins'=> $recent,
        ]);
    }
}
