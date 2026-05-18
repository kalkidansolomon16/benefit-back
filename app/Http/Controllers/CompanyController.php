<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->tier, fn($q) => $q->where('tier', $request->tier))
            ->when($request->is_active !== null, fn($q) => $q->where('is_active', $request->boolean('is_active')))
            ->withCount('employees')
            ->latest()
            ->paginate(15);

        return CompanyResource::collection($companies);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());
        return response()->json(new CompanyResource($company), 201);
    }

    public function show(Company $company): CompanyResource
    {
        $company->load(['employees.user', 'activeSubscription.plan', 'invoices' => fn($q) => $q->latest()->limit(5)]);
        return new CompanyResource($company);
    }

    public function update(StoreCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());
        return new CompanyResource($company);
    }

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(['message' => 'Company deleted.']);
    }

    public function toggleActive(Company $company): JsonResponse
    {
        $company->update(['is_active' => !$company->is_active]);
        return response()->json(['is_active' => $company->is_active]);
    }
}
