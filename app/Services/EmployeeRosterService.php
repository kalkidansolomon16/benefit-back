<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Employee;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The company's employee list (Excel), imported every billing period.
 *
 * analyze() decides, row by row, without saving anything:
 *   create    – a new person for this company
 *   update    – already registered here (imported before, or signed up on the
 *               portal themselves): only changed or missing details are filled in
 *   unchanged – already registered, nothing to change
 *   error     – cannot be imported (reason given)
 *
 * An employee is recognised by email, phone or FAN number. Email and phone
 * must be unique across FitAccess; a person registered under another company
 * is never moved or duplicated.
 *
 * apply() saves the create/update rows and invites new employees to create
 * their account.
 */
class EmployeeRosterService
{
    private const ALIASES = [
        'package' => 'plan', 'tier' => 'plan', 'plan_name' => 'plan',
        'joined_at' => 'date_joined_company', 'date_joined' => 'date_joined_company',
        'e_mail' => 'email', 'email_address' => 'email', 'phone_number' => 'phone', 'mobile' => 'phone',
        'fan' => 'fan_number', 'national_id' => 'fan_number', 'date_of_birth' => 'birth_date', 'dob' => 'birth_date',
        'father_name' => 'middle_name', 'grandfather_name' => 'last_name',
    ];

    private const LABELS = [
        'name' => 'Name', 'birth_date' => 'Birth date', 'fan_number' => 'FAN number', 'email' => 'Email',
        'phone' => 'Phone', 'level' => 'Plan', 'job_title' => 'Job title', 'department' => 'Department',
        'company_branch_id' => 'Branch', 'registration_status' => 'Company approval',
    ];

    /* ── Reading ─────────────────────────────────────────────── */

    /** @return array<int, array<string, string>> */
    public function readFile(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $rows = $ext === 'csv' || $ext === 'txt'
            ? $this->readCsv($file->getRealPath())
            : Xlsx::read($file->getRealPath(), ['birth_date', 'date_of_birth', 'dob', 'date_joined_company', 'joined_at', 'date_joined']);

        return array_map(function (array $row) {
            $out = [];
            foreach ($row as $k => $v) {
                $out[self::ALIASES[$k] ?? $k] = trim((string) $v);
            }

            return $out;
        }, $rows);
    }

    private function readCsv(string $path): array
    {
        $h = fopen($path, 'r');
        if (!$h) return [];
        $header = fgetcsv($h);
        if (!$header) { fclose($h); return []; }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $keys = array_map(fn ($x) => Xlsx::headerKey((string) $x), $header);
        $rows = [];
        while (($line = fgetcsv($h)) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) continue;
            $row = [];
            foreach ($keys as $i => $k) {
                if ($k === '') continue;
                $v = trim((string) ($line[$i] ?? ''));
                $row[$k] = in_array($k, ['birth_date', 'date_joined_company', 'joined_at', 'dob', 'date_of_birth'], true) && $v !== ''
                    ? Xlsx::normalizeDate($v) : $v;
            }
            $rows[] = $row;
        }
        fclose($h);

        return $rows;
    }

    /* ── Analysis ────────────────────────────────────────────── */

    public static function normalizePhone(string $raw): ?string
    {
        $d = preg_replace('/\D/', '', $raw);
        if ($d === '') return null;
        if (str_starts_with($d, '251') && strlen($d) === 12) $d = substr($d, 3);
        elseif (str_starts_with($d, '0') && strlen($d) === 10) $d = substr($d, 1);
        if (strlen($d) !== 9 || !in_array($d[0], ['7', '9'], true)) return null;

        return '+251' . $d;
    }

    /**
     * @return array{rows: array<int, array>, summary: array<string, int>}
     */
    public function analyze(array $rows, Company $company, ?CompanyBranch $forcedBranch = null): array
    {
        $plans = MembershipPlan::where('is_active', true)->get(['name', 'tier']);
        $branches = $company->branches()->get(['id', 'name']);
        $seen = ['email' => [], 'phone' => [], 'fan' => []];
        $out = [];

        foreach ($rows as $i => $row) {
            $rowNo = $i + 2;
            $errors = [];

            $first  = $this->clean($row['first_name'] ?? '');
            $middle = $this->clean($row['middle_name'] ?? '');
            $last   = $this->clean($row['last_name'] ?? '');
            $email  = strtolower(trim($row['email'] ?? ''));
            $fan    = preg_replace('/\s+/', '', $row['fan_number'] ?? '');
            $phone  = self::normalizePhone($row['phone'] ?? '');
            $birth  = $row['birth_date'] ?? '';
            $joined = $row['date_joined_company'] ?? '';

            if ($first === '')  $errors[] = 'First name is missing.';
            if ($middle === '') $errors[] = 'Middle name is missing.';
            if ($last === '')   $errors[] = 'Last name is missing.';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is missing or not valid.';
            if (!preg_match('/^\d{5,20}$/', $fan)) $errors[] = 'FAN number must be 5 to 20 digits.';
            if (!$phone) $errors[] = 'Phone must be an Ethiopian mobile number, e.g. +251911234567 or 0911234567.';

            $birthDate = $this->date($birth);
            if (!$birthDate) $errors[] = 'Birth date is missing or not in YYYY-MM-DD format.';
            elseif ($birthDate->gt(now()->subYears(16)) || $birthDate->lt(now()->subYears(100))) $errors[] = 'Birth date does not look right.';
            $joinedDate = $joined !== '' ? $this->date($joined) : null;
            if ($joined !== '' && !$joinedDate) $errors[] = 'Date joined company is not in YYYY-MM-DD format.';

            $planRef = strtolower(trim($row['plan'] ?? ''));
            $plan = $plans->first(fn ($p) => strtolower($p->name) === $planRef || strtolower($p->tier) === $planRef);
            if (!$plan) $errors[] = $planRef === '' ? 'Plan is missing.' : "Plan \"{$row['plan']}\" does not exist. Pick one from the list.";

            $branchId = $forcedBranch?->id;
            if (!$forcedBranch) {
                $bRef = strtolower(trim($row['branch'] ?? ''));
                if ($bRef !== '' && !in_array($bRef, ['head office', 'main', 'hq'], true)) {
                    $b = $branches->first(fn ($x) => strtolower($x->name) === $bRef);
                    if ($b) {
                        $branchId = $b->id;
                    } else {
                        $errors[] = "Branch \"{$row['branch']}\" was not found.";
                    }
                }
            }

            foreach (['email' => $email, 'phone' => $phone, 'fan' => $fan] as $k => $v) {
                if ($v && isset($seen[$k][$v])) {
                    $errors[] = ucfirst($k === 'fan' ? 'FAN number' : $k) . " is repeated in the file (also on row {$seen[$k][$v]}).";
                } elseif ($v) {
                    $seen[$k][$v] = $rowNo;
                }
            }

            $name = trim(preg_replace('/\s+/', ' ', "{$first} {$middle} {$last}"));
            $base = ['row' => $rowNo, 'name' => $name, 'email' => $email, 'phone' => $phone, 'plan' => $plan?->name];

            if ($errors) {
                $out[] = $base + ['action' => 'error', 'errors' => $errors];
                continue;
            }

            $data = [
                'name' => $name, 'birth_date' => $birthDate->toDateString(), 'fan_number' => $fan,
                'email' => $email, 'phone' => $phone, 'level' => Employee::packageToLevel($plan->tier),
                'job_title' => $this->clean($row['job_title'] ?? '') ?: null,
                'department' => $this->clean($row['department'] ?? '') ?: null,
                'company_branch_id' => $branchId,
                'joined' => $joinedDate?->toDateString(),
            ];

            [$employee, $matchError] = $this->match($company, $email, $phone, $fan);
            if ($matchError) {
                $out[] = $base + ['action' => 'error', 'errors' => [$matchError]];
                continue;
            }

            if (!$employee) {
                $out[] = $base + ['action' => 'create', 'data' => $data];
                continue;
            }

            $changes = $this->diff($employee, $data);
            $out[] = $base + [
                'action'      => $changes ? 'update' : 'unchanged',
                'employee_id' => $employee->id,
                'changes'     => $changes,
                'data'        => $data,
            ];
        }

        $summary = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($out as $r) $summary[$r['action']]++;

        return ['rows' => $out, 'summary' => $summary + ['total' => count($out)]];
    }

    /**
     * Find this person in the company. Returns [employee|null, error|null].
     */
    private function match(Company $company, string $email, string $phone, string $fan): array
    {
        $users = User::where('email', $email)->orWhere('phone', $phone)->orWhere('fan_number', $fan)->get();
        $byFan = Employee::withTrashed()->where('fan_number', $fan)->first();

        $candidates = collect();
        foreach ($users as $u) {
            $emp = Employee::where('user_id', $u->id)->first();
            if (!$emp) {
                $what = strtolower($u->email) === $email ? 'email' : ($u->phone === $phone ? 'phone number' : 'FAN number');

                return [null, "This {$what} already belongs to another FitAccess account (not an employee)."];
            }
            $candidates->push($emp);
        }
        if ($byFan && !$byFan->trashed()) {
            $candidates->push($byFan);
        } elseif ($byFan && $byFan->trashed()) {
            return [null, 'This FAN number belongs to a removed employee. Contact FitAccess to restore them.'];
        }

        $candidates = $candidates->unique('id');
        $other = $candidates->first(fn ($e) => (int) $e->company_id !== (int) $company->id);
        if ($other) {
            return [null, 'This person (email, phone or FAN) is already registered under another company.'];
        }
        if ($candidates->count() > 1) {
            return [null, 'The email, phone and FAN number match different employees ('
                . $candidates->map(fn ($e) => $e->user?->name ?? "#{$e->id}")->implode(', ') . '). Check this row.'];
        }

        return [$candidates->first(), null];
    }

    /** field => [old, new] for display; only real changes or missing info */
    private function diff(Employee $e, array $d): array
    {
        $e->loadMissing('user', 'companyBranch');
        $u = $e->user;
        $cur = [
            'name' => $u?->name, 'birth_date' => $e->birth_date?->toDateString(), 'fan_number' => $e->fan_number,
            'email' => strtolower((string) $u?->email), 'phone' => $u?->phone, 'level' => $e->level,
            'job_title' => $e->job_title, 'department' => $e->department, 'company_branch_id' => $e->company_branch_id,
        ];
        $changes = [];
        foreach ($cur as $k => $old) {
            $new = $d[$k];
            if ($k === 'job_title' || $k === 'department') {
                if ($new === null) continue; // an empty cell never erases what we have
            }
            if ((string) $old !== (string) $new) {
                $changes[$k] = [
                    'label' => self::LABELS[$k],
                    'old'   => $this->display($k, $old, $e),
                    'new'   => $this->display($k, $new, $e),
                ];
            }
        }
        if (($e->registration_status ?? 'approved') !== 'approved') {
            $changes['registration_status'] = ['label' => self::LABELS['registration_status'], 'old' => ucfirst((string) $e->registration_status), 'new' => 'Approved (in company list)'];
        }

        return $changes;
    }

    private function display(string $k, $v, Employee $e): string
    {
        if ($v === null || $v === '') return '—';
        if ($k === 'level') {
            return MembershipPlan::where('is_active', true)->get()->first(fn ($p) => Employee::packageToLevel($p->tier) === $v)?->name ?? ucfirst((string) $v);
        }
        if ($k === 'company_branch_id') {
            return CompanyBranch::find($v)?->name ?? 'Head office';
        }

        return (string) $v;
    }

    /* ── Applying ────────────────────────────────────────────── */

    /**
     * Save every create/update row of a fresh analysis. Returns counts and the
     * rows that were skipped.
     */
    public function apply(array $analysis, Company $company, ?User $by = null): array
    {
        $created = $updated = $late = 0;
        $invited = [];
        $confirmedSelfSignups = [];

        foreach ($analysis['rows'] as $r) {
            if ($r['action'] === 'create') {
                $employee = DB::transaction(fn () => $this->createEmployee($company, $r['data']));
                $created++;
                // Added after the staff-list cut-off → active next working day, extra days on the next invoice
                if (LateJoiners::schedule($employee)) $late++;
                $invited[] = $employee;
            } elseif ($r['action'] === 'update') {
                $employee = Employee::with('user')->find($r['employee_id']);
                if (!$employee) continue;
                $wasPending = ($employee->registration_status ?? 'approved') !== 'approved';
                $emailChanged = DB::transaction(fn () => $this->updateEmployee($employee, $r['data']));
                $updated++;
                if ($wasPending && LateJoiners::schedule($employee->fresh())) $late++;
                if (!$employee->account_created_at && ($emailChanged || !$employee->invited_at)) {
                    $invited[] = $employee;
                } elseif ($wasPending && $employee->account_created_at) {
                    $confirmedSelfSignups[] = $employee;
                }
            }
        }

        foreach ($invited as $e) {
            self::invite($e->fresh(['user', 'company']));
        }
        foreach ($confirmedSelfSignups as $e) {
            if ($e->user) {
                Notifier::user($e->user, 'employee_confirmed', 'Your company confirmed your FitAccess registration',
                    "{$company->name} added you to its FitAccess list. Your card number will be sent to you once the membership is paid.",
                    ['buttonText' => 'Open FitAccess', 'buttonUrl' => config('app.frontend_url') . '/employee/dashboard']);
            }
        }

        $s = $analysis['summary'];
        $text = "{$created} new employee(s) added, {$updated} updated, {$s['unchanged']} unchanged"
            . ($s['error'] ? ", {$s['error']} row(s) skipped because of errors" : '') . '.'
            . ($late ? " {$late} employee(s) were added after the staff-list cut-off: they become active the next working day and their extra days are charged on the next invoice." : '');

        Notifier::company($company, 'employees_imported', 'Employee list imported',
            "Your employee list was imported: {$text} New employees were emailed a link to create their FitAccess account.",
            ['buttonText' => 'View employees', 'buttonUrl' => config('app.frontend_url') . '/hr/employees']);
        Notifier::admins('employees_imported', "{$company->name} imported its employee list",
            "{$company->name}: {$text}" . ($by ? " Imported by {$by->name}." : ''), ['company_id' => $company->id]);

        return ['late_joiners' => $late, 'created' => $created, 'updated' => $updated, 'unchanged' => $s['unchanged'], 'skipped' => $s['error'], 'invited' => count($invited), 'message' => $text];
    }

    private function createEmployee(Company $company, array $d): Employee
    {
        $user = User::create([
            'name'       => $d['name'],
            'email'      => $d['email'],
            'phone'      => $d['phone'],
            'fan_number' => $d['fan_number'],
            // Unusable until the employee creates their own password from the invitation
            'password'   => Hash::make(Str::random(48)),
            'role'       => 'employee',
            'is_active'  => false,
        ]);

        $employee = Employee::create([
            'user_id'               => $user->id,
            'company_id'            => $company->id,
            'company_branch_id'     => $d['company_branch_id'],
            'branch'                => $d['company_branch_id'] ? CompanyBranch::find($d['company_branch_id'])?->name : null,
            'fan_number'            => $d['fan_number'],
            'birth_date'            => $d['birth_date'],
            'job_title'             => $d['job_title'],
            'department'            => $d['department'],
            'level'                 => $d['level'],
            'registration_status'   => 'approved',   // the company itself listed them
            'admin_approval_status' => 'pending',    // FitAccess approves once paid
            'payment_status'        => 'unpaid',
            'payment_preference'    => 'pay_later',
            'is_enrolled'           => true,
            'enrolled_at'           => $d['joined'] ?? now(),
        ]);
        AuditLog::record('created', $employee, [], ['source' => 'excel_import']);

        return $employee;
    }

    /** @return bool whether the email changed */
    private function updateEmployee(Employee $e, array $d): bool
    {
        $u = $e->user;
        $old = $e->only(['birth_date', 'fan_number', 'level', 'job_title', 'department', 'company_branch_id', 'registration_status']);
        $emailChanged = $u && strtolower($u->email) !== $d['email'];

        $u?->update(array_filter([
            'name' => $d['name'], 'email' => $d['email'], 'phone' => $d['phone'], 'fan_number' => $d['fan_number'],
        ], fn ($v) => $v !== null));

        $e->update(array_filter([
            'birth_date'          => $d['birth_date'],
            'fan_number'          => $d['fan_number'],
            'level'               => $d['level'],
            'job_title'           => $d['job_title'],
            'department'          => $d['department'],
            'company_branch_id'   => $d['company_branch_id'],
            'branch'              => $d['company_branch_id'] ? CompanyBranch::find($d['company_branch_id'])?->name : null,
            'registration_status' => 'approved',
            'is_enrolled'         => true,
        ], fn ($v, $k) => $v !== null || in_array($k, ['company_branch_id', 'branch'], true), ARRAY_FILTER_USE_BOTH));

        // A self-registered employee whom the company now lists still needs FitAccess's final approval
        if (!$e->admin_approval_status) {
            $e->update(['admin_approval_status' => 'pending']);
        }
        AuditLog::record('updated', $e, $old, ['source' => 'excel_import']);

        return $emailChanged;
    }

    /* ── Invitation ──────────────────────────────────────────── */

    /** Email (and notify) the employee a link to create their account. */
    public static function invite(Employee $e): void
    {
        $user = $e->user;
        if (!$user || $e->account_created_at) return;

        $token = Str::random(48);
        $days = max(1, Settings::int('invite_link_days'));
        $e->update([
            'invite_token_hash' => hash('sha256', $token),
            'invited_at'        => now(),
            'invite_expires_at' => now()->addDays($days),
        ]);

        $company = $e->company?->name ?? 'your company';
        $link = config('app.frontend_url') . '/join?token=' . $token;

        Notifier::user($user, 'employee_invited', "You're registered for FitAccess by {$company}",
            "{$company} has registered you for FitAccess, the corporate gym and wellness membership. Create your account to get started.",
            [
                'subject'    => "Welcome to FitAccess — create your account ({$company})",
                'heading'    => 'Welcome to FitAccess',
                'highlight'  => "Registered by {$company}",
                'paragraphs' => [
                    "{$company} has registered you for FitAccess, the corporate gym and wellness membership available at partner gyms across Ethiopia.",
                    'Create your account with the button below. You will be asked to confirm your first name, last name, birth date and email exactly as your company provided them, and to choose a password.',
                    'Once your company has completed the payment, FitAccess will e-mail you your personal card number. Enter it in the portal to start using partner gyms.',
                ],
                'steps'      => [
                    'Create your account (this link is valid for ' . $days . ' days).',
                    'Sign in to explore gyms, trainings and news.',
                    'Receive your card number from FitAccess by e-mail and activate your card.',
                ],
                'buttonText' => 'Create my account',
                'buttonUrl'  => $link,
                'closing'    => 'If you did not agree to join FitAccess, please contact your HR team.',
                'appLinks'   => Settings::appLinks(),
                'appText'    => 'You can also create your account in the FitAccess mobile app' . (Settings::appLinks() ? '' : ' (search for FitAccess in Google Play or the App Store)') . ': open the app, choose Create account and enter the same details: first name, last name, birth date and this e-mail address.',
            ]
        );
    }

    private function clean(string $v): string
    {
        return trim(preg_replace('/\s+/', ' ', $v));
    }

    private function date(string $v): ?Carbon
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $v);

            return $d && $d->format('Y-m-d') === $v ? $d : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
