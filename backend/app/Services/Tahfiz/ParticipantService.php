<?php

namespace App\Services\Tahfiz;

use App\Models\Employee;
use App\Models\Guardian;
use App\Models\Student;
use App\Models\TahfizEnrollment;
use App\Models\TahfizHalaqah;
use App\Models\TahfizParticipant;
use App\Models\TahfizRegistrationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pintu masuk tunggal untuk pembuatan peserta dan transisi enrollment.
 *
 * Semua aturan bisnis peserta berada di sini agar tidak tersebar di banyak
 * controller: penautan entitas induk, pencegahan duplikat peserta, transisi
 * status enrollment yang sah, dan penempatan halaqah (termasuk pengecekan
 * kapasitas).
 */
class ParticipantService
{
    /**
     * Buat peserta dari entitas induk atau data mandiri.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $profile
     */
    public function createParticipant(array $data, ?array $profile = null): TahfizParticipant
    {
        $type = $data['participant_type'];
        $entityColumn = $type.'_id';

        // Hanya kolom peserta yang dikenal yang boleh ikut masuk.
        $attributes = collect($data)
            ->only([
                'participant_type', 'user_id', 'student_id', 'employee_id', 'guardian_id',
                'name', 'gender', 'birth_date', 'phone', 'email', 'address', 'notes', 'status',
            ])
            ->filter()
            ->toArray();

        // Ambil nama & identitas dari entitas induk bila ada.
        $entity = $this->resolveEntity($type, $data[$entityColumn] ?? null);
        if ($entity !== null) {
            $attributes['name'] ??= $entity->full_name;
            $attributes['gender'] ??= $this->entityGender($entity);
            $attributes['birth_date'] ??= $entity->birth_date;
            $attributes['phone'] ??= $entity->phone;
            $attributes['email'] ??= $entity->email;
            $attributes['address'] ??= $entity->address;
        }

        return DB::transaction(function () use ($attributes, $profile) {
            $participant = TahfizParticipant::create($attributes);

            if (! empty($profile)) {
                $participant->profile()->create($profile);
            }

            return $participant->refresh()->load('profile');
        });
    }

    /**
     * Entitas induk yang dirujuk peserta.
     */
    private function resolveEntity(string $type, ?int $id): Student|Employee|Guardian|null
    {
        if ($id === null) {
            return null;
        }

        return match ($type) {
            'student' => Student::find($id),
            'employee' => Employee::find($id),
            'guardian' => Guardian::find($id),
            default => null,
        };
    }

    private function entityGender(Student|Employee|Guardian $entity): ?string
    {
        // gender_id merujuk ke tabel referensi; konversi kode ke enum peserta.
        $code = $entity->gender?->code ?? $entity->gender?->name;

        return match (strtolower((string) $code)) {
            'l', 'm', 'male', 'laki-laki' => 'male',
            'p', 'f', 'female', 'perempuan' => 'female',
            default => null,
        };
    }

    /**
     * Setujui pendaftaran mandiri: buat peserta + enrollment status registered.
     */
    public function approveRegistration(TahfizRegistrationRequest $request, int $userId): TahfizParticipant
    {
        if ($request->status !== TahfizRegistrationRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Pendaftaran ini sudah diproses.',
            ]);
        }

        return DB::transaction(function () use ($request, $userId) {
            $participant = $this->createParticipant([
                'participant_type' => TahfizParticipant::TYPE_PUBLIC,
                'name' => $request->name,
                'gender' => $request->gender,
                'birth_date' => $request->birth_date,
                'phone' => $request->phone,
                'email' => $request->email,
                'address' => $request->address,
                'status' => TahfizParticipant::STATUS_ACTIVE,
            ], [
                'experience_level' => $request->experience_level,
                'last_memorized' => $request->last_memorized,
                'reading_ability' => $request->reading_ability,
                'memorization_target' => $request->memorization_target,
                'schedule_preference' => $request->schedule_preference,
            ]);

            $request->update([
                'status' => TahfizRegistrationRequest::STATUS_APPROVED,
                'participant_id' => $participant->id,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]);

            return $participant;
        });
    }

    /**
     * Tolak pendaftaran mandiri.
     */
    public function rejectRegistration(TahfizRegistrationRequest $request, int $userId, string $note): void
    {
        if ($request->status !== TahfizRegistrationRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Pendaftaran ini sudah diproses.',
            ]);
        }

        $request->update([
            'status' => TahfizRegistrationRequest::STATUS_REJECTED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }

    /**
     * Transisi status enrollment dengan validasi urutan yang sah.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transition(TahfizEnrollment $enrollment, array $payload, int $userId): TahfizEnrollment
    {
        $target = $payload['status'];

        $this->assertTransitionAllowed($enrollment->status, $target);

        if (in_array($target, [
            TahfizEnrollment::STATUS_SUSPENDED,
            TahfizEnrollment::STATUS_WITHDRAWN,
            TahfizEnrollment::STATUS_REJECTED,
            TahfizEnrollment::STATUS_COMPLETED,
        ], true) && blank($payload['reason'] ?? null)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan wajib diisi untuk perubahan ke status ini.',
            ]);
        }

        $halaqahId = $payload['halaqah_id'] ?? $enrollment->halaqah_id;

        // Penempatan halaqah memeriksa kapasitas & status aktif.
        if ($target === TahfizEnrollment::STATUS_ACTIVE && $halaqahId !== null) {
            $this->assertHalaqahAccepts($halaqahId, $enrollment->participant_id, $enrollment->halaqah_id);
        }

        return DB::transaction(function () use ($enrollment, $payload, $target, $halaqahId, $userId) {
            $updates = [
                'status' => $target,
                'level_id' => $payload['level_id'] ?? $enrollment->level_id,
                'halaqah_id' => $halaqahId,
                'notes' => $payload['reason'] ?? $payload['completion_note'] ?? $enrollment->notes,
            ];

            if ($target === TahfizEnrollment::STATUS_ACTIVE) {
                $updates['approved_by'] = $userId;
                $updates['approved_at'] = now();
                $updates['enrolled_at'] = $enrollment->enrolled_at ?? now()->toDateString();
            }

            if ($target === TahfizEnrollment::STATUS_COMPLETED) {
                $updates['completed_at'] = now()->toDateString();
            }

            $enrollment->update($updates);
            $this->syncHalaqahMembership($enrollment);

            return $enrollment->refresh();
        });
    }

    /**
     * Transisi status yang diizinkan.
     *
     * Alur: draft → registered → assessment → approved → active
     *                                   ↓ rejected
     *       active → suspended → active | completed | withdrawn
     */
    protected function assertTransitionAllowed(string $from, string $to): void
    {
        $allowed = [
            'draft' => ['registered', 'rejected'],
            'registered' => ['assessment', 'approved', 'active', 'rejected', 'withdrawn'],
            'assessment' => ['approved', 'registered', 'rejected', 'withdrawn'],
            'approved' => ['active', 'rejected', 'withdrawn'],
            'active' => ['suspended', 'completed', 'withdrawn'],
            'suspended' => ['active', 'withdrawn', 'completed'],
            // Status akhir tidak bisa berubah lagi.
        ];

        if ($from === $to) {
            throw ValidationException::withMessages([
                'status' => 'Enrollment sudah berada di status ini.',
            ]);
        }

        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => "Perubahan status dari {$from} ke {$to} tidak diizinkan.",
            ]);
        }
    }

    /**
     * Pastikan halaqah aktif dan masih ada kapasitas.
     */
    protected function assertHalaqahAccepts(int $halaqahId, int $participantId, ?int $currentHalaqahId): void
    {
        $halaqah = TahfizHalaqah::query()->whereKey($halaqahId)->firstOrFail();

        if ($halaqah->status !== TahfizHalaqah::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'halaqah_id' => 'Halaqah ini tidak aktif.',
            ]);
        }

        // Pindah halaqah: anggota lama dilepas dulu, jadi jangan dihitung dua kali.
        if ($currentHalaqahId !== null && $currentHalaqahId !== $halaqahId) {
            $this->detachHalaqahMembership($currentHalaqahId, $participantId);
        }

        if (! $halaqah->hasCapacity()) {
            throw ValidationException::withMessages([
                'halaqah_id' => "Halaqah {$halaqah->name} sudah penuh (kapasitas {$halaqah->capacity}).",
            ]);
        }
    }

    /**
     * Sinkronkan keanggotaan halaqah sesuai enrollment.
     */
    protected function syncHalaqahMembership(TahfizEnrollment $enrollment): void
    {
        // Lepas dari halaqah lama bila dipindah/keluar.
        if ($enrollment->wasChanged('halaqah_id')) {
            $original = $enrollment->getOriginal('halaqah_id');
            if ($original !== null) {
                $this->detachHalaqahMembership($original, $enrollment->participant_id);
            }
        }

        $isActive = in_array($enrollment->status, [
            TahfizEnrollment::STATUS_ACTIVE,
            TahfizEnrollment::STATUS_APPROVED,
        ], true);

        if ($enrollment->halaqah_id !== null && $isActive) {
            $enrollment->halaqah->members()->updateOrCreate(
                ['halaqah_id' => $enrollment->halaqah_id, 'participant_id' => $enrollment->participant_id],
                [
                    'enrollment_id' => $enrollment->id,
                    'joined_at' => now()->toDateString(),
                    'status' => 'active',
                ]
            );
        } elseif ($enrollment->halaqah_id !== null) {
            // Non-aktif: tandai tidak aktif, jangan langsung hapus (audit).
            $enrollment->halaqah->members()
                ->where('participant_id', $enrollment->participant_id)
                ->where('status', 'active')
                ->update(['status' => 'inactive', 'left_at' => now()->toDateString()]);
        }
    }

    protected function detachHalaqahMembership(int $halaqahId, int $participantId): void
    {
        DB::table('tahfiz_halaqah_members')
            ->where('halaqah_id', $halaqahId)
            ->where('participant_id', $participantId)
            ->update(['status' => 'moved', 'left_at' => now()->toDateString()]);
    }
}
