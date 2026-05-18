<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'invoice_number'      => $this->invoice_number,
            'subtotal_etb'        => $this->subtotal_etb,
            'service_fee_etb'     => $this->service_fee_etb,
            'absenteeism_fee_etb' => $this->absenteeism_fee_etb,
            'tax_etb'             => $this->tax_etb,
            'total_etb'           => $this->total_etb,
            'issue_date'          => $this->issue_date,
            'due_date'            => $this->due_date,
            'paid_at'             => $this->paid_at,
            'status'              => $this->status,
            'payment_reference'   => $this->payment_reference,
            'company'             => new CompanyResource($this->whenLoaded('company')),
            'created_at'          => $this->created_at,
        ];
    }
}
