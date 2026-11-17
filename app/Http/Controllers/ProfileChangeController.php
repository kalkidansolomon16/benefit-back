<?php

namespace App\Http\Controllers;

use App\Mail\FitAccessNotificationMail;
use App\Models\AdminNotification;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\OneTimeCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * v2 read-only profile: email and phone change with a code sent to the
 * new address or number; other details through a correction request.
 * Company members' name and company are managed by HR.
 */
class ProfileChangeController extends Controller
{
    public const CORRECTABLE = ['name' => 'Full name', 'date_of_birth' => 'Date of birth'];

    public function __construct(private readonly OneTimeCodeService $codes) {}

    /** POST /mobile/profile/contact {kind: email|phone, value} */
    public function requestContactChange(Request $request): JsonResponse
    {
        $data = $this->validateContact($request);
        $user = $request->user();

        if ($data['kind'] === 'phone' && !$this->codes->smsAvailable()) {
            return response()->json([
                'message' => 'Changing your phone number in the app isn’t available yet. Contact support to change it.',
                'code' => 'sms_unavailable',
            ], 503);
        }

        $this->codes->send(
            'contact',
            $this->subject($user, $data['kind'], $data['value']),
            $data['kind'] === 'email' ? 'email' : 'sms',
            $data['value'],
            $user->name,
            $data['kind'] === 'email' ? 'your new email address' : 'your new phone number',
        );

        return response()->json(['data' => [
            'masked_contact' => OneTimeCodeService::mask($data['value']),
            'expires_in' => OneTimeCodeService::TTL_MINUTES * 60,
        ]]);
    }

    /** POST /mobile/profile/contact/verify {kind, value, code} */
    public function verifyContactChange(Request $request): JsonResponse
    {
        $data = $this->validateContact($request) + $request->validate(['code' => 'required|digits:6']);
        $user = $request->user();

        $result = $this->codes->verify('contact', $this->subject($user, $data['kind'], $data['value']), $request->code);
        if ($result !== 'ok') {
            return response()->json([
                'message' => $result === 'wrong' ? 'That code isn’t right. Try again.' : 'This code has expired. Ask for a new one.',
                'code' => $result === 'wrong' ? 'wrong_code' : 'code_expired',
            ], 422);
        }

        $old = $user->{$data['kind']};
        $user->forceFill([$data['kind'] => $data['value']])->save();
        AuditLog::record('contact_changed', $user, [$data['kind'] => $old], [$data['kind'] => $data['value']]);

        // Tell the old address, in case this wasn't the member.
        if ($data['kind'] === 'email' && $old) {
            try {
                Mail::to($old)->send(new FitAccessNotificationMail(
                    recipientName: $user->name,
                    emailSubject: 'Your FitAccess email was changed',
                    heading: 'Email address changed',
                    message: 'The email on your FitAccess account was changed to '.OneTimeCodeService::mask($data['value']).'. If this wasn’t you, contact FitAccess support straight away.',
                    buttonText: 'Contact support',
                    buttonUrl: 'mailto:'.config('mail.from.address'),
                    color: '#E53935',
                ));
            } catch (\Throwable $e) {
                Log::warning('contact_changed.notify_failed', ['user_id' => $user->id]);
            }
        }

        return response()->json(['data' => [$data['kind'] => $data['value']], 'message' => 'Updated.']);
    }

    /** POST /mobile/profile/corrections {field, requested_value, note?} */
    public function requestCorrection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(array_keys(self::CORRECTABLE))],
            'requested_value' => 'required|string|max:255',
            'note' => 'nullable|string|max:500',
        ]);
        $user = $request->user();

        if ($data['field'] === 'name' && $user->employee) {
            return response()->json(['message' => 'Your name is managed by your company. Contact your HR to update it.', 'code' => 'managed_by_company'], 422);
        }

        $id = DB::transaction(function () use ($user, $data) {
            DB::table('profile_corrections')->where('user_id', $user->id)->where('field', $data['field'])->where('status', 'pending')->delete();

            return DB::table('profile_corrections')->insertGetId([
                'user_id' => $user->id,
                'field' => $data['field'],
                'current_value' => $data['field'] === 'name' ? $user->name : null,
                'requested_value' => $data['requested_value'],
                'note' => $data['note'] ?? null,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        AdminNotification::create([
            'type' => 'profile_correction',
            'title' => 'Profile correction — '.$user->name,
            'message' => self::CORRECTABLE[$data['field']].': “'.$data['requested_value'].'”',
            'data' => ['correction_id' => $id, 'user_id' => $user->id],
        ]);

        return response()->json(['data' => ['id' => $id, 'status' => 'pending'], 'message' => 'We’ll review it within 2 working days.'], 201);
    }

    /** GET /profile-corrections?status=pending (admin) */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $rows = DB::table('profile_corrections')
            ->join('users', 'users.id', '=', 'profile_corrections.user_id')
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('profile_corrections.status', $status))
            ->orderByDesc('profile_corrections.id')
            ->select('profile_corrections.*', 'users.name as member_name', 'users.email as member_email')
            ->paginate(20);

        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'last_page' => $rows->lastPage()]]);
    }

    /** POST /profile-corrections/{id}/approve | reject (admin) */
    public function review(Request $request, int $id, string $decision): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:255']);
        $row = DB::table('profile_corrections')->where('id', $id)->where('status', 'pending')->first();
        if (!$row || !in_array($decision, ['approve', 'reject'], true)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        DB::transaction(function () use ($row, $decision, $request) {
            if ($decision === 'approve' && $row->field === 'name') {
                User::whereKey($row->user_id)->update(['name' => $row->requested_value]);
            }
            DB::table('profile_corrections')->where('id', $row->id)->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected',
                'review_note' => $request->note,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json(['data' => ['status' => $decision === 'approve' ? 'approved' : 'rejected']]);
    }

    /** Pending corrections for /mobile/profile. */
    public static function pendingFor(User $user): array
    {
        return DB::table('profile_corrections')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->get(['field', 'requested_value', 'created_at'])
            ->map(fn ($r) => ['field' => $r->field, 'label' => self::CORRECTABLE[$r->field] ?? $r->field, 'requested_value' => $r->requested_value])
            ->values()
            ->all();
    }

    private function validateContact(Request $request): array
    {
        $kind = $request->input('kind');
        $data = $request->validate([
            'kind' => 'required|in:email,phone',
            'value' => $kind === 'email'
                ? ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)]
                : ['required', 'string', 'regex:/^(\+2519\d{8}|09\d{8})$/', Rule::unique('users', 'phone')->ignore($request->user()->id)],
        ], [
            'value.unique' => $kind === 'email' ? 'This email is already used by another account.' : 'This phone number is already used by another account.',
            'value.regex' => 'Enter an Ethiopian mobile number, like +251911234412.',
        ]);
        $data['value'] = $kind === 'email' ? strtolower(trim($data['value'])) : preg_replace('/\s+/', '', $data['value']);

        return $data;
    }

    private function subject(User $user, string $kind, string $value): string
    {
        return "user:{$user->id}|{$kind}:{$value}";
    }
}
