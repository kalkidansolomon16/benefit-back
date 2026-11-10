<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\CardNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "Check if your FitAccess card is active" lookup.
 *
 * Answers only active / inactive / not found, plus the plan name and expiry for
 * an active card. It never reveals who owns a card or which company they work for.
 */
class CardStatusController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $request->validate(['card_number' => 'required|string|max:32']);

        $number = CardNumber::normalize($request->input('card_number'));
        if ($number === null) {
            return response()->json([
                'message' => 'This is not a valid FitAccess card number. Please check the 12 digits and try again.',
                'errors'  => ['card_number' => ['Invalid card number.']],
            ], 422);
        }

        $employee = Employee::withTrashed()
            ->with(['user:id,is_active', 'company:id,is_active,company_status', 'activeMembership.plan:id,name'])
            ->where('card_number', $number)
            ->first();

        if (!$employee) {
            return response()->json(['status' => 'not_found', 'card_number' => CardNumber::format($number)]);
        }

        $active = self::isActive($employee);
        $membership = $employee->activeMembership;

        return response()->json([
            'status'      => $active ? 'active' : 'inactive',
            'card_number' => CardNumber::format($number),
            'plan'        => $active ? $membership?->plan?->name : null,
            'valid_until' => $active ? $employee->paid_until?->toDateString() : null,
        ]);
    }

    public static function isActive(Employee $employee): bool
    {
        if ($employee->trashed() || !$employee->is_enrolled || $employee->is_banned) {
            return false;
        }
        if (!$employee->user?->is_active) {
            return false;
        }

        $company = $employee->company;
        if (!$company || !$company->is_active || !in_array($company->company_status ?? 'active', ['active'], true)) {
            return false;
        }

        // Active only while the company's invoice covers today
        if (!$employee->hasPaidAccess()) {
            return false;
        }

        // A membership suspended by FitAccess also blocks the card
        return !$employee->memberships()->where('status', 'suspended')->exists()
            || $employee->memberships()->where('status', 'active')->exists();
    }
}
