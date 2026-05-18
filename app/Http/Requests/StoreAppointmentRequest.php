<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'employee_id'    => 'required|exists:employees,id',
            'doctor_name'    => 'nullable|string|max:255',
            'clinic_name'    => 'nullable|string|max:255',
            'specialty'      => 'nullable|string|max:100',
            'appointment_at' => 'required|date|after:now',
            'is_online'      => 'boolean',
            'meeting_link'   => 'nullable|url',
            'notes'          => 'nullable|string',
        ];
    }
}
