<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:255',
            'industry'       => 'required|in:banking,ngo,government,international_school,hospital,real_estate,telecom,airline,insurance,tech,embassy,other',
            'contact_email'  => 'required|email|unique:companies,contact_email',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone'  => 'nullable|string|max:20',
            'address'        => 'nullable|string',
            'city'           => 'nullable|string|max:100',
            'tier'           => 'nullable|in:platinum,basic_plus,basic',
            'tin_number'     => 'nullable|string|max:50',
            'contract_start' => 'nullable|date',
            'contract_end'   => 'nullable|date|after:contract_start',
        ];
    }
}
