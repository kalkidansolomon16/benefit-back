<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\MembershipService;
use Illuminate\Console\Command;

class SyncMemberships extends Command
{
    protected $signature   = 'memberships:sync {--force : Also sync employees whose payment_status is not yet paid}';
    protected $description = 'Create missing membership rows for all paid+enrolled employees. Use --force to include unpaid employees.';

    public function handle(): int
    {
        $query = Employee::where('is_enrolled', true)
            ->where('registration_status', 'approved')
            ->whereNotNull('level');

        // By default only sync employees whose company invoice has been settled.
        // Use --force to repair memberships for unpaid employees too (admin use only).
        if (!$this->option('force')) {
            $query->where('payment_status', 'paid');
        }

        $employees = $query->get();

        $this->info("Found {$employees->count()} eligible employee(s) to sync.");

        $created = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $tier = MembershipService::tierFromLevel($employee->level);
            $newCount = MembershipService::provisionForEmployee($employee, $tier);

            if ($newCount > 0) {
                $created += $newCount;
                $this->line("  + {$employee->fan_number} → {$newCount} membership(s) created");
            } else {
                $skipped++;
            }
        }

        $this->newLine();
        $this->info("Done. Created: {$created}  |  Already existed / skipped: {$skipped}");

        return self::SUCCESS;
    }
}
