<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGymRequest;
use App\Http\Resources\GymResource;
use App\Models\AuditLog;
use App\Models\Gym;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GymController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $gyms = Gym::query()
            ->when($request->tier, fn($q) => $q->where('tier', $request->tier))
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->is_partner !== null, fn($q) => $q->where('is_partner', $request->boolean('is_partner')))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->withCount(['activeMembers'])
            ->latest()
            ->paginate(10);

        return GymResource::collection($gyms);
    }

    public function store(StoreGymRequest $request): JsonResponse
    {
        $gym = Gym::create($request->validated());
        AuditLog::record('created', $gym);
        return response()->json(new GymResource($gym), 201);
    }

    public function show(Gym $gym): GymResource
    {
        $gym->loadCount(['activeMembers', 'checkins']);
        return new GymResource($gym);
    }

    public function update(StoreGymRequest $request, Gym $gym): GymResource
    {
        $old = $gym->only(array_keys($request->validated()));
        $gym->update($request->validated());
        AuditLog::record('updated', $gym, $old, $request->validated());
        return new GymResource($gym);
    }

    public function destroy(Gym $gym): JsonResponse
    {
        AuditLog::record('deleted', $gym);
        $gym->delete();
        return response()->json(['message' => 'Gym deleted.']);
    }
}
