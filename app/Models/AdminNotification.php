<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type', 'title', 'message', 'data', 'read_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /** Create a Pay Now invoice-request notification */
    public static function invoiceRequest(string $companyName, string $employeeName, int $employeeId, int $companyId): self
    {
        return static::create([
            'type'    => 'invoice_request',
            'title'   => 'Invoice Requested — Pay Now',
            'message' => "{$companyName} approved employee {$employeeName} and selected Pay Now. Please generate an invoice for this company.",
            'data'    => [
                'employee_id'   => $employeeId,
                'company_id'    => $companyId,
                'company_name'  => $companyName,
                'employee_name' => $employeeName,
            ],
        ]);
    }
}
