<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::with(['company', 'subscription'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->company_id, fn($q) => $q->where('company_id', $request->company_id))
            ->latest('issue_date')
            ->paginate(15);

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        $invoice->load(['company', 'subscription.plan']);
        return new InvoiceResource($invoice);
    }

    public function generateForSubscription(Subscription $subscription): JsonResponse
    {
        $invoice = Invoice::create([
            'subscription_id'      => $subscription->id,
            'company_id'           => $subscription->company_id,
            'subtotal_etb'         => $subscription->total_amount_etb,
            'service_fee_etb'      => $subscription->service_fee_etb,
            'absenteeism_fee_etb'  => $subscription->absenteeism_fee_etb,
            'tax_etb'              => 0,
            'total_etb'            => $subscription->total_amount_etb + $subscription->service_fee_etb + $subscription->absenteeism_fee_etb,
            'issue_date'           => now(),
            'due_date'             => now()->addDays(30),
            'status'               => 'sent',
        ]);

        return response()->json(new InvoiceResource($invoice->load('company')), 201);
    }

    public function markPaid(Request $request, Invoice $invoice): JsonResponse
    {
        $request->validate(['payment_reference' => 'required|string']);
        $invoice->update([
            'status'            => 'paid',
            'paid_at'           => now(),
            'payment_reference' => $request->payment_reference,
        ]);
        return response()->json(new InvoiceResource($invoice));
    }
}
