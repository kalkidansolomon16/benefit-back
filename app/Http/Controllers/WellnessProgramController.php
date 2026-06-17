<?php

namespace App\Http\Controllers;

use App\Models\WellnessProgram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WellnessProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $programs = WellnessProgram::query()
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->is_online !== null, fn($q) => $q->where('is_online', $request->boolean('is_online')))
            ->withCount('employees')
            ->latest()
            ->paginate(10);

        return response()->json($programs);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'category'         => 'required|in:fitness,mental_health,nutrition,medical,yoga,lifestyle,other',
            'provider'         => 'nullable|string|max:255',
            'provider_contact' => 'nullable|string|max:255',
            'location'         => 'nullable|string',
            'is_online'        => 'boolean',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after:start_date',
            'max_participants' => 'nullable|integer|min:1',
        ]);

        $program = WellnessProgram::create($validated);
        return response()->json($program, 201);
    }

    public function show(WellnessProgram $wellnessProgram): JsonResponse
    {
        $wellnessProgram->loadCount('employees');
        return response()->json($wellnessProgram);
    }

    public function update(Request $request, WellnessProgram $wellnessProgram): JsonResponse
    {
        $wellnessProgram->update($request->all());
        return response()->json($wellnessProgram);
    }

    public function destroy(WellnessProgram $wellnessProgram): JsonResponse
    {
        $wellnessProgram->delete();
        return response()->json(['message' => 'Program deleted.']);
    }

    public function enroll(Request $request, WellnessProgram $wellnessProgram): JsonResponse
    {
        $request->validate(['employee_id' => 'required|exists:employees,id']);
        $wellnessProgram->employees()->syncWithoutDetaching([
            $request->employee_id => ['status' => 'enrolled', 'enrolled_at' => now()],
        ]);
        return response()->json(['message' => 'Employee enrolled in program.']);
    }
}
