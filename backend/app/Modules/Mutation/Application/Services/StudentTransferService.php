<?php

namespace App\Modules\Mutation\Application\Services;

use App\Models\ClassStudent;
use App\Models\DocumentSequence;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Modules\Mutation\Models\StudentTransfer;
use App\Modules\Mutation\Models\StudentTransferHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin mutasi siswa.
 *
 * Permohonan disimpan apa adanya (tanpa state turunan) dan efeknya baru
 * diterapkan saat `complete()`. Seluruh penerapan efek berlangsung dalam satu
 * transaksi dan bersifat idempoten: menyelesaikan permohonan yang sama dua kali
 * tidak menggandakan keanggotaan rombel, riwayat, atau penomoran dokumen.
 */
class StudentTransferService
{
    /**
     * Kolom yang boleh diisi dari masukan pengguna.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'student_id', 'type', 'academic_year_id', 'from_class_id', 'to_class_id',
        'from_school_name', 'to_school_name', 'request_date', 'effective_date',
        'reason', 'document_number', 'notes',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId): StudentTransfer
    {
        $this->assertTypeRules($data);

        return StudentTransfer::create([
            ...$this->only($data),
            'status' => StudentTransfer::STATUS_DRAFT,
            'requested_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StudentTransfer $transfer, array $data): StudentTransfer
    {
        $this->assertStatus($transfer, StudentTransfer::STATUS_DRAFT);

        $merged = array_merge($transfer->only(self::FIELDS), $data);
        $this->assertTypeRules($merged);

        $transfer->fill($this->only($data))->save();

        return $transfer->refresh();
    }

    public function submit(StudentTransfer $transfer, ?int $actorId): StudentTransfer
    {
        $this->assertStatus($transfer, StudentTransfer::STATUS_DRAFT);

        $from = $transfer->status;
        $transfer->status = StudentTransfer::STATUS_SUBMITTED;
        $transfer->save();

        $this->recordHistory($transfer, $from, StudentTransfer::STATUS_SUBMITTED, $actorId);

        return $transfer->refresh();
    }

    public function approve(StudentTransfer $transfer, ?int $actorId): StudentTransfer
    {
        $this->assertStatus($transfer, StudentTransfer::STATUS_SUBMITTED);

        $from = $transfer->status;
        $transfer->fill([
            'status' => StudentTransfer::STATUS_APPROVED,
            'approved_by' => $actorId,
            'approved_at' => now(),
        ])->save();

        $this->recordHistory($transfer, $from, StudentTransfer::STATUS_APPROVED, $actorId);

        return $transfer->refresh();
    }

    public function reject(StudentTransfer $transfer, string $note, ?int $actorId): StudentTransfer
    {
        $this->assertStatus($transfer, StudentTransfer::STATUS_SUBMITTED);

        $from = $transfer->status;
        $transfer->fill([
            'status' => StudentTransfer::STATUS_REJECTED,
            'approved_by' => $actorId,
            'approved_at' => now(),
            'notes' => $note,
        ])->save();

        $this->recordHistory($transfer, $from, StudentTransfer::STATUS_REJECTED, $actorId, $note);

        return $transfer->refresh();
    }

    /**
     * Selesaikan permohonan dan terapkan efeknya ke data siswa.
     *
     * Idempoten: permohonan yang sudah `completed` dikembalikan tanpa efek ganda.
     */
    public function complete(StudentTransfer $transfer, ?int $actorId): StudentTransfer
    {
        if ($transfer->status === StudentTransfer::STATUS_COMPLETED) {
            return $transfer;
        }

        $this->assertStatus($transfer, StudentTransfer::STATUS_APPROVED);

        return DB::transaction(function () use ($transfer, $actorId) {
            // Baca ulang di dalam transaksi agar aman dari eksekusi ganda paralel.
            $fresh = StudentTransfer::query()->find($transfer->id);

            if (! $fresh) {
                throw ValidationException::withMessages([
                    'status' => 'Permohonan mutasi tidak ditemukan.',
                ]);
            }

            if ($fresh->status === StudentTransfer::STATUS_COMPLETED) {
                return $fresh;
            }

            if ($fresh->status !== StudentTransfer::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya permohonan yang disetujui yang dapat diselesaikan.',
                ]);
            }

            $this->applyEffect($fresh, $actorId);

            if (blank($fresh->document_number)) {
                $fresh->document_number = $this->generateDocumentNumber($fresh);
            }

            $from = $fresh->status;
            $fresh->status = StudentTransfer::STATUS_COMPLETED;
            $fresh->save();

            $this->recordHistory($fresh, $from, StudentTransfer::STATUS_COMPLETED, $actorId);

            return $fresh;
        });
    }

    /**
     * Validasi kelengkapan permohonan berdasarkan jenis mutasi.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertTypeRules(array $data): void
    {
        $type = $data['type'] ?? null;

        if (! in_array($type, StudentTransfer::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => 'Jenis mutasi tidak valid.',
            ]);
        }

        $fromClass = $data['from_class_id'] ?? null;
        $toClass = $data['to_class_id'] ?? null;
        $fromSchool = trim((string) ($data['from_school_name'] ?? ''));
        $toSchool = trim((string) ($data['to_school_name'] ?? ''));
        $yearId = $data['academic_year_id'] ?? null;

        $errors = [];

        if (blank($yearId)) {
            $errors['academic_year_id'] = 'Tahun ajaran wajib dipilih.';
        }

        switch ($type) {
            case StudentTransfer::TYPE_MASUK:
                if (blank($toClass) && $fromSchool === '' && $toSchool === '') {
                    $errors['to_class_id'] = 'Mutasi masuk memerlukan kelas tujuan atau nama sekolah.';
                }
                break;

            case StudentTransfer::TYPE_KELUAR:
                if (blank($fromClass)) {
                    $errors['from_class_id'] = 'Mutasi keluar memerlukan kelas asal.';
                }
                break;

            case StudentTransfer::TYPE_PINDAH_KELAS:
                if (blank($fromClass)) {
                    $errors['from_class_id'] = 'Pindah kelas memerlukan kelas asal.';
                }
                if (blank($toClass)) {
                    $errors['to_class_id'] = 'Pindah kelas memerlukan kelas tujuan.';
                }
                if (! blank($fromClass) && ! blank($toClass) && (int) $fromClass === (int) $toClass) {
                    $errors['to_class_id'] = 'Kelas tujuan harus berbeda dari kelas asal.';
                }
                break;

            case StudentTransfer::TYPE_PINDAH_SEKOLAH:
                if ($fromSchool === '') {
                    $errors['from_school_name'] = 'Pindah sekolah memerlukan nama sekolah asal.';
                }
                if ($toSchool === '') {
                    $errors['to_school_name'] = 'Pindah sekolah memerlukan nama sekolah tujuan.';
                }
                break;
        }

        // Kelas asal/tujuan harus berada pada tahun ajaran yang dipilih.
        foreach (['from_class_id' => $fromClass, 'to_class_id' => $toClass] as $field => $classId) {
            if (! blank($classId) && ! blank($yearId)
                && ! SchoolClass::query()->whereKey($classId)->where('academic_year_id', $yearId)->exists()) {
                $errors[$field] = 'Kelas tersebut bukan milik tahun ajaran yang dipilih.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    // -----------------------------------------------------------------
    // Penerapan efek
    // -----------------------------------------------------------------

    private function applyEffect(StudentTransfer $transfer, ?int $actorId): void
    {
        $student = $transfer->student()->first();

        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => 'Data siswa tidak ditemukan.',
            ]);
        }

        $yearId = (int) $transfer->academic_year_id;

        match ($transfer->type) {
            StudentTransfer::TYPE_MASUK => $this->applyMasuk($transfer, $student, $yearId, $actorId),
            StudentTransfer::TYPE_KELUAR => $this->applyKeluar($transfer, $student, $yearId, $actorId),
            StudentTransfer::TYPE_PINDAH_KELAS => $this->applyPindahKelas($transfer, $student, $yearId, $actorId),
            StudentTransfer::TYPE_PINDAH_SEKOLAH => $this->applyPindahSekolah($transfer, $student, $yearId, $actorId),
            default => throw ValidationException::withMessages([
                'type' => 'Jenis mutasi tidak dikenal.',
            ]),
        };
    }

    private function applyMasuk(StudentTransfer $transfer, Student $student, int $yearId, ?int $actorId): void
    {
        if ($transfer->to_class_id) {
            $this->syncMembership($student, $yearId, (int) $transfer->to_class_id, 'aktif');
        }

        $this->syncHistory($student, $yearId, $transfer->to_class_id, 'baru', $actorId);
        $this->setStudentActive($student, true, 'Aktif');
    }

    private function applyKeluar(StudentTransfer $transfer, Student $student, int $yearId, ?int $actorId): void
    {
        if ($transfer->from_class_id) {
            $this->syncMembership($student, $yearId, (int) $transfer->from_class_id, 'keluar');
        }

        $this->syncHistory($student, $yearId, $transfer->from_class_id, 'keluar', $actorId);
        $this->setStudentActive($student, false, 'Keluar');
    }

    private function applyPindahKelas(StudentTransfer $transfer, Student $student, int $yearId, ?int $actorId): void
    {
        // Lepaskan keanggotaan kelas asal, lalu tempatkan di kelas tujuan.
        if ($transfer->from_class_id) {
            ClassStudent::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $yearId)
                ->where('school_class_id', $transfer->from_class_id)
                ->delete();
        }

        $this->syncMembership($student, $yearId, (int) $transfer->to_class_id, 'aktif');
        $this->syncHistory($student, $yearId, $transfer->to_class_id, 'pindah', $actorId);
        $this->setStudentActive($student, true, 'Aktif');
    }

    private function applyPindahSekolah(StudentTransfer $transfer, Student $student, int $yearId, ?int $actorId): void
    {
        if ($transfer->from_class_id) {
            $this->syncMembership($student, $yearId, (int) $transfer->from_class_id, 'pindah');
        }

        $this->syncHistory($student, $yearId, $transfer->from_class_id, 'pindah', $actorId);
        $this->setStudentActive($student, false, 'Mutasi');
    }

    // -----------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------

    private function syncMembership(Student $student, int $yearId, int $classId, string $status): void
    {
        ClassStudent::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $yearId],
            ['school_class_id' => $classId, 'status' => $status],
        );
    }

    private function syncHistory(Student $student, int $yearId, ?int $classId, string $status, ?int $actorId): void
    {
        $class = $classId ? SchoolClass::query()->find($classId) : null;

        StudentHistory::updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => $yearId],
            [
                'school_class_id' => $classId,
                'education_level_id' => $class?->education_level_id,
                'status' => $status,
                'created_by' => $actorId,
            ],
        );
    }

    private function setStudentActive(Student $student, bool $active, ?string $statusName): void
    {
        $student->is_active = $active;

        if ($statusName !== null) {
            $statusId = StudentStatus::query()->where('name', $statusName)->value('id');
            if ($statusId) {
                $student->status_id = $statusId;
            }
        }

        $student->save();
    }

    private function recordHistory(
        StudentTransfer $transfer,
        ?string $from,
        string $to,
        ?int $actorId,
        ?string $note = null,
    ): void {
        StudentTransferHistory::create([
            'student_transfer_id' => $transfer->id,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_id' => $actorId,
        ]);
    }

    private function assertStatus(StudentTransfer $transfer, string ...$allowed): void
    {
        if (! in_array($transfer->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Permohonan berstatus '.$transfer->statusLabel().' tidak dapat diproses pada tahap ini.',
            ]);
        }
    }

    /**
     * Bangkitkan nomor dokumen unik, mis. `MUT/2026/0001`.
     */
    private function generateDocumentNumber(StudentTransfer $transfer): string
    {
        $transfer->loadMissing('academicYear');

        $year = $transfer->academicYear?->start_date?->year
            ?? $transfer->effective_date?->year
            ?? now()->year;

        return DB::transaction(function () use ($year) {
            $sequence = DocumentSequence::query()
                ->where('code', 'student-transfer')
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = DocumentSequence::create([
                    'code' => 'student-transfer',
                    'prefix' => 'MUT',
                    'format' => 'MUT/{YYYY}/{SEQ:4}',
                    'last_number' => 0,
                    'reset_period' => 'never',
                ]);
            }

            $sequence->last_number = (int) $sequence->last_number + 1;
            $sequence->save();

            return sprintf('MUT/%s/%04d', $year, $sequence->last_number);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function only(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }
}
