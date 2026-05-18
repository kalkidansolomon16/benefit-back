<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreCheckinRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'fan_number'  => 'required|string|exists:employees,fan_number',
            'gym_id'      => 'required|exists:gyms,id',
            'method'      => 'nullable|in:fan_number,card,facial',
            'recorded_by' => 'nullable|string|max:255',
        ];
    }
}
