<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'company_id'     => 'required|exists:companies,id',
            'plan_id'        => 'required|exists:membership_plans,id',
            'employee_count' => 'required|integer|min:1',
            'billing_cycle'  => 'required|in:monthly,quarterly,annual',
            'billing_date'   => 'required|date',
            'period_start'   => 'required|date',
            'period_end'     => 'required|date|after:period_start',
        ];
    }
}
