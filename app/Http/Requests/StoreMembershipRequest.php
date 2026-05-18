<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMembershipRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'employee_id' => 'required|exists:employees,id',
            'gym_id'      => 'required|exists:gyms,id',
            'plan_id'     => 'required|exists:membership_plans,id',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after:start_date',
        ];
    }
}
