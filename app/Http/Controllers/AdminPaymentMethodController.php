<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentMethodController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(PaymentMethod::orderBy('bank_name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'           => 'required|string|in:bank,telebirr,cbe_birr,mpesa,other',
            'bank_name'      => 'required|string|max:100',
            'account_name'   => 'required|string|max:150',
            'account_number' => 'required|string|max:50',
            'instructions'   => 'nullable|string|max:1000',
            'is_active'      => 'boolean',
        ]);

        $method = PaymentMethod::create($validated);

        return response()->json($method, 201);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $validated = $request->validate([
            'type'           => 'sometimes|required|string|in:bank,telebirr,cbe_birr,mpesa,other',
            'bank_name'      => 'sometimes|required|string|max:100',
            'account_name'   => 'sometimes|required|string|max:150',
            'account_number' => 'sometimes|required|string|max:50',
            'instructions'   => 'nullable|string|max:1000',
            'is_active'      => 'boolean',
        ]);

        $paymentMethod->update($validated);

        return response()->json($paymentMethod);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->delete();
        return response()->json(['message' => 'Payment method deleted.']);
    }

    public function toggleActive(PaymentMethod $paymentMethod): JsonResponse
    {
        $paymentMethod->update(['is_active' => !$paymentMethod->is_active]);
        return response()->json([
            'is_active' => $paymentMethod->is_active,
            'message'   => 'Payment method ' . ($paymentMethod->is_active ? 'activated' : 'deactivated') . '.',
        ]);
    }
}
