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

    /** Create a gym tier-upgrade-request notification */
    public static function gymUpgradeRequest(string $gymName, string $currentTier, string $requestedTier, int $gymId, int $requestId): self
    {
        return static::create([
            'type'    => 'gym_upgrade_request',
            'title'   => 'Tier Upgrade Request — ' . $gymName,
            'message' => "{$gymName} (currently {$currentTier}) has requested an upgrade to {$requestedTier}.",
            'data'    => [
                'gym_id'         => $gymId,
                'request_id'     => $requestId,
                'current_tier'   => $currentTier,
                'requested_tier' => $requestedTier,
                'gym_name'       => $gymName,
            ],
        ]);
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
