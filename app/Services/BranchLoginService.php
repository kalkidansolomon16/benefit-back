<?php

namespace App\Services;

use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared by gyms and companies: every branch has its own login
 * (email + password) and is submitted as part of a registration or later
 * from the portal.
 */
class BranchLoginService
{
    public const GYM = 'gym';
    public const COMPANY = 'company';

    /**
     * Validate a list of branch entries and return only the known fields.
     *
     * @param  string[]  $reservedEmails  emails a branch login may not reuse (e.g. the main account's)
     */
    public static function validate(mixed $branches, string $kind, array $reservedEmails = []): array
    {
        if (!is_array($branches)) {
            $branches = [];
        }

        $rules = [
            'branches'                    => 'array|max:50',
            'branches.*.branch_name'      => 'required|string|max:100',
            'branches.*.contact_person'   => 'required|string|max:255',
            'branches.*.email'            => ['required', 'email', 'max:255', 'distinct:ignore_case',
                                              Rule::unique('users', 'email')->whereNull('deleted_at')],
            'branches.*.password'         => ['required', 'string', new StrongPassword()],
            'branches.*.city'             => 'required|string|max:100',
            'branches.*.sub_city'         => 'nullable|string|max:100',
            'branches.*.woreda'           => 'required|string|max:255',
            'branches.*.landmark'         => 'nullable|string|max:255',
            'branches.*.contact_phone'    => 'nullable|string|max:20',
        ];
        $messages = [
            'branches.*.branch_name.required'    => 'Each branch needs a name.',
            'branches.*.contact_person.required' => 'Each branch needs a manager / contact name.',
            'branches.*.email.required'          => 'Each branch needs its own login email.',
            'branches.*.email.unique'            => 'A branch login email is already registered.',
            'branches.*.email.distinct'          => 'Each branch must use a different login email.',
            'branches.*.password.required'       => 'Each branch needs its own password.',
            'branches.*.city.required'           => 'Each branch needs a city.',
            'branches.*.woreda.required'         => 'Each branch needs an address.',
        ];
        $fields = ['branch_name', 'contact_person', 'email', 'password', 'city', 'sub_city',
                   'woreda', 'landmark', 'contact_phone'];

        if ($kind === self::GYM) {
            $rules += [
                'branches.*.google_maps_link' => 'nullable|string|max:500',
                'branches.*.weekday_open'     => 'required|string|max:10',
                'branches.*.weekday_close'    => 'required|string|max:10',
                'branches.*.weekend_open'     => 'nullable|string|max:10',
                'branches.*.weekend_close'    => 'nullable|string|max:10',
                'branches.*.max_capacity'     => 'required|integer|min:1',
            ];
            $messages += [
                'branches.*.weekday_open.required'  => 'Each branch needs weekday opening hours.',
                'branches.*.weekday_close.required' => 'Each branch needs weekday closing hours.',
                'branches.*.max_capacity.required'  => 'Each branch needs a maximum capacity.',
            ];
            array_push($fields, 'google_maps_link', 'weekday_open', 'weekday_close',
                       'weekend_open', 'weekend_close', 'max_capacity');
        }

        Validator::make(['branches' => $branches], $rules, $messages)->validate();

        $reserved = array_map('strtolower', array_filter($reservedEmails));
        foreach ($branches as $i => $b) {
            if (in_array(strtolower($b['email']), $reserved, true)) {
                throw ValidationException::withMessages([
                    "branches.$i.email" => 'A branch login email must differ from the main account email.',
                ]);
            }
        }

        return array_values(array_map(
            fn($b) => array_merge(array_fill_keys($fields, null), array_intersect_key($b, array_flip($fields))),
            $branches
        ));
    }

    /** Create the branch's own login. The plain password is never stored anywhere else. */
    public static function createLogin(array $branch, string $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'name'      => $branch['contact_person'],
            'email'     => $branch['email'],
            'password'  => Hash::make($branch['password']),
            'role'      => $role,
            'phone'     => $branch['contact_phone'] ?? null,
            'is_active' => false,
        ], $attributes));
    }
}
