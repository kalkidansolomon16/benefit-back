<?php

namespace App\Services;

use App\Models\Company;
use App\Models\MembershipPlan;
use App\Support\BillingCalendar;
use App\Support\Settings;
use App\Support\Xlsx;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * The two files a company receives when FitAccess approves it:
 *  - the employee contract (admin-uploaded Word/PDF, or a built-in default .docx)
 *  - the Excel template the company fills in with the employees who signed it
 */
class OnboardingDocuments
{
    /** Column order of the employee template (header text => import key) */
    public const COLUMNS = [
        'First name *'                 => 'first_name',
        'Middle name *'                => 'middle_name',
        'Last name *'                  => 'last_name',
        'Birth date * (DD/MM/YYYY)'    => 'birth_date',
        'FAN number *'                 => 'fan_number',
        'Email *'                      => 'email',
        'Phone *'                      => 'phone',
        'Plan *'                       => 'plan',
        'Job title'                    => 'job_title',
        'Department'                   => 'department',
        'Branch'                       => 'branch',
        'Date joined company (DD/MM/YYYY)' => 'date_joined_company',
    ];

    /* ── Excel template ──────────────────────────────────────── */

    public static function templateFilename(?Company $company = null): string
    {
        $slug = $company ? preg_replace('/[^A-Za-z0-9]+/', '-', $company->name) : 'company';

        return 'FitAccess-employees-' . trim($slug, '-') . '.xlsx';
    }

    public static function employeeTemplate(?Company $company = null): string
    {
        $plans = MembershipPlan::where('is_active', true)->orderBy('monthly_fee_etb')->get(['name', 'tier', 'monthly_fee_etb']);
        $branches = $company ? $company->branches()->where('is_active', true)->orderBy('name')->pluck('name')->all() : [];
        array_unshift($branches, 'Head office');

        $headers = array_keys(self::COLUMNS);
        $planCol = array_search('plan', array_values(self::COLUMNS), true);
        $branchCol = array_search('branch', array_values(self::COLUMNS), true);

        $lists = [['Plans', 'Branches']];
        $max = max(count($plans), count($branches));
        for ($i = 0; $i < $max; $i++) {
            $lists[] = [$plans[$i]->name ?? null, $branches[$i] ?? null];
        }

        $calendar = BillingCalendar::rulesText();
        $instructions = [
            ['How to fill in this file'],
            ['ℹ Add one row per employee who signed the FitAccess contract, on the "Employees" sheet. Do not change the header row.'],
            ['ℹ Required columns are marked with *. Birth date and other dates use the format DD/MM/YYYY: day/month/year (e.g. 14/05/1992 for 14 May 1992).'],
            ['ℹ Email and phone must be different for every employee. They are used to invite the employee to create their account.'],
            ['ℹ Pick the Plan (and Branch, if you have branches) from the drop-down list.'],
            ['ℹ You can import this file again next period: employees already registered are not duplicated, only their changed details are updated.'],
            [''],
            ['Plans'],
        ];
        foreach ($plans as $p) {
            $instructions[] = ["{$p->name}: ETB " . number_format((float) $p->monthly_fee_etb) . ' per employee per month'];
        }
        $instructions[] = [''];
        $instructions[] = ['Payment calendar'];
        foreach ($calendar as $line) {
            $instructions[] = [$line];
        }
        $instructions[] = [''];
        $instructions[] = ['Example row (do not copy into the Employees sheet)'];
        $instructions[] = $headers;
        $instructions[] = ['Abebe', 'Girma', 'Haile', '14/05/1992', '1234567890123', 'abebe.girma@example.com', '+251911234567',
            $plans[0]->name ?? 'Fit Basic', 'Accountant', 'Finance', 'Head office', now()->format('d/m/Y')];

        return Xlsx::build([
            [
                'name'   => 'Employees',
                'rows'   => [$headers],
                'widths' => [16, 16, 16, 24, 18, 30, 18, 18, 20, 18, 20, 30],
                'lists'  => array_filter([
                    $planCol => '\'Lists\'!$A$2:$A$' . (count($plans) + 1),
                    $branchCol => '\'Lists\'!$B$2:$B$' . (count($branches) + 1),
                ]),
            ],
            ['name' => 'Instructions', 'rows' => $instructions, 'widths' => [110], 'header' => true],
            ['name' => 'Lists', 'rows' => $lists, 'widths' => [24, 24], 'hidden' => true],
        ]);
    }

    /* ── Contract ────────────────────────────────────────────── */

    /** @return array{content: string, name: string, mime: string} */
    public static function contract(): array
    {
        $path = Settings::get('contract_template_path');
        if ($path && Storage::disk('public')->exists($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            return [
                'content' => Storage::disk('public')->get($path),
                'name'    => 'FitAccess-employee-contract.' . $ext,
                'mime'    => match ($ext) {
                    'pdf'   => 'application/pdf',
                    'doc'   => 'application/msword',
                    default => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                },
            ];
        }

        // Official form FA-EMP-01 (Employee Enrolment and Consent Form), shipped with the app
        $official = resource_path('contracts/FitAccess-Employee-Enrolment-and-Consent-Form.pdf');
        if (is_file($official)) {
            return [
                'content' => file_get_contents($official),
                'name'    => 'FitAccess-Employee-Enrolment-and-Consent-Form.pdf',
                'mime'    => 'application/pdf',
            ];
        }

        return [
            'content' => self::defaultContractDocx(),
            'name'    => 'FitAccess-employee-contract.docx',
            'mime'    => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
    }

    /** Temporary contract until FitAccess uploads its own (Admin → Rules & documents). */
    public static function defaultContractDocx(): string
    {
        $p = [];
        $p[] = ['FITACCESS EMPLOYEE MEMBERSHIP AGREEMENT', 'title'];
        $p[] = ['Corporate wellness benefit — employee consent form', 'sub'];
        $p[] = [''];
        $p[] = ['Employer (company): ______________________________________________'];
        $p[] = ['Employee full name: ______________________________________________'];
        $p[] = ['FAN number: ____________________    Date of birth: ____ / ____ / ________'];
        $p[] = ['Email: ___________________________    Phone: ___________________________'];
        $p[] = ['Plan (as agreed with your employer):   ☐ Fit Basic    ☐ Fit Basic Plus    ☐ Fit Premium'];
        $p[] = [''];
        $p[] = ['1. Purpose', 'h'];
        $p[] = ['My employer offers FitAccess, a corporate gym and wellness membership in Ethiopia, as an employee benefit. By signing, I confirm that I want to join FitAccess under my employer\'s plan.'];
        $p[] = ['2. Personal data', 'h'];
        $p[] = ['I agree that my employer shares my name, date of birth, FAN number, email, phone number, job details and plan with FitAccess, and that FitAccess uses them only to create and manage my membership, send me service messages, and record my gym check-ins.'];
        $p[] = ['3. My account and card', 'h'];
        $p[] = ['FitAccess will email me a link to create my account. I will enter the same details as above and choose my own password. My personal FitAccess card number is given to me by my employer after the membership is paid. The card is personal and may not be shared or lent to anyone.'];
        $p[] = ['4. Use of partner gyms', 'h'];
        $p[] = ['I will follow the rules of every partner gym I visit. Check-in is allowed once per day within the opening hours set by FitAccess. Misuse (for example sharing my QR code or card) can lead to suspension of my access.'];
        $p[] = ['5. Payment and validity', 'h'];
        $p[] = ['My employer pays FitAccess for my membership monthly, every 3 months, every 6 months or yearly. My access is valid only for the period my employer has paid for. If payment is not completed on time, my card is disabled until it is paid; my card number stays the same.'];
        $p[] = ['6. Leaving the programme', 'h'];
        $p[] = ['I may leave the programme by informing my employer in writing. My access ends at the end of the period already paid for.'];
        $p[] = [''];
        $p[] = ['Employee signature: ____________________________    Date: ____ / ____ / ________'];
        $p[] = [''];
        $p[] = ['For the employer — HR name and signature: ____________________________    Date: ____ / ____ / ________'];
        $p[] = [''];
        $p[] = ['This is a temporary FitAccess template. Keep the signed form at your company; do not send it to FitAccess. Add every employee who signed to the FitAccess Excel template and import it in the HR portal.', 'note'];

        $body = '';
        foreach ($p as $item) {
            $text  = $item[0];
            $style = $item[1] ?? null;
            $rPr = match ($style) {
                'title' => '<w:rPr><w:b/><w:sz w:val="32"/></w:rPr>',
                'sub'   => '<w:rPr><w:color w:val="4D7C0F"/><w:sz w:val="22"/></w:rPr>',
                'h'     => '<w:rPr><w:b/><w:sz w:val="24"/></w:rPr>',
                'note'  => '<w:rPr><w:i/><w:color w:val="6B7280"/><w:sz w:val="18"/></w:rPr>',
                default => '<w:rPr><w:sz w:val="22"/></w:rPr>',
            };
            $pPr = $style === 'title' || $style === 'sub' ? '<w:pPr><w:jc w:val="center"/><w:spacing w:after="120"/></w:pPr>' : '<w:pPr><w:spacing w:after="120"/></w:pPr>';
            $body .= '<w:p>' . $pPr . ($text === '' ? '' : '<w:r>' . $rPr . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1, 'UTF-8') . '</w:t></w:r>') . '</w:p>';
        }

        $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr>'
            . '</w:body></w:document>';

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', $doc);
        $zip->close();
        $bin = file_get_contents($tmp);
        @unlink($tmp);

        return $bin;
    }
}
