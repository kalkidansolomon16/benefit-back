<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gym extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id', 'parent_gym_id', 'name', 'branch_name', 'contact_person', 'contact_phone', 'contact_email',
        'address', 'sub_city', 'city', 'latitude', 'longitude',
        'tier', 'max_capacity', 'current_members',
        'monthly_fee_etb', 'quarterly_fee_etb', 'annual_fee_etb',
        'facilities', 'opening_hours', 'logo_path', 'photo_paths',
        'is_active', 'is_partner', 'partnership_start', 'partnership_end',
    ];

    protected $casts = [
        'facilities'       => 'array',
        'opening_hours'    => 'array',
        'photo_paths'      => 'array',
        'is_active'        => 'boolean',
        'is_partner'       => 'boolean',
        'partnership_start'=> 'date',
        'partnership_end'  => 'date',
    ];

    // Virtual attribute (computed in PHP since SQLite/MySQL both handle it)
    public function getAvailableSlotsAttribute(): int
    {
        return $this->max_capacity - $this->current_members;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(Checkin::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(Membership::class)->where('status', 'active');
    }

    public function upgradeRequests(): HasMany
    {
        return $this->hasMany(\App\Models\GymUpgradeRequest::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Gym::class, 'parent_gym_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Gym::class, 'parent_gym_id');
    }

    /** Gyms (main + branches) owned by a partner account */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where(fn($q) => $q
            ->where('owner_user_id', $user->id)
            ->orWhere('contact_email', $user->email)
        );
    }

    /**
     * Gyms a partner-side user is allowed to SEE.
     *  - main owner: every gym they own (main + all branches)
     *  - branch login: its own branch plus the other branches of the same gym
     *  - staff (gym_hr etc.): only their own branch
     */
    public function scopeViewableBy(Builder $query, User $user): Builder
    {
        if ($user->gym_id) {
            if ($user->role !== 'gym_partner') {
                return $query->whereKey($user->gym_id);
            }

            $own  = static::find($user->gym_id);
            $root = $own ? ($own->parent_gym_id ?? $own->id) : (int) $user->gym_id;

            return $query->where(fn($q) => $q->where('id', $root)->orWhere('parent_gym_id', $root));
        }

        return $query->ownedBy($user);
    }

    /**
     * Whether the user may CHANGE data of this gym.
     * A branch login can only change its own branch; the main owner can change all.
     */
    public static function canWrite(User $user, Gym $gym): bool
    {
        if ($user->gym_id) {
            return (int) $gym->id === (int) $user->gym_id;
        }

        return (int) $gym->owner_user_id === (int) $user->id || $gym->contact_email === $user->email;
    }

    /** True for a gym_partner account that belongs to one branch (not the main owner) */
    public static function isBranchLogin(User $user): bool
    {
        return $user->role === 'gym_partner' && !empty($user->gym_id);
    }

    /**
     * The gym a partner-side user is currently working on.
     * Owners and branch logins may pick any gym they can view via the
     * X-Gym-Id header (branch switcher); with no header they get their own
     * branch (branch login) or the main gym (owner).
     */
    public static function forPartner(User $user, $requestedId = null, ?Builder $query = null): ?Gym
    {
        $query ??= static::query();

        // Staff accounts are pinned to one branch
        if ($user->gym_id && $user->role !== 'gym_partner') {
            return $query->find($user->gym_id);
        }

        $base = (clone $query)->viewableBy($user);

        $requestedId ??= request()->header('X-Gym-Id');
        if ($requestedId) {
            $gym = (clone $base)->whereKey((int) $requestedId)->first();
            if ($gym) return $gym;
        }

        if ($user->gym_id) {
            return (clone $base)->whereKey($user->gym_id)->first();
        }

        return $base->orderByRaw('parent_gym_id IS NOT NULL')->orderBy('id')->first();
    }

    /**
     * How busy each gym is right now, so members can choose a quiet one.
     * Counts only (never names):
     *  - inside_now    checked in today, not checked out, within the last 3 hours
     *  - today_checkins everyone who has checked in today
     *  - selected_today members who picked this gym for today
     *  - percent / level   inside_now compared with the gym's capacity
     *
     * @param  iterable<Gym>  $gyms
     * @return array<int, array<string, mixed>>  keyed by gym id
     */
    public static function crowdFor(iterable $gyms): array
    {
        $gyms = collect($gyms);
        $ids  = $gyms->pluck('id')->all();
        if (!$ids) return [];

        $today = now()->toDateString();

        $inside = Checkin::whereIn('gym_id', $ids)
            ->whereDate('checked_in_at', $today)
            ->whereNull('checked_out_at')
            ->where('checked_in_at', '>=', now()->subHours(3))
            ->selectRaw('gym_id, count(*) as c')->groupBy('gym_id')->pluck('c', 'gym_id');

        $visited = Checkin::whereIn('gym_id', $ids)
            ->whereDate('checked_in_at', $today)
            ->selectRaw('gym_id, count(*) as c')->groupBy('gym_id')->pluck('c', 'gym_id');

        $selected = DailyGymSelection::whereIn('gym_id', $ids)
            ->where('selection_date', $today)
            ->selectRaw('gym_id, count(*) as c')->groupBy('gym_id')->pluck('c', 'gym_id');

        $out = [];
        foreach ($gyms as $g) {
            $capacity = max(1, (int) $g->max_capacity);
            $in       = (int) ($inside[$g->id] ?? 0);
            $percent  = (int) round($in / $capacity * 100);
            [$level, $label] = match (true) {
                $percent >= 100 => ['full', 'Full'],
                $percent >= 70  => ['busy', 'Busy'],
                $percent >= 40  => ['moderate', 'Moderate'],
                default         => ['quiet', 'Quiet'],
            };

            $out[$g->id] = [
                'inside_now'     => $in,
                'today_checkins' => (int) ($visited[$g->id] ?? 0),
                'selected_today' => (int) ($selected[$g->id] ?? 0),
                'capacity'       => $capacity,
                'percent'        => min($percent, 100),
                'level'          => $level,
                'level_label'    => $label,
            ];
        }

        return $out;
    }

    public function hasCapacity(): bool
    {
        return $this->available_slots > 0;
    }
}
