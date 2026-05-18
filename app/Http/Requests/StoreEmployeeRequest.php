<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'user_id'    => 'required|exists:users,id',
            'company_id' => 'required|exists:companies,id',
            'fan_number' => 'required|string|unique:employees,fan_number',
            'job_title'  => 'nullable|string|max:255',
            'level'      => 'required|in:chief,director,manager,staff',
            'department' => 'nullable|string|max:255',
        ];
    }
}
