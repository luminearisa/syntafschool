<?php

namespace App\Services\Library\Membership;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Library;
use App\Models\LibraryMember;
use App\Models\Student;
use App\Models\User;
use App\Services\Library\NumberingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Keanggotaan perpustakaan.
 *
 * Orang yang sudah ada (siswa/pegawai/wali/pengguna) ditautkan — identitasnya
 * ditarik otomatis dan **tidak diinput ulang** (Rule 11). Anggota umum diisi
 * mandiri dan tidak wajib punya akun pengguna (§37).
 *
 * Satu orang hanya boleh memiliki satu keanggotaan **aktif** per perpustakaan
 * (Rule 7). Evaluasi kedaluwarsa bersifat lunak: status berubah menjadi
 * `expired`, data tidak pernah dihapus (Rule 10).
 */
class MembershipService
{
    public function __construct(protected NumberingService $numbering)
    {
    }

    public function query(Request $request): Builder
    {
        $query = LibraryMember::query()->with([
            'library:id,code,name',
            'membershipType:id,code,name,max_loan,loan_duration_days',
            'student:id,nis,full_name',
            'employee:id,nip,full_name',
            'guardian:id,full_name',
            'user:id,name',
            'cards' => fn ($q) => $q->where('status', 'active'),
        ]);

        foreach ((array) $request->query('filter', []) as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($column, ['library_id', 'membership_type_id', 'status'], true)) {
                $query->where($column, $value);
            }
        }

        if ($link = $request->query('linked_type')) {
            match ($link) {
                'student' => $query->whereNotNull('student_id'),
                'employee' => $query->whereNotNull('employee_id'),
                'guardian' => $query->whereNotNull('guardian_id'),
                'user' => $query->whereNotNull('user_id'),
                'public' => $query->whereNull('student_id')->whereNull('employee_id')
                    ->whereNull('guardian_id')->whereNull('user_id'),
                default => null,
            };
        }

        if ($search = $request->query('search')) {
            $query->where(fn (Builder $q) => $q
                ->where('member_number', 'like', "{$search}%")
                ->orWhere('name', 'like', "{$search}%")
                ->orWhere('email', 'like', "{$search}%")
                ->orWhere('phone', 'like', "{$search}%")
                ->orWhereHas('student', fn (Builder $s) => $s->where('full_name', 'like', "{$search}%"))
                ->orWhereHas('employee', fn (Builder $e) => $e->where('full_name', 'like', "{$search}%"))
                ->orWhereHas('guardian', fn (Builder $g) => $g->where('full_name', 'like', "{$search}%")));
        }

        $sortDir = $request->query('sort_dir') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy('created_at', $sortDir);
    }

    /**
     * Daftarkan keanggotaan baru (workflow §36).
     *
     * @param  array<string, mixed>  $data
     */
    public function register(array $data): LibraryMember
    {
        return DB::transaction(function () use ($data): LibraryMember {
            /** @var Library $library */
            $library = Library::query()->findOrFail($data['library_id']);

            $linked = $this->resolveLinked($data);

            $this->assertSingleActiveMembership($library->id, $linked);

            $member = new LibraryMember();
            $member->fill($this->attributes($data, $linked));
            $member->library_id = $library->id;
            $member->member_number = $data['member_number'] ?? $this->numbering->next($library, 'member');
            $member->registered_at = $data['registered_at'] ?? Carbon::now()->toDateString();
            $member->status = $this->initialStatus($data);
            $member->save();

            return $member->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LibraryMember $member, array $data): LibraryMember
    {
        $attributes = $this->attributes($data, $this->linkedFrom($member), $member);

        // Nomor anggota immutable setelah dipakai (§33).
        unset($attributes['member_number']);

        $member->fill($attributes);
        $member->save();

        return $member->refresh();
    }

    /**
     * Ubah status keanggotaan beserta jejak auditnya.
     */
    public function setStatus(LibraryMember $member, string $status, ?string $reason = null): LibraryMember
    {
        if (! in_array($status, LibraryMember::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Status keanggotaan tidak dikenal.']);
        }

        if ($member->status === $status) {
            return $member;
        }

        // Mengaktifkan kembali harus mematuhi aturan satu keanggotaan aktif.
        if ($status === LibraryMember::STATUS_ACTIVE) {
            $this->assertSingleActiveMembership($member->library_id, $this->linkedFrom($member), $member->id);
        }

        $from = $member->status;
        $member->update(['status' => $status]);

        $event = match ($status) {
            LibraryMember::STATUS_ACTIVE => 'MEMBERSHIP_ACTIVATED',
            LibraryMember::STATUS_SUSPENDED => 'MEMBERSHIP_SUSPENDED',
            default => 'MEMBER_UPDATED',
        };

        activity('library')
            ->event($event)
            ->performedOn($member)
            ->withProperties(['from' => $from, 'to' => $status, 'reason' => $reason] + $this->context())
            ->log("Status keanggotaan: {$from} → {$status}");

        return $member->refresh();
    }

    /**
     * Perpanjang masa berlaku (§35).
     */
    public function renew(LibraryMember $member, string $validUntil, ?string $validFrom = null): LibraryMember
    {
        if ($member->status === LibraryMember::STATUS_BLOCKED) {
            throw ValidationException::withMessages([
                'status' => 'Keanggotaan yang diblokir tidak dapat diperpanjang.',
            ]);
        }

        $until = Carbon::parse($validUntil);

        if ($until->isPast()) {
            throw ValidationException::withMessages([
                'valid_until' => 'Tanggal berakhir perpanjangan harus di masa depan.',
            ]);
        }

        $from = $validFrom !== null ? Carbon::parse($validFrom) : Carbon::now();

        $member->update([
            'valid_from' => $from->toDateString(),
            'valid_until' => $until->toDateString(),
            // Perpanjangan otomatis mengaktifkan kembali (kecuali diblokir).
            'status' => LibraryMember::STATUS_ACTIVE,
        ]);

        activity('library')
            ->event('MEMBERSHIP_RENEWED')
            ->performedOn($member)
            ->withProperties([
                'valid_from' => $member->valid_from?->toDateString(),
                'valid_until' => $member->valid_until?->toDateString(),
            ] + $this->context())
            ->log('Keanggotaan diperpanjang');

        return $member->refresh();
    }

    /**
     * Evaluasi kedaluwarsa: hanya mengubah status, tidak menghapus data.
     *
     * @return int jumlah anggota yang ditandai expired
     */
    public function evaluateExpirations(?int $libraryId = null): int
    {
        $query = LibraryMember::query()
            ->whereIn('status', [LibraryMember::STATUS_ACTIVE, LibraryMember::STATUS_PENDING])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', Carbon::now()->toDateString());

        if ($libraryId !== null) {
            $query->where('library_id', $libraryId);
        }

        $members = $query->get();

        foreach ($members as $member) {
            $member->update(['status' => LibraryMember::STATUS_EXPIRED]);
        }

        return $members->count();
    }

    /**
     * Ringkasan keanggotaan untuk dashboard (§62).
     *
     * @return array<string, int>
     */
    public function summary(?int $libraryId = null): array
    {
        $base = fn () => LibraryMember::query()->when($libraryId !== null, fn (Builder $q) => $q->where('library_id', $libraryId));

        return [
            'total' => $base()->count(),
            'active' => $base()->where('status', LibraryMember::STATUS_ACTIVE)->count(),
            'expired' => $base()->where('status', LibraryMember::STATUS_EXPIRED)->count(),
            'pending' => $base()->where('status', LibraryMember::STATUS_PENDING)->count(),
            'suspended' => $base()->where('status', LibraryMember::STATUS_SUSPENDED)->count(),
            // Kedaluwarsa yang belum tersinkron ke status.
            'due_expired' => $base()->where('status', LibraryMember::STATUS_ACTIVE)
                ->whereNotNull('valid_until')
                ->whereDate('valid_until', '<', Carbon::now()->toDateString())
                ->count(),
        ];
    }

    public function delete(LibraryMember $member): void
    {
        if ($member->cards()->exists()) {
            throw ValidationException::withMessages([
                'member' => 'Anggota memiliki kartu terbitan. Nonaktifkan anggota sebagai gantinya.',
            ]);
        }

        // Riwayat transaksi menyusul pada Fase 4 (Rule 13).
        if (Schema::hasTable('library_loans') && DB::table('library_loans')->where('member_id', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'Anggota memiliki riwayat peminjaman dan tidak dapat dihapus.',
            ]);
        }

        $member->delete();
    }

    /**
     * Tarik identitas dari entitas tertaut (§55).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function resolveLinked(array $data): ?array
    {
        if (! empty($data['student_id'])) {
            $student = Student::query()->findOrFail($data['student_id']);

            return [
                'student_id' => $student->id,
                'user_id' => $student->user_id ?? null,
                'name' => $student->full_name,
                'gender_id' => $student->gender_id,
                'birth_date' => $student->birth_date,
                'phone' => $student->phone,
                'email' => $student->email,
                'address' => $student->address,
                'photo_path' => $student->photo_path,
            ];
        }

        if (! empty($data['employee_id'])) {
            $employee = Employee::query()->findOrFail($data['employee_id']);

            return [
                'employee_id' => $employee->id,
                'user_id' => $employee->user_id ?? null,
                'name' => $employee->full_name,
                'gender_id' => $employee->gender_id,
                'birth_date' => $employee->birth_date,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'address' => $employee->address,
                'photo_path' => $employee->photo_path,
            ];
        }

        if (! empty($data['guardian_id'])) {
            $guardian = Guardian::query()->findOrFail($data['guardian_id']);

            return [
                'guardian_id' => $guardian->id,
                'user_id' => $guardian->user_id ?? null,
                'name' => $guardian->full_name,
                'gender_id' => $guardian->gender_id,
                'phone' => $guardian->phone,
                'email' => $guardian->email,
                'address' => $guardian->address,
            ];
        }

        if (! empty($data['user_id'])) {
            $user = User::query()->findOrFail($data['user_id']);

            return ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone];
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function linkedFrom(LibraryMember $member): ?array
    {
        return [
            'student_id' => $member->student_id,
            'employee_id' => $member->employee_id,
            'guardian_id' => $member->guardian_id,
            'user_id' => $member->user_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $linked
     * @return array<string, mixed>
     */
    protected function attributes(array $data, ?array $linked, ?LibraryMember $existing = null): array
    {
        $attributes = array_intersect_key($data, array_flip([
            'membership_type_id', 'valid_from', 'valid_until', 'notes',
            'gender_id', 'birth_date', 'identity_number',
        ]));

        // Identitas mandiri hanya dipakai bila tidak ada entitas tertaut,
        // dan tidak menimpa data yang sudah terisi saat pembaruan.
        foreach (['name', 'phone', 'email', 'address', 'photo_path'] as $field) {
            if ($linked !== null && filled($linked[$field] ?? null)) {
                $attributes[$field] = $existing?->{$field} ?: $linked[$field];

                continue;
            }

            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if ($linked !== null) {
            foreach (['user_id', 'student_id', 'employee_id', 'guardian_id'] as $field) {
                $attributes[$field] = $linked[$field] ?? null;
            }
        }

        if (isset($data['status'])) {
            $attributes['status'] = $data['status'];
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function initialStatus(array $data): string
    {
        if (isset($data['status'])) {
            return (string) $data['status'];
        }

        $from = $data['valid_from'] ?? null;

        if ($from !== null && Carbon::parse($from)->isFuture()) {
            return LibraryMember::STATUS_PENDING;
        }

        $until = $data['valid_until'] ?? null;

        if ($until !== null && Carbon::parse($until)->isPast()) {
            return LibraryMember::STATUS_EXPIRED;
        }

        return LibraryMember::STATUS_ACTIVE;
    }

    /**
     * Rule 7: satu orang hanya boleh punya satu keanggotaan aktif per library.
     *
     * @param  array<string, mixed>|null  $linked
     */
    protected function assertSingleActiveMembership(int $libraryId, ?array $linked, ?int $ignoreId = null): void
    {
        if ($linked === null) {
            return;
        }

        // Kumpulkan tautan yang benar-benar terisi lebih dulu agar tidak
        // membentuk klausa `where` kosong.
        $links = array_filter([
            'student_id' => $linked['student_id'] ?? null,
            'employee_id' => $linked['employee_id'] ?? null,
            'guardian_id' => $linked['guardian_id'] ?? null,
            'user_id' => $linked['user_id'] ?? null,
        ], fn ($value) => ! empty($value));

        if ($links === []) {
            return;
        }

        $query = LibraryMember::query()
            ->where('library_id', $libraryId)
            ->whereIn('status', LibraryMember::ACTIVE_STATUSES)
            ->where(function (Builder $q) use ($links): void {
                foreach ($links as $field => $value) {
                    $q->orWhere($field, $value);
                }
            });

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'member' => 'Orang ini sudah memiliki keanggotaan aktif pada perpustakaan tersebut.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function context(): array
    {
        if (app()->runningInConsole()) {
            return [];
        }

        return [
            'ip' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
        ];
    }
}