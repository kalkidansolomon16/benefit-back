<?php

namespace App\Console\Commands;

use App\Models\Gym;
use App\Models\GymStaff;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Gym owners and branch logins that were approved before the mobile app started
 * reading the gym_staff table have no row there. This creates the missing
 * "admin" rows. Safe to run more than once; it never touches existing rows.
 */
class BackfillGymStaff extends Command
{
    protected $signature   = 'gym-staff:backfill {--dry-run : Only list what would be created}';
    protected $description = 'Create gym_staff admin rows for existing gym owners and branch logins';

    public function handle(): int
    {
        $dry     = (bool) $this->option('dry-run');
        $created = 0;

        User::where('role', 'gym_partner')->orderBy('id')->each(function (User $user) use ($dry, &$created) {
            if (GymStaff::where('user_id', $user->id)->exists()) {
                return;
            }

            // Branch login → its own branch; owner → the main gym
            $gym = $user->gym_id
                ? Gym::find($user->gym_id)
                : Gym::ownedBy($user)->whereNull('parent_gym_id')->orderBy('id')->first();

            if (!$gym) {
                $this->warn("Skipped {$user->email}: no gym found for this account.");
                return;
            }

            $this->line(($dry ? '[dry-run] ' : '') . "{$user->email} → {$gym->name} (admin)");

            if (!$dry) {
                GymStaff::create([
                    'user_id'              => $user->id,
                    'gym_id'               => $gym->id,
                    'role'                 => 'admin',
                    'is_active'            => true,
                    'must_change_password' => false,
                ]);
            }
            $created++;
        });

        $this->info(($dry ? 'Would create ' : 'Created ') . $created . ' gym_staff row(s).');

        return self::SUCCESS;
    }
}
