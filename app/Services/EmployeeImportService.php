<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeImportService
{
    /**
     * Bulk-create employees from an uploaded CSV.
     *
     * When $forcedCompany is given (HR flow), every row is created under that
     * company and any "company" column in the file is ignored. Otherwise
     * (admin flow) each row must resolve to a company via a "company" column
     * (numeric id or exact company name).
     *
     * @return array{created: array<int, array<string, mixed>>, failed: array<int, array{row: int, errors: array<int, string>}>}
     */
    public function import(UploadedFile $file, ?Company $forcedCompany = null, ?\App\Models\CompanyBranch $forcedBranch = null): array
    {
        $rows = $this->parseCsv($file);

        $created    = [];
        $failed     = [];
        $seenEmails = [];
        $seenFans   = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // header is row 1

            $company = $forcedCompany;
            if (!$company) {
                $companyRef = trim($row['company'] ?? '');
                if ($companyRef === '') {
                    $failed[] = ['row' => $rowNumber, 'errors' => ['The company column is required.']];
                    continue;
                }
                $company = ctype_digit($companyRef)
                    ? Company::find((int) $companyRef)
                    : Company::whereRaw('LOWER(name) = ?', [strtolower($companyRef)])->first();

                if (!$company) {
                    $failed[] = ['row' => $rowNumber, 'errors' => ["Company '{$companyRef}' was not found."]];
                    continue;
                }
            }

            $validator = Validator::make($row, [
                'first_name'  => 'required|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'last_name'   => 'required|string|max:100',
                'email'       => 'required|email|max:255',
                'fan_number'  => 'required|string|min:5|max:20',
                'package'     => ['required', 'string', Rule::exists('membership_plans', 'tier')->where(fn ($q) => $q->where('is_active', true))],
                'job_title'   => 'nullable|string|max:255',
                'department'  => 'nullable|string|max:255',
                'phone'       => 'nullable|string|max:20',
                'joined_at'   => 'nullable|date',
            ]);

            if ($validator->fails()) {
                $failed[] = ['row' => $rowNumber, 'errors' => $validator->errors()->all()];
                continue;
            }

            $email = strtolower(trim($row['email']));
            $fan   = trim($row['fan_number']);

            if (isset($seenEmails[$email]) || User::where('email', $email)->exists()) {
                $failed[] = ['row' => $rowNumber, 'errors' => ["Email '{$email}' is already registered."]];
                continue;
            }
            if (isset($seenFans[$fan]) || Employee::where('fan_number', $fan)->exists() || User::where('fan_number', $fan)->exists()) {
                $failed[] = ['row' => $rowNumber, 'errors' => ["FAN number '{$fan}' is already registered."]];
                continue;
            }

            $seenEmails[$email] = true;
            $seenFans[$fan]     = true;

            $fullName = preg_replace(
                '/\s+/',
                ' ',
                trim("{$row['first_name']} " . ($row['middle_name'] ?? '') . " {$row['last_name']}")
            );
            $level        = Employee::packageToLevel($row['package']);
            $tempPassword = Str::password(14);

            DB::transaction(function () use ($row, $company, $email, $fan, $fullName, $level, $tempPassword, $rowNumber, $forcedBranch, &$created) {
                $user = User::create([
                    'name'                 => $fullName,
                    'email'                => $email,
                    'password'             => Hash::make($tempPassword),
                    'role'                 => 'employee',
                    'phone'                => $row['phone'] ?: null,
                    'fan_number'           => $fan,
                    'is_active'            => false,
                    'must_reset_password'  => true,
                ]);

                $employee = Employee::create([
                    'user_id'               => $user->id,
                    'company_id'            => $company->id,
                    'company_branch_id'     => $forcedBranch?->id,
                    'branch'                => $forcedBranch?->name,
                    'fan_number'            => $fan,
                    'job_title'             => $row['job_title'] ?: null,
                    'level'                 => $level,
                    'department'            => $row['department'] ?: null,
                    'registration_status'   => 'approved',
                    'admin_approval_status' => 'pending',
                    'is_enrolled'           => true,
                    'enrolled_at'           => $row['joined_at'] ?: now(),
                    'payment_status'        => 'unpaid',
                ]);

                AuditLog::record('created', $employee);

                $created[] = [
                    'row'           => $rowNumber,
                    'name'          => $fullName,
                    'email'         => $email,
                    'fan_number'    => $fan,
                    'company'       => $company->name,
                    'temp_password' => $tempPassword,
                ];
            });
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return [];
        }
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) === 1 && trim((string) $line[0]) === '') {
                continue; // skip blank lines
            }
            $row = [];
            foreach ($header as $idx => $key) {
                $row[$key] = trim((string) ($line[$idx] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
