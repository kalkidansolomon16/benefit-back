<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Company;
use App\Models\MembershipPlan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptionService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $subscriptions = Subscription::with(['company', 'plan'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->company_id, fn($q) => $q->where('company_id', $request->company_id))
            ->latest()
            ->paginate(10);

        return SubscriptionResource::collection($subscriptions);
    }

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $company = Company::findOrFail($request->company_id);
        $plan    = MembershipPlan::findOrFail($request->plan_id);

        $subscription = $this->subscriptionService->createSubscription($company, $plan, $request->validated());
        $subscription->load(['company', 'plan']);

        return response()->json(new SubscriptionResource($subscription), 201);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        $subscription->load(['company', 'plan', 'invoices']);
        return new SubscriptionResource($subscription);
    }

    public function activate(Subscription $subscription): JsonResponse
    {
        $subscription->update(['status' => 'active']);
        return response()->json(['message' => 'Subscription activated.']);
    }

    public function cancel(Subscription $subscription): JsonResponse
    {
        $subscription->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Subscription cancelled.']);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['plan_id' => 'required|exists:membership_plans,id', 'employee_count' => 'required|integer|min:1']);
        $plan = MembershipPlan::findOrFail($request->plan_id);
        return response()->json($this->subscriptionService->calculateFees($request->employee_count, $plan));
    }
}
