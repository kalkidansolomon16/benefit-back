<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreGymRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'address'         => 'required|string',
            'tier'            => 'required|in:premium,basic_plus,basic',
            'max_capacity'    => 'nullable|integer|min:1',
            'monthly_fee_etb' => 'required|numeric|min:0',
            'contact_phone'   => 'nullable|string|max:20',
            'contact_email'   => 'nullable|email',
            'sub_city'        => 'nullable|string|max:100',
            'latitude'        => 'nullable|numeric',
            'longitude'       => 'nullable|numeric',
            'facilities'      => 'nullable|array',
        ];
    }
}
