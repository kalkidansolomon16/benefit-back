<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAppointmentRequest;
use App\Models\Appointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $appointments = Appointment::with(['employee.user'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->date, fn($q) => $q->whereDate('appointment_at', $request->date))
            ->orderBy('appointment_at')
            ->paginate(10);

        return response()->json($appointments);
    }

    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $appointment = Appointment::create($request->validated());
        $appointment->load('employee.user');
        return response()->json($appointment, 201);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        return response()->json($appointment->load('employee.user'));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'    => 'sometimes|required|exists:employees,id',
            'doctor_name'    => 'sometimes|nullable|string|max:255',
            'clinic_name'    => 'sometimes|nullable|string|max:255',
            'specialty'      => 'sometimes|nullable|string|max:100',
            'appointment_at' => 'sometimes|required|date|after:now',
            'is_online'      => 'sometimes|boolean',
            'meeting_link'   => 'sometimes|nullable|url',
            'notes'          => 'sometimes|nullable|string',
        ]);

        $appointment->update($validated);
        return response()->json($appointment);
    }

    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $request->validate(['status' => 'required|in:pending,confirmed,completed,cancelled,no_show']);
        $appointment->update(['status' => $request->status]);
        return response()->json($appointment);
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->delete();
        return response()->json(['message' => 'Appointment deleted.']);
    }
}
