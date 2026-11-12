<?php

namespace App\Services;

use App\Models\MemberVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Identity verification (v2): a member submits a selfie; it stays
 * "pending" until gym staff compare it with a national ID at the first
 * visit (or an admin reviews it). A newer verified photo replaces the
 * old one only once it is approved, so re-verifying never locks a member
 * out.
 */
class VerificationService
{
    public const DISK = 'local';

    /** not_started | pending | verified | rejected */
    public function statusFor(User $user): string
    {
        $latest = $this->latest($user);
        if (!$latest) {
            return 'not_started';
        }
        // A pending re-verification doesn't undo an earlier approval.
        if ($latest->status === MemberVerification::PENDING && $this->approved($user)) {
            return 'verified';
        }

        return $latest->status;
    }

    public function latest(User $user): ?MemberVerification
    {
        return MemberVerification::where('user_id', $user->id)->latest('id')->first();
    }

    /** The photo staff should compare with the ID: the newest approved one, else the newest. */
    public function photoFor(User $user): ?MemberVerification
    {
        return $this->approved($user) ?? $this->latest($user);
    }

    public function approved(User $user): ?MemberVerification
    {
        return MemberVerification::where('user_id', $user->id)
            ->where('status', MemberVerification::VERIFIED)
            ->latest('id')
            ->first();
    }

    public function submit(User $user, UploadedFile $photo): MemberVerification
    {
        $path = $photo->store('verifications/'.$user->id, self::DISK);

        return DB::transaction(function () use ($user, $path) {
            // Only one open request at a time: an older pending photo is replaced.
            MemberVerification::where('user_id', $user->id)
                ->where('status', MemberVerification::PENDING)
                ->get()
                ->each(function (MemberVerification $v) {
                    Storage::disk(self::DISK)->delete($v->photo_path);
                    $v->delete();
                });

            return MemberVerification::create(['user_id' => $user->id, 'photo_path' => $path, 'status' => MemberVerification::PENDING]);
        });
    }

    public function approve(MemberVerification $v, User $reviewer, ?int $gymId = null): MemberVerification
    {
        $v->forceFill([
            'status' => MemberVerification::VERIFIED,
            'rejection_reason' => null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at_gym_id' => $gymId,
            'reviewed_at' => now(),
        ])->save();

        return $v;
    }

    public function reject(MemberVerification $v, User $reviewer, string $reason, ?int $gymId = null): MemberVerification
    {
        $v->forceFill([
            'status' => MemberVerification::REJECTED,
            'rejection_reason' => $reason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at_gym_id' => $gymId,
            'reviewed_at' => now(),
        ])->save();

        return $v;
    }
}
