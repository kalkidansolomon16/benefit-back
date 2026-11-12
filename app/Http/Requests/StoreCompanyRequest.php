<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'name'                     => 'required|string|max:255',
            'industry'                 => 'required|in:banking,ngo,government,international_school,hospital,real_estate,telecom,airline,insurance,tech,embassy,other',
            'contact_email'            => 'required|email|unique:companies,contact_email,' . $companyId,
            'contact_person'           => 'nullable|string|max:255',
            'contact_phone'            => 'nullable|string|max:20',
            'address'                  => 'nullable|string',
            'city'                     => 'nullable|string|max:100',
            'tier'                     => 'nullable|in:platinum,basic_plus,basic',
            'tin_number'               => 'nullable|string|max:50',
            'contract_start'           => 'nullable|date',
            'contract_end'             => 'nullable|date|after:contract_start',
            // Business licence: required on create, optional on update
            'business_license'         => ($this->isMethod('POST') ? 'required' : 'nullable')
                                          . '|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'business_license_status'  => 'nullable|in:pending,approved,rejected,expired',
        ];
    }

    public function messages(): array
    {
        return [
            'business_license.required' => 'A business licence document (PDF or image) is required.',
            'business_license.mimes'    => 'The business licence must be a PDF, JPG, or PNG file.',
            'business_license.max'      => 'The business licence file must not exceed 5 MB.',
        ];
    }
}
