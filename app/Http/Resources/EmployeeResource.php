<?php
namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'fan_number'  => $this->fan_number,
            'job_title'   => $this->job_title,
            'level'       => $this->level,
            'department'  => $this->department,
            'is_enrolled'    => $this->is_enrolled,
            'enrolled_at'    => $this->enrolled_at,
            'payment_status' => $this->payment_status ?? 'unpaid',
            'is_enrolled'            => $this->is_enrolled,
            'enrolled_at'            => $this->enrolled_at,
            'payment_status'         => $this->payment_status ?? 'unpaid',
            'paid_period'            => $this->paid_period,
            'registration_status'    => $this->registration_status ?? 'approved',
            'company_branch_id'      => $this->company_branch_id,
            'admin_approval_status'  => $this->admin_approval_status,
            'payment_preference'     => $this->payment_preference,
            'is_banned'      => $this->is_banned,
            'banned_until'   => $this->banned_until?->toIso8601String(),
            'ban_reason'     => $this->ban_reason,
            'ban_indefinite' => $this->isBanIndefinite(),
            // FitAccess card: one number for life; issued to the company, then activated by the employee
            'card_number'    => $this->card_number ? \App\Support\CardNumber::format($this->card_number) : null,
            'card_issued'    => $this->isCardIssued(),
            'card_issued_at' => $this->card_issued_at?->toIso8601String(),
            'card_activated' => $this->hasActivatedCard(),
            // User account active state (separate from enrollment)
            'user_is_active' => $this->whenLoaded('user', fn() => (bool) $this->user?->is_active, false),
            'gym_tier'    => $this->getGymTier(),
            'user'        => new UserResource($this->whenLoaded('user')),
            'company'     => new CompanyResource($this->whenLoaded('company')),
            'active_membership' => new MembershipResource($this->whenLoaded('activeMembership')),
            'created_at'  => $this->created_at,
        ];
    }
}
