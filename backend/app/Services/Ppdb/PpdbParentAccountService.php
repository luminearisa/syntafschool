<?php

namespace App\Services\Ppdb;

use App\Models\Guardian;
use App\Models\PpdbApplicant;
use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * Akun orang tua PPDB & calon (anak) miliknya.
 *
 * Identitas orang tua TIDAK disalin ke tabel PPDB: `users` menyimpan nama,
 * email, telepon; `guardians` menyimpan detail wali. `ppdb_parent_accounts`
 * hanya menautkan keduanya plus status akun — sehingga satu akun bisa punya
 * banyak anak tanpa duplikasi data pribadi.
 */
class PpdbParentAccountService
{
    /**
     * Peran untuk pendaftar mandiri. `wali-murid` sudah ada di
     * RolePermissionSeeder (peran orang tua/wali pada sistem inti). Bila role
     * tersebut belum di-seed, akun tetap dibuat tanpa role.
     */
    public const PARENT_ROLE = 'wali-murid';

    /**
     * Tipe notifikasi untuk pemblokiran akun. `PpdbActivityNotification` hanya
     * menyediakan konstanta untuk alur utama, dan kelas itu bagian dari kontrak
     * yang tidak boleh diubah, jadi tipe bebas dipakai di sini.
     */
    public const NOTIFICATION_ACCOUNT_BLOCKED = 'account_blocked';

    public const STATUS_LABELS = [
        PpdbParentAccount::STATUS_PENDING => 'Menunggu verifikasi',
        PpdbParentAccount::STATUS_ACTIVE => 'Aktif',
        PpdbParentAccount::STATUS_INACTIVE => 'Nonaktif',
        PpdbParentAccount::STATUS_BLOCKED => 'Diblokir',
    ];

    /** Kolom identitas orang tua yang tinggal di tabel `guardians`. */
    private const GUARDIAN_KEYS = [
        'gender_id', 'religion_id', 'address', 'occupation_id',
        'education_background_id', 'income_range_id',
    ];

    public function __construct(private readonly PpdbNotificationService $notifications)
    {
    }

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? Str::of($status)->replace('_', ' ')->title()->toString();
    }

    /** @return list<array{value: string, label: string}> */
    public static function statusOptions(): array
    {
        return array_map(
            static fn (string $status): array => ['value' => $status, 'label' => self::statusLabel($status)],
            PpdbParentAccount::STATUSES,
        );
    }

    // =====================================================================
    // Pendaftaran mandiri & profil
    // =====================================================================

    /**
     * Buat akun pendaftar baru: users + guardians + ppdb_parent_accounts dalam
     * satu transaksi, lalu kirim notifikasi selamat datang.
     *
     * Keputusan: pendaftar mandiri LANGSUNG `active` (bukan
     * pending_verification) agar dapat langsung mengisi formulir tanpa menunggu
     * panitia; status ini tetap dibaca admin dan bisa diblokir kapan pun.
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): PpdbParentAccount
    {
        $account = DB::transaction(function () use ($data): PpdbParentAccount {
            $guardian = Guardian::create([
                'full_name' => (string) $data['name'],
                'gender_id' => $data['gender_id'] ?? null,
                'religion_id' => $data['religion_id'] ?? null,
                'address' => $data['address'] ?? null,
                'phone' => (string) $data['phone'],
                'email' => (string) $data['email'],
                'occupation_id' => $data['occupation_id'] ?? null,
                'education_background_id' => $data['education_background_id'] ?? null,
                'income_range_id' => $data['income_range_id'] ?? null,
            ]);

            $user = User::create([
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'phone' => (string) $data['phone'],
                'password' => Hash::make((string) $data['password']),
                'is_active' => true,
            ]);

            // `users.userable` dipakai khusus Student/Employee (lihat model
            // User); akun orang tua ditautkan lewat guardian_id + user_id.
            if (Role::query()->where('name', self::PARENT_ROLE)->exists()) {
                $user->syncRoles([self::PARENT_ROLE]);
            }

            $account = PpdbParentAccount::create([
                'user_id' => $user->id,
                'guardian_id' => $guardian->id,
                'account_status' => PpdbParentAccount::STATUS_ACTIVE,
                'verified_at' => now(),
                'last_activity_at' => now(),
            ]);

            return $account->load(['user', 'guardian']);
        });

        $this->notifications->toParent(
            $account,
            PpdbActivityNotification::TYPE_ACCOUNT_CREATED,
            'Selamat datang di PPDB',
            sprintf(
                'Akun %s berhasil dibuat. Silakan lengkapi profil, tambahkan data anak, lalu buat pendaftaran.',
                (string) $account->user?->name,
            ),
            ['parent_account_id' => $account->id, 'account_status' => $account->account_status],
        );

        return $account;
    }

    /**
     * Perbarui profil orang tua: nama/email/telepon → `users`, detail identitas
     * → `guardians`. Tidak ada kolom identitas di `ppdb_parent_accounts`.
     *
     * @param  array<string, mixed>  $data
     * @return array{user: User, guardian: Guardian}
     */
    public function updateProfile(PpdbParentAccount $account, array $data): array
    {
        $user = $account->user ?? User::query()->findOrFail($account->user_id);

        return DB::transaction(function () use ($account, $user, $data): array {
            $userChanges = [];

            foreach (['name' => 'name', 'email' => 'email', 'phone' => 'phone'] as $column => $key) {
                if (array_key_exists($key, $data)) {
                    $userChanges[$column] = $data[$key];
                }
            }

            if ($userChanges !== []) {
                $user->forceFill($userChanges)->save();
            }

            $guardianChanges = array_fill_keys(self::GUARDIAN_KEYS, null);

            foreach (self::GUARDIAN_KEYS as $column) {
                if (array_key_exists($column, $data)) {
                    $guardianChanges[$column] = $data[$column];
                }
            }

            if (array_key_exists('name', $data)) {
                $guardianChanges['full_name'] = $data['name'];
            }

            if (array_key_exists('phone', $data)) {
                $guardianChanges['phone'] = $data['phone'];
            }

            if (array_key_exists('email', $data)) {
                $guardianChanges['email'] = $data['email'];
            }

            $guardian = $account->guardian;

            if ($guardian === null) {
                $guardian = Guardian::create($guardianChanges + [
                    'full_name' => (string) $user->name,
                    'phone' => (string) $user->phone,
                    'email' => (string) $user->email,
                ]);
            } else {
                $guardian->forceFill($guardianChanges)->save();
            }

            if ($account->guardian_id !== $guardian->id) {
                $account->forceFill(['guardian_id' => $guardian->id])->save();
            }

            $account->unsetRelation('guardian')->unsetRelation('user');

            return ['user' => $user->refresh(), 'guardian' => $guardian->refresh()];
        });
    }

    // =====================================================================
    // Resolusi akun portal (pembatas data)
    // =====================================================================

    public function accountFor(User $user): ?PpdbParentAccount
    {
        return PpdbParentAccount::query()
            ->with(['user', 'guardian'])
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Akun pemilik sesi. 403 bila pengguna tidak punya akun PPDB. Seluruh query
     * portal selanjutnya dibatasi pada `parent_account_id` akun ini.
     */
    public function currentAccount(User $user): PpdbParentAccount
    {
        $account = $this->accountFor($user);

        abort_if(
            $account === null,
            403,
            'Akun PPDB Anda belum terdaftar. Silakan buat akun melalui halaman pendaftaran PPDB.',
        );

        return $account;
    }

    /**
     * Sama seperti currentAccount() tetapi untuk aksi tulis: akun nonaktif /
     * diblokir / menunggu verifikasi hanya boleh membaca (read-only).
     */
    public function transactableAccount(User $user): PpdbParentAccount
    {
        $account = $this->currentAccount($user);

        abort_unless($account->canTransact(), 403, sprintf(
            'Akun PPDB Anda berstatus %s sehingga tidak dapat mengubah data. Hubungi panitia penerimaan.',
            self::statusLabel((string) $account->account_status),
        ));

        return $account;
    }

    /** Catat aktivitas terakhir (maksimal satu kali per 5 menit). */
    public function touchActivity(PpdbParentAccount $account): void
    {
        PpdbParentAccount::query()
            ->whereKey($account->id)
            ->where(function (Builder $query): void {
                $query->whereNull('last_activity_at')->orWhere('last_activity_at', '<', now()->subMinutes(5));
            })
            ->update(['last_activity_at' => now(), 'updated_at' => now()]);
    }

    // =====================================================================
    // Kelola akun (admin)
    // =====================================================================

    public function adminQuery(Request $request): Builder
    {
        $like = '%'.$request->string('search')->toString().'%';

        return PpdbParentAccount::query()
            ->with(['user', 'guardian'])
            ->withCount(['applicants', 'registrations'])
            ->when($request->filled('account_status'), fn (Builder $q) => $q->where('account_status', (string) $request->input('account_status')))
            ->when($request->filled('verified'), fn (Builder $q) => $request->boolean('verified')
                ? $q->whereNotNull('verified_at')
                : $q->whereNull('verified_at'))
            ->when($request->filled('has_registration'), fn (Builder $q) => $request->boolean('has_registration')
                ? $q->has('registrations')
                : $q->doesntHave('registrations'))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', (string) $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', (string) $request->input('date_to')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where(function (Builder $scope) use ($like): void {
                $scope->whereHas('user', fn (Builder $u) => $u
                    ->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like))
                    ->orWhereHas('guardian', fn (Builder $g) => $g
                        ->where('full_name', 'like', $like)
                        ->orWhere('phone', 'like', $like)
                        ->orWhere('email', 'like', $like))
                    ->orWhereHas('applicants', fn (Builder $a) => $a
                        ->where('full_name', 'like', $like)
                        ->orWhere('nik', 'like', $like)
                        ->orWhere('nisn', 'like', $like))
                    ->orWhereHas('registrations', fn (Builder $r) => $r
                        ->where('registration_number', 'like', $like));
            }))
            ->latest('id');
    }

    /**
     * Ubah status akun. Pemblokiran langsung berdampak: `canTransact()` menjadi
     * false sehingga semua endpoint tulis portal menolak (read-only).
     */
    public function updateStatus(PpdbParentAccount $account, string $status, ?string $note, ?User $actor): PpdbParentAccount
    {
        $changes = ['account_status' => $status];

        if ($note !== null) {
            $changes['notes'] = $note;
        }

        $previous = (string) $account->account_status;

        if ($status === PpdbParentAccount::STATUS_ACTIVE && $account->verified_at === null) {
            $changes['verified_at'] = now();
        }

        $account->forceFill($changes)->save();

        activity('ppdb')
            ->event('PPDB_ACCOUNT_STATUS_CHANGED')
            ->performedOn($account)
            ->causedBy($actor)
            ->withProperties([
                'account_status' => $status,
                'previous_status' => $previous,
                'note' => $note,
            ])
            ->log(sprintf(
                'Status akun orang tua diubah menjadi %s oleh %s.',
                self::statusLabel($status),
                (string) $actor?->name,
            ));

        if ($status === PpdbParentAccount::STATUS_BLOCKED) {
            $this->notifications->toParent(
                $account,
                self::NOTIFICATION_ACCOUNT_BLOCKED,
                'Akun PPDB diblokir',
                sprintf(
                    'Akun PPDB atas nama %s diblokir oleh panitia%s. Anda hanya dapat melihat data.',
                    (string) $account->user?->name,
                    $note !== null && $note !== '' ? '. Alasan: '.$note : '',
                ),
                ['parent_account_id' => $account->id, 'account_status' => $status],
            );
        }

        return $account->refresh();
    }

    /**
     * Reset password: kembalikan password sementara SEKALI (tidak disimpan dalam
     * bentuk teks nyata, akun tidak pernah dihapus).
     */
    public function resetPassword(PpdbParentAccount $account, ?User $actor): string
    {
        $password = $this->temporaryPassword();
        $user = $account->user ?? User::query()->findOrFail($account->user_id);

        $user->forceFill(['password' => Hash::make($password)])->save();

        activity('ppdb')
            ->event('PPDB_ACCOUNT_PASSWORD_RESET')
            ->performedOn($account)
            ->causedBy($actor)
            ->withProperties(['email' => $user->email])
            ->log(sprintf(
                'Password akun %s disetel ulang oleh %s.',
                (string) $user->email,
                (string) $actor?->name,
            ));

        return $password;
    }

    /**
     * Aktivitas akun: pendaftaran terbaru + jejak audit terakhir.
     *
     * @return array{recent_registrations: list<array<string, mixed>>, activities: list<array<string, mixed>>}
     */
    public function activity(PpdbParentAccount $account, int $activityLimit = 5): array
    {
        $recent = $account->registrations()
            ->with(['applicant', 'program', 'period'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(static fn (PpdbRegistration $registration): array => [
                'id' => $registration->id,
                'registration_number' => $registration->registration_number,
                'status' => $registration->status,
                'status_label' => PpdbRegistrationQueryService::statusLabel((string) $registration->status),
                'payment_status' => $registration->payment_status,
                'payment_status_label' => PpdbRegistrationQueryService::paymentLabel((string) $registration->payment_status),
                'document_status' => $registration->document_status,
                'document_status_label' => PpdbRegistrationQueryService::documentLabel((string) $registration->document_status),
                'applicant_id' => $registration->applicant_id,
                'applicant_name' => $registration->applicant?->full_name,
                'program' => $registration->program?->name,
                'period' => $registration->period?->name,
                'submitted_at' => $registration->submitted_at?->toIso8601String(),
                'created_at' => $registration->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'recent_registrations' => $recent,
            'activities' => $this->auditTrail($account, $activityLimit),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditTrail(PpdbParentAccount $account, int $limit): array
    {
        $applicantIds = $account->applicants()->pluck('id')->all();
        $registrationIds = $account->registrations()->pluck('id')->all();

        $activities = Activity::query()
            ->where('log_name', 'ppdb')
            ->where(function (Builder $query) use ($account, $applicantIds, $registrationIds): void {
                $query->where(fn (Builder $q) => $q
                    ->where('subject_type', $account->getMorphClass())
                    ->where('subject_id', $account->id));

                if ($applicantIds !== []) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', (new PpdbApplicant)->getMorphClass())
                        ->whereIn('subject_id', $applicantIds));
                }

                if ($registrationIds !== []) {
                    $query->orWhere(fn (Builder $q) => $q
                        ->where('subject_type', (new PpdbRegistration)->getMorphClass())
                        ->whereIn('subject_id', $registrationIds));
                }
            })
            ->latest('id')
            ->limit($limit)
            ->get();

        return $activities->map(static fn (Activity $activity): array => [
            'id' => $activity->id,
            'event' => $activity->event,
            'description' => $activity->description,
            'subject_type' => class_basename((string) $activity->subject_type),
            'subject_id' => $activity->subject_id,
            'causer_name' => $activity->causer?->name,
            'changed_fields' => array_keys((array) $activity->properties),
            'created_at' => $activity->created_at?->toIso8601String(),
        ])->all();
    }

    // =====================================================================
    // Calon (anak) milik akun
    // =====================================================================

    /** @return Builder<PpdbApplicant> */
    public function applicants(PpdbParentAccount $account, Request $request): Builder
    {
        return PpdbApplicant::query()
            ->with(['gender', 'religion', 'targetProgram', 'registrations.program', 'registrations.period'])
            ->where('parent_account_id', $account->id)
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where('full_name', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('id');
    }

    /**
     * Daftar calon untuk panitia.
     *
     * Filter: search (nama/NIK/NISN/nomor pendaftaran), periode, program,
     * status calon, status pendaftaran.
     *
     * @return Builder<PpdbApplicant>
     */
    public function applicantQuery(Request $request): Builder
    {
        $like = '%'.$request->string('search')->toString().'%';

        return PpdbApplicant::query()
            ->with(['parentAccount.user', 'guardian', 'gender', 'religion', 'targetProgram', 'registrations.program', 'registrations.period'])
            ->when($request->filled('parent_account_id'), fn (Builder $q) => $q->where('parent_account_id', (int) $request->input('parent_account_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', (string) $request->input('status')))
            ->when($request->filled('gender_id'), fn (Builder $q) => $q->where('gender_id', (int) $request->input('gender_id')))
            ->when($request->filled('target_program_id'), fn (Builder $q) => $q->where('target_program_id', (int) $request->input('target_program_id')))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('birth_date', '>=', (string) $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('birth_date', '<=', (string) $request->input('date_to')))
            ->when($request->filled('period_id'), fn (Builder $q) => $q->whereHas(
                'registrations',
                fn (Builder $r) => $r->where('period_id', (int) $request->input('period_id')),
            ))
            ->when($request->filled('program_id'), fn (Builder $q) => $q->whereHas(
                'registrations',
                fn (Builder $r) => $r->where('program_id', (int) $request->input('program_id')),
            ))
            ->when($request->filled('registration_status'), fn (Builder $q) => $q->whereHas(
                'registrations',
                fn (Builder $r) => $r->where('status', (string) $request->input('registration_status')),
            ))
            ->when($request->filled('search'), fn (Builder $q) => $q->where(function (Builder $scope) use ($like): void {
                $scope->where('full_name', 'like', $like)
                    ->orWhere('nickname', 'like', $like)
                    ->orWhere('nik', 'like', $like)
                    ->orWhere('nisn', 'like', $like)
                    ->orWhereHas('registrations', fn (Builder $r) => $r->where('registration_number', 'like', $like));
            }))
            ->latest('id');
    }

    /**
     * Buat calon di bawah akun pemanggil — satu akun orang tua boleh punya
     * banyak anak.
     *
     * @param  array<string, mixed>  $data
     */
    public function createApplicant(PpdbParentAccount $account, array $data): PpdbApplicant
    {
        unset($data['parent_account_id'], $data['guardian_id']);

        return PpdbApplicant::create($data + [
            'parent_account_id' => $account->id,
            'guardian_id' => $account->guardian_id,
        ]);
    }

    /**
     * Pemilik akun tidak pernah dipindahkan lewat endpoint ini.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateApplicant(PpdbApplicant $applicant, array $data): PpdbApplicant
    {
        unset($data['parent_account_id']);

        $applicant->forceFill($data)->save();

        return $applicant->refresh();
    }

    /**
     * Hapus (soft) calon selama belum ada pendaftaran yang melewati status
     * draft. Draft ikut disembunyikan agar tidak menggantung.
     *
     * @throws ValidationException bila ada pendaftaran yang sudah dikirim
     */
    public function removeApplicant(PpdbApplicant $applicant): void
    {
        $applicant->loadMissing('registrations');

        $sent = $applicant->registrations
            ->reject(fn (PpdbRegistration $registration): bool => $registration->status === PpdbRegistration::STATUS_DRAFT);

        if ($sent->isNotEmpty()) {
            throw ValidationException::withMessages([
                'applicant' => sprintf(
                    'Calon %s tidak dapat dihapus karena sudah punya pendaftaran yang dikirim (%s). '
                    .'Ajukan pembatalan pendaftaran ke panitia terlebih dahulu.',
                    (string) $applicant->full_name,
                    (string) $sent->pluck('registration_number')->implode(', '),
                ),
            ]);
        }

        DB::transaction(function () use ($applicant): void {
            foreach ($applicant->registrations as $registration) {
                $registration->delete();
            }

            $applicant->delete();
        });
    }

    // =====================================================================
    // Penjaga kepemilikan data portal
    // =====================================================================

    /**
     * Pendaftaran harus milik akun pemanggil. Akses milik orang lain dibalas
     * 404 agar tidak membocorkan keberadaan data.
     */
    public function ownRegistration(User $user, PpdbRegistration $registration, bool $transacting = false): PpdbRegistration
    {
        $account = $transacting ? $this->transactableAccount($user) : $this->currentAccount($user);

        abort_if(
            (int) $registration->parent_account_id !== (int) $account->id,
            404,
            'Pendaftaran tidak ditemukan pada akun Anda.',
        );

        $registration->loadMissing(['applicant', 'program', 'wave', 'period']);

        return $registration;
    }

    /** Sama seperti ownRegistration(), untuk data calon (anak). */
    public function ownApplicant(User $user, PpdbApplicant $applicant, bool $transacting = false): PpdbApplicant
    {
        $account = $transacting ? $this->transactableAccount($user) : $this->currentAccount($user);

        abort_if(
            (int) $applicant->parent_account_id !== (int) $account->id,
            404,
            'Data calon tidak ditemukan pada akun Anda.',
        );

        return $applicant;
    }

    private function temporaryPassword(): string
    {
        return Str::random(2).Str::lower(Str::random(7)).random_int(100, 999);
    }
}
