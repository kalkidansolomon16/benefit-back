<?php

namespace App\Http\Controllers;

use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\PartnerApplication;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PartnerApplicationController extends Controller
{
    /* ── List all applications ─────────────────────────────── */

    public function index(Request $request): JsonResponse
    {
        $applications = PartnerApplication::with(['user:id,name,email,is_active', 'parentGym:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get()
            ->map(fn($a) => [
                'id'                     => $a->id,
                'facility_name'          => $a->facility_name,
                'categories'             => $a->categories,
                'contact_person'         => $a->contact_person,
                'contact_phone'          => $a->contact_phone,
                'contact_email'          => $a->contact_email,
                'tin_number'             => $a->tin_number,
                'business_license_url'   => $a->business_license_path
                    ? url('/api/v1/files/' . ltrim($a->business_license_path, '/'))
                    : null,
                'city'                   => $a->city,
                'sub_city'               => $a->sub_city,
                'woreda'                 => $a->woreda,
                'landmark'               => $a->landmark,
                'google_maps_link'       => $a->google_maps_link,
                'operating_hours_summary'=> $a->operating_hours_summary,
                'weekday_open'           => $a->weekday_open,
                'weekday_close'          => $a->weekday_close,
                'weekend_open'           => $a->weekend_open,
                'weekend_close'          => $a->weekend_close,
                'max_capacity'           => $a->max_capacity,
                'amenities'              => $a->amenities ?? [],
                'branch_name'            => $a->branch_name,
                'branches'               => $a->branches ?? [],
                'is_branch_request'      => (bool) $a->parent_gym_id,
                'parent_gym_name'        => $a->parentGym?->name,
                'status'                 => $a->status,
                'rejection_reason'       => $a->rejection_reason,
                'submitted_at'           => $a->created_at->toDateString(),
                'user'                   => $a->user ? [
                    'id'        => $a->user->id,
                    'email'     => $a->user->email,
                    'is_active' => $a->user->is_active,
                ] : null,
            ]);

        $pendingCount = PartnerApplication::where('status', 'pending')->count();

        return response()->json([
            'data'          => $applications,
            'total'         => $applications->count(),
            'pending_count' => $pendingCount,
        ]);
    }

    /* ── Approve → create Gym ──────────────────────────────── */

    public function approve(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== 'pending') {
            return response()->json(['message' => 'Application has already been processed.'], 422);
        }

        $branches = $partnerApplication->branches ?? [];

        $rules = [
            'tier' => 'required|in:basic,basic_plus,premium,platinum',
        ];
        if (count($branches) > 0) {
            $rules['branch_tiers']   = 'required|array|size:' . count($branches);
            $rules['branch_tiers.*'] = 'required|in:basic,basic_plus,premium,platinum';
        }
        $request->validate($rules);

        $isBranchRequest = (bool) $partnerApplication->parent_gym_id;
        if ($isBranchRequest && !$partnerApplication->parentGym) {
            return response()->json(['message' => 'The main gym for this branch no longer exists.'], 422);
        }

        $created = [];

        DB::transaction(function () use ($request, $partnerApplication, $branches, $isBranchRequest, &$created) {
            $app   = $partnerApplication;
            $owner = $app->user;

            // Main location (or the single new branch, for a portal branch request)
            $main = $this->createGym($app, [
                'branch_name'   => $app->branch_name,
                'city'          => $app->city,
                'sub_city'      => $app->sub_city,
                'woreda'        => $app->woreda,
                'landmark'      => $app->landmark,
                'contact_phone' => $app->contact_phone,
                'weekday_open'  => $app->weekday_open,
                'weekday_close' => $app->weekday_close,
                'weekend_open'  => $app->weekend_open,
                'weekend_close' => $app->weekend_close,
                'max_capacity'  => $app->max_capacity,
            ], $request->tier, $owner?->id, $isBranchRequest ? $app->parent_gym_id : null,
               $isBranchRequest ? $app->branchUser : null);
            $created[] = $main;
            if ($isBranchRequest) {
                $this->linkBranchLogin($app->branchUser, $main);
            } else {
                $this->makeGymAdmin($owner, $main);
            }

            // Extra branches submitted at registration — each has its own login
            foreach ($branches as $i => $branch) {
                $login = !empty($branch['user_id']) ? User::find($branch['user_id']) : null;
                $gym   = $this->createGym($app, $branch, $request->input("branch_tiers.$i"), $owner?->id, $main->id, $login);
                $this->linkBranchLogin($login, $gym);
                $created[] = $gym;
            }

            $app->update(['status' => 'approved']);
            if (!$isBranchRequest) {
                $owner?->update(['is_active' => true]);
            }
        });

        // Send notifications AFTER transaction so DB changes are committed
        $partnerUser = $partnerApplication->user()->first();
        if ($partnerUser) {
            if ($isBranchRequest) {
                try {
                    \Illuminate\Support\Facades\Mail::to($partnerUser->email)->send(
                        new \App\Mail\FitAccessNotificationMail(
                            recipientName: $partnerUser->name,
                            emailSubject:  'Your New FitAccess Branch Is Live',
                            heading:       'Branch Approved',
                            message:       "Your new branch \"{$created[0]->name}\" has been approved and is now visible to FitAccess members.\n\nYou can switch to it from the branch selector in your partner portal.",
                            buttonText:    'Open Partner Portal',
                            color:         '#10b981',
                        )
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Branch approval email failed: ' . $e->getMessage());
                }
            } else {
                try {
                    \Illuminate\Support\Facades\Mail::to($partnerUser->email)
                        ->send(new \App\Mail\AccountApprovedMail($partnerUser));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Partner approval email failed: ' . $e->getMessage());
                }
                try {
                    app(TelegramService::class)->notifyUserApproved($partnerUser, 'partner');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Partner approval Telegram failed: ' . $e->getMessage());
                }
            }
        }

        $message = count($created) > 1
            ? 'Partner approved. ' . count($created) . " branches of \"{$partnerApplication->facility_name}\" are now live."
            : "Approved. Gym \"{$created[0]->name}\" is now live.";

        return response()->json([
            'message' => $message,
            'gym_id'  => $created[0]->id,
            'gym_ids' => array_map(fn($g) => $g->id, $created),
        ]);
    }

    /** Create one gym (main location or branch) from an application */
    private function createGym(PartnerApplication $app, array $loc, string $tier, ?int $ownerId, ?int $parentGymId, ?User $login = null): Gym
    {
        $address = $loc['woreda'];
        if (!empty($loc['landmark'])) {
            $address .= ', ' . $loc['landmark'];
        }

        $openingHours = [
            'weekdays' => !empty($loc['weekday_open']) && !empty($loc['weekday_close'])
                ? $loc['weekday_open'] . '–' . $loc['weekday_close']
                : null,
            'weekends' => !empty($loc['weekend_open']) && !empty($loc['weekend_close'])
                ? $loc['weekend_open'] . '–' . $loc['weekend_close']
                : 'Closed',
            'summary'  => $parentGymId ? null : $app->operating_hours_summary,
        ];

        $branchName = $loc['branch_name'] ?? null;

        return Gym::create([
            'owner_user_id'     => $ownerId,
            'parent_gym_id'     => $parentGymId,
            'name'              => $branchName ? "{$app->facility_name} – {$branchName}" : $app->facility_name,
            'branch_name'       => $branchName,
            'contact_person'    => $login?->name ?? $app->contact_person,
            'contact_phone'     => ($loc['contact_phone'] ?? null) ?: $app->contact_phone,
            // A branch is reached through its own login email
            'contact_email'     => $login?->email ?? $app->contact_email,
            'address'           => $address,
            'sub_city'          => $loc['sub_city'] ?? null,
            'city'              => $loc['city'],
            'tier'              => self::canonicalTier($tier),
            'max_capacity'      => (int) $loc['max_capacity'],
            'facilities'        => array_merge($app->categories ?? [], $app->amenities ?? []),
            'opening_hours'     => $openingHours,
            'is_active'         => true,
            'is_partner'        => true,
            'partnership_start' => now()->toDateString(),
        ]);
    }

    /** Pin a branch login to its gym, let it sign in, and make it that gym's admin */
    private function linkBranchLogin(?User $login, Gym $gym): void
    {
        $login?->forceFill(['gym_id' => $gym->id, 'is_active' => true])->save();
        $this->makeGymAdmin($login, $gym);
    }

    /**
     * The mobile app reads the gym_staff table to know which gym a login belongs to
     * and what it may do, so every gym's owner / branch login gets an "admin" row.
     * They chose their own password at registration, so no forced change.
     */
    private function makeGymAdmin(?User $user, Gym $gym): void
    {
        if (!$user) return;

        GymStaff::updateOrCreate(
            ['user_id' => $user->id, 'gym_id' => $gym->id],
            ['role' => 'admin', 'is_active' => true, 'must_change_password' => false]
        );
    }

    /**
     * Normalise to canonical tier so gym access-control keeps working.
     * Strips common plan prefixes: 'fit_basic_plus' → 'basic_plus', 'fit_premium' → 'premium'.
     */
    private static function canonicalTier(string $tier): string
    {
        $raw  = strtolower($tier);
        $norm = preg_replace('/^[a-z]+_(?=basic|premium|platinum|gold|silver)/i', '', $raw) ?? $raw;

        return match (true) {
            str_contains($norm, 'platinum') || str_contains($norm, 'gold')   => 'platinum',
            str_contains($norm, 'premium')                                   => 'premium',
            str_contains($norm, 'basic_plus') || str_contains($norm, 'plus') => 'basic_plus',
            default                                                          => 'basic',
        };
    }

    /* ── Partner portal: my branches + request a new one ───── */

    public function myBranches(): JsonResponse
    {
        $user = auth()->user();

        // Main owner: all branches (read/write). Branch login: all branches, read-only except its own.
        // Staff: only the branch they work at.
        $gyms = Gym::viewableBy($user)->orderByRaw('parent_gym_id IS NOT NULL')->orderBy('id')->get();

        $isOwner  = $user->role === 'gym_partner' && !$user->gym_id;
        $requests = !$isOwner ? collect() : PartnerApplication::where('user_id', $user->id)
            ->whereNotNull('parent_gym_id')
            ->whereIn('status', ['pending', 'rejected'])
            ->latest()
            ->get();

        return response()->json([
            'branches' => $gyms->map(fn($g) => [
                'id'           => $g->id,
                'name'         => $g->name,
                'branch_name'  => $g->branch_name,
                'is_main'      => $g->parent_gym_id === null,
                'can_write'    => Gym::canWrite($user, $g),
                'login_email'  => $isOwner ? $g->contact_email : null,
                'address'      => $g->address,
                'sub_city'     => $g->sub_city,
                'city'         => $g->city,
                'tier'         => $g->tier,
                'max_capacity' => $g->max_capacity,
                'is_active'    => $g->is_active,
            ])->values(),
            'requests' => $requests->map(fn($a) => [
                'id'               => $a->id,
                'branch_name'      => $a->branch_name,
                'city'             => $a->city,
                'sub_city'         => $a->sub_city,
                'woreda'           => $a->woreda,
                'status'           => $a->status,
                'rejection_reason' => $a->rejection_reason,
                'submitted_at'     => $a->created_at->toDateString(),
            ])->values(),
            'can_request' => $isOwner,
        ]);
    }

    /** Main gym owner issues a new temporary password for a branch login */
    public function resetBranchPassword(Gym $gym): JsonResponse
    {
        $user = auth()->user();
        if ($user->role !== 'gym_partner' || $user->gym_id || !Gym::canWrite($user, $gym) || $gym->parent_gym_id === null) {
            abort(403, 'Only the main gym owner can reset a branch password.');
        }

        $login = User::where('gym_id', $gym->id)->where('role', 'gym_partner')->first();
        if (!$login) {
            return response()->json(['message' => 'This branch has no login.'], 404);
        }

        $temp = \Illuminate\Support\Str::password(14);
        $login->update(['password' => \Illuminate\Support\Facades\Hash::make($temp), 'must_reset_password' => true]);
        $login->tokens()->delete();

        return response()->json([
            'message'       => 'Temporary password issued. Share it securely — it is shown only once.',
            'temp_password' => $temp,
            'email'         => $login->email,
        ]);
    }

    public function requestBranch(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user->role !== 'gym_partner' || $user->gym_id) {
            abort(403, 'Only the gym owner can add new branches.');
        }

        $main = Gym::ownedBy($user)->whereNull('parent_gym_id')->orderBy('id')->first();
        if (!$main) {
            abort(403, 'No gym is linked to this partner account.');
        }

        $branch = \App\Services\BranchLoginService::validate(
            [$request->all()],
            \App\Services\BranchLoginService::GYM,
            [$user->email]
        )[0];

        // Business details come from the owner's original application (or the gym itself)
        $original = PartnerApplication::where('user_id', $user->id)
            ->whereNull('parent_gym_id')
            ->where('status', 'approved')
            ->latest()
            ->first();

        // The branch's own login (inactive until the admin approves the branch)
        $login = \App\Services\BranchLoginService::createLogin($branch, 'gym_partner');

        $app = PartnerApplication::create([
            'user_id'               => $user->id,
            'parent_gym_id'         => $main->id,
            'branch_user_id'        => $login->id,
            'facility_name'         => $original?->facility_name ?? $main->name,
            'branch_name'           => $branch['branch_name'],
            'categories'            => $original?->categories ?? ($main->facilities ?? []),
            'contact_person'        => $original?->contact_person ?? ($main->contact_person ?? $user->name),
            'contact_phone'         => $branch['contact_phone'] ?: ($original?->contact_phone ?? $main->contact_phone ?? ''),
            'contact_email'         => $original?->contact_email ?? $user->email,
            'tin_number'            => $original?->tin_number ?? '',
            'business_license_path' => $original?->business_license_path,
            'city'                  => $branch['city'],
            'sub_city'              => $branch['sub_city'],
            'woreda'                => $branch['woreda'],
            'landmark'              => $branch['landmark'],
            'google_maps_link'      => $branch['google_maps_link'],
            'weekday_open'          => $branch['weekday_open'],
            'weekday_close'         => $branch['weekday_close'],
            'weekend_open'          => $branch['weekend_open'],
            'weekend_close'         => $branch['weekend_close'],
            'max_capacity'          => $branch['max_capacity'],
            'amenities'             => $original?->amenities ?? [],
            'status'                => 'pending',
        ]);

        try {
            app(TelegramService::class)->notifyAdminsNewPartner($app);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Branch request Telegram notification failed: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Branch submitted! Our team will review it and notify you once it is live.',
        ], 201);
    }

    /* ── Reject ────────────────────────────────────────────── */

    public function reject(Request $request, PartnerApplication $partnerApplication): JsonResponse
    {
        if ($partnerApplication->status !== 'pending') {
            return response()->json(['message' => 'Application has already been processed.'], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $partnerApplication->update([
            'status'           => 'rejected',
            'rejection_reason' => $request->reason,
        ]);

        // Branch logins that never went live are removed so their emails can be reused
        $unusedLogins = collect($partnerApplication->branches ?? [])->pluck('user_id')
            ->push($partnerApplication->branch_user_id)->filter()->all();
        if ($unusedLogins) {
            User::whereIn('id', $unusedLogins)->whereNull('gym_id')->forceDelete();
        }

        // A rejected branch request must not lock the owner out of their existing gym
        if ($partnerApplication->parent_gym_id) {
            if ($partnerApplication->user) {
                try {
                    \Illuminate\Support\Facades\Mail::to($partnerApplication->user->email)->send(
                        new \App\Mail\FitAccessNotificationMail(
                            recipientName: $partnerApplication->user->name,
                            emailSubject:  'Your FitAccess Branch Request Was Not Approved',
                            heading:       'Branch Not Approved',
                            message:       "Your request to add the branch \"{$partnerApplication->branch_name}\" was not approved."
                                          . ($request->reason ? "\n\nReason: {$request->reason}" : '')
                                          . "\n\nIf you have questions, please contact FitAccess support.",
                            buttonText:    'Contact Support',
                            color:         '#ef4444',
                        )
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Branch rejection email failed: ' . $e->getMessage());
                }
            }

            return response()->json(['message' => 'Branch request rejected.']);
        }

        $partnerApplication->user?->update(['is_active' => false]);

        try {
            if ($partnerApplication->user) {
                $reason = $request->reason;
                app(TelegramService::class)->notifyUserRejected($partnerApplication->user, 'partner', $reason);
                \Illuminate\Support\Facades\Mail::to($partnerApplication->user->email)->send(
                    new \App\Mail\FitAccessNotificationMail(
                        recipientName: $partnerApplication->user->name,
                        emailSubject:  'Your FitAccess Gym Partner Application Was Not Approved',
                        heading:       'Application Not Approved',
                        message:       "We regret to inform you that your gym partner application for {$partnerApplication->facility_name} was not approved."
                                      . ($reason ? "\n\nReason: {$reason}" : '')
                                      . "\n\nIf you have questions, please contact FitAccess support.",
                        buttonText:    'Contact Support',
                        color:         '#ef4444',
                    )
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Partner rejection notification failed: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Application rejected.']);
    }
}
