<?php

namespace App\Services\Ppdb;

use App\Models\AcademicYear;
use App\Models\ClassStudent;
use App\Models\Gender;
use App\Models\Guardian;
use App\Models\GuardianRelation;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbRegistration;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentHistory;
use App\Models\StudentStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Konversi calon peserta didik menjadi siswa (`students`).
 *
 * Prinsipnya: **satu orang satu baris**. Person yang sama tidak boleh lahir dua
 * kali walau orang tuanya mendaftar di dua program, dan identitas wali tidak
 * disalin — `guardian_id` milik calon dipakai ulang lalu ditautkan lewat
 * `student_guardians`.
 *
 * Aturan baku yang dipakai kelas ini:
 * - `nis`       : penomoran existing `PpdbNumberingService::next('student')`
 *                 (format `document_sequences`), diulang bila menabrak unique.
 * - `nisn`      : disalin dari calon selama bebas; bila sudah dipakai siswa lain
 *                 permintaan ditolak (422) agar tidak ada duplikasi orang.
 * - `status_id` : baris `student_statuses` aktif yang namanya "Aktif" (pencocokan
 *                 longgar), bukan id hardcode — lihat `activeStudentStatus()`.
 * - `school_id` : ikut konvensi `StudentController` (boleh null, diambil dari
 *                 request; default sekolah aktif satu-satunya).
 * - kelas       : hanya bila rombel benar-benar dikirim & cocok tahun ajarannya.
 */
class PpdbConversionService
{
    public const NIS_TRIES = 10;

    public function __construct(
        private readonly PpdbNumberingService $numbering,
        private readonly PpdbRegistrationStateMachine $state,
    ) {
    }

    /**
     * @param  array{school_id?: int|null, status_id?: int|null, nis?: string|null, school_class_id?: int|null, academic_year_id?: int|null}  $options
     * @return array{student: Student, created: bool, registration: PpdbRegistration, skipped: list<string>}
     */
    public function convert(PpdbRegistration $registration, ?User $actor = null, array $options = []): array
    {
        if ($registration->status !== PpdbRegistration::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => 'Calon hanya dapat dijadikan siswa setelah daftar ulang selesai (status sekarang: '
                    .$registration->status.').',
            ]);
        }

        $registration->loadMissing('applicant.parentAccount.user', 'program', 'period');

        $applicant = $registration->applicant;

        if ($applicant === null) {
            throw ValidationException::withMessages([
                'applicant' => 'Data calon pada pendaftaran ini tidak ditemukan.',
            ]);
        }

        // Idempoten: pendaftaran ini (atau pendaftaran lain milik orang yang sama)
        // sudah pernah menghasilkan siswa.
        $skipped = [];
        $match = $this->existingStudentFor($registration);

        if ($match !== null) {
            $student = $match['student'];

            if ($student->trashed()) {
                // Baris lama dipulihkan, bukan disisipkan baru: `nisn`/`nis` unik di
                // tingkat database, jadi menyisipkan orang yang sama akan gagal.
                $student->restore();
            }

            if ((int) $registration->student_id !== (int) $student->id) {
                $registration->forceFill(['student_id' => $student->id])->save();

                $this->state->record(
                    $registration,
                    'student.converted',
                    "Menggunakan data siswa existing NIS {$student->nis} (cocok lewat {$match['matched_by']}).",
                    $actor,
                );
            }

            // Berkas yang sudah disetujui tetap ikut dilaporkan walau orangnya
            // sudah ada, supaya konversi ulang tidak meninggalkan siswa tanpa dokumen.
            $documents = $this->collectDocuments($registration, $skipped);

            return [
                'student' => $student->load(['status', 'guardians', 'program', 'school']),
                'created' => false,
                'registration' => $registration->refresh(),
                'matched_by' => $match['matched_by'],
                'documents' => $documents,
                'skipped' => $skipped,
            ];
        }

        $status = $this->activeStudentStatus();
        $nisn = $this->resolveNisn($applicant->nisn);

        if ($status === null) {
            $skipped[] = 'status_siswa_kosong';
        }

        $student = DB::transaction(function () use ($registration, $applicant, $actor, $options, $status, $nisn, &$skipped, &$documents) {
            $student = Student::create([
                'school_id' => $options['school_id'] ?? $this->defaultSchoolId(),
                'nis' => $this->uniqueNis($options['nis'] ?? null),
                'nisn' => $nisn,
                'full_name' => $applicant->full_name,
                'nick_name' => $applicant->nickname,
                'gender_id' => $applicant->gender_id,
                'religion_id' => $applicant->religion_id,
                'birth_place' => $applicant->birth_place,
                'birth_date' => $applicant->birth_date,
                'address' => $applicant->address,
                'province_id' => $applicant->province_id,
                'city_id' => $applicant->city_id,
                'district_id' => $applicant->district_id,
                'village_id' => $applicant->village_id,
                'postal_code' => $applicant->postal_code,
                'previous_school' => $applicant->previous_school,
                'photo_path' => $applicant->photo_path,
                'program_id' => $registration->program?->program_id,
                'admission_date' => Carbon::now()->toDateString(),
                'status_id' => $status?->id,
                'is_active' => true,
            ]);

            $this->linkGuardian($student, $applicant->guardian_id, $applicant->gender_id, $skipped);
            $this->enrollInClass($student, $registration, $actor, $options, $skipped);
            $documents = $this->collectDocuments($registration, $skipped);

            $registration->forceFill(['student_id' => $student->id])->save();

            $this->state->record(
                $registration,
                'student.converted',
                "Calon {$applicant->full_name} dijadikan siswa dengan NIS {$student->nis}"
                    .($documents['total'] > 0 ? " ({$documents['total']} berkas calon ikut tercatat)." : '.'),
                $actor,
            );

            return $student;
        });

        return [
            'student' => $student->load(['status', 'guardians', 'program', 'school']),
            'created' => true,
            'registration' => $registration->refresh(),
            'matched_by' => 'siswa_baru',
            'documents' => $documents ?? ['total' => 0, 'approved' => 0, 'on_disk' => 0, 'missing' => [], 'files' => []],
            'skipped' => $skipped,
        ];
    }

    /**
     * Siswa existing untuk pendaftaran ini — termasuk saat calon yang sama punya
     * pendaftaran lain yang sudah lebih dulu dikonversi.
     *
     * `matched_by` ikut dikembalikan karena muncul di respons API dan riwayat:
     * operator harus tahu baris mana yang dipakai, bukan hanya "sudah ada".
     *
     * @return array{student: Student, matched_by: string}|null
     */
    private function existingStudentFor(PpdbRegistration $registration): ?array
    {
        if ($registration->student_id !== null) {
            $student = Student::query()->find($registration->student_id);

            if ($student !== null) {
                return ['student' => $student, 'matched_by' => 'pendaftaran_ini'];
            }
        }

        $sibling = PpdbRegistration::query()
            ->where('applicant_id', $registration->applicant_id)
            ->where('id', '!=', $registration->id)
            ->whereNotNull('student_id')
            ->first(['id', 'student_id', 'registration_number']);

        if ($sibling === null) {
            return null;
        }

        $student = Student::query()->find($sibling->student_id);

        return $student === null
            ? null
            : ['student' => $student, 'matched_by' => "pendaftaran_{$sibling->registration_number}"];
    }

    /**
     * Berkas calon yang mengikuti siswa hasil konversi.
     *
     * Tidak ada penyalinan: project ini tidak punya tabel dokumen siswa terpisah,
     * jadi berkas tetap satu salinan di disk `public` dan tetap tercatat di
     * `ppdb_applicant_documents` milik pendaftar (dapat ditelusuri lewat
     * `registration->student_id`). Yang diperiksa di sini hanya apakah isinya
     * benar-benar ada di disk dan sudah disetujui panitia — siswa tidak boleh
     * berdiri di atas berkas yang ternyata hilang.
     *
     * @return array{total: int, approved: int, on_disk: int, missing: list<string>, files: list<array<string, mixed>>}
     */
    private function collectDocuments(PpdbRegistration $registration, array &$skipped): array
    {
        $documents = PpdbApplicantDocument::query()
            ->where('registration_id', $registration->id)
            ->where('is_active', true)
            ->orderBy('requirement_name')
            ->get(['id', 'requirement_name', 'status', 'file_path', 'original_filename', 'mime_type', 'file_size']);

        $disk = Storage::disk('public');

        $missing = $documents->reject(fn (PpdbApplicantDocument $d) => $disk->exists((string) $d->file_path));

        if ($missing->isNotEmpty()) {
            $skipped[] = 'berkas_hilang_di_disk:'.$missing->count();
        }

        $unapproved = $documents->reject(
            fn (PpdbApplicantDocument $d) => $d->status === PpdbApplicantDocument::STATUS_APPROVED
        );

        if ($unapproved->isNotEmpty()) {
            $skipped[] = 'berkas_belum_disetujui:'.$unapproved->count();
        }

        return [
            'total' => $documents->count(),
            'approved' => $documents->count() - $unapproved->count(),
            'on_disk' => $documents->count() - $missing->count(),
            'missing' => $missing->pluck('original_filename')->all(),
            'files' => $documents->map(fn (PpdbApplicantDocument $d) => [
                'id' => (int) $d->id,
                'requirement_name' => $d->requirement_name,
                'filename' => $d->original_filename,
                'mime_type' => $d->mime_type,
                'file_size' => (int) $d->file_size,
                'status' => $d->status,
                'available' => $disk->exists((string) $d->file_path),
            ])->all(),
        ];
    }

    /**
     * NIS dari penomoran existing; diulang sampai bebas (kolom `nis` unique).
     */
    private function uniqueNis(?string $provided): string
    {
        if (filled($provided)) {
            if (Student::query()->where('nis', $provided)->exists()) {
                throw ValidationException::withMessages(['nis' => 'NIS tersebut sudah dipakai siswa lain.']);
            }

            return (string) $provided;
        }

        for ($i = 0; $i < self::NIS_TRIES; $i++) {
            $nis = $this->numbering->next('student');

            if (! Student::query()->where('nis', $nis)->exists()) {
                return $nis;
            }
        }

        throw ValidationException::withMessages([
            'nis' => 'Nomor induk siswa belum bisa dibuat. Periksa konfigurasi penomoran `student`.',
        ]);
    }

    /**
     * NISN disalin selama bebas; bila sudah dipakai, tolak (jangan gandakan orang).
     */
    private function resolveNisn(?string $nisn): ?string
    {
        if (blank($nisn)) {
            return null;
        }

        $owner = Student::query()->where('nisn', $nisn)->first();

        if ($owner !== null) {
            throw ValidationException::withMessages([
                'nisn' => "NISN $nisn sudah terdaftar atas nama siswa \"{$owner->full_name}\" (NIS {$owner->nis}). "
                    .'Perbaiki NISN calon atau gabungkan datanya terlebih dahulu agar tidak membuat siswa ganda.',
            ]);
        }

        return (string) $nisn;
    }

    /**
     * Status siswa "aktif" — dicari dari master, tidak pernah hardcode id.
     *
     * Urutan: nama persis (case-insensitive) `Aktif` → nama diawali `aktif` →
     * baris aktif pertama.
     */
    public function activeStudentStatus(): ?StudentStatus
    {
        $statuses = StudentStatus::query()->where('is_active', true)->orderBy('id')->get();

        return $statuses->first(fn (StudentStatus $s) => mb_strtolower(trim($s->name)) === 'aktif')
            ?? $statuses->first(fn (StudentStatus $s) => str_starts_with(mb_strtolower(trim($s->name)), 'aktif'))
            ?? $statuses->first();
    }

    /**
     * Konvensi `StudentController`: `school_id` boleh kosong dan datang dari form.
     * Bila tidak dikirim, dipakai sekolah aktif satu-satunya bila hanya ada satu.
     */
    private function defaultSchoolId(): ?int
    {
        $query = School::query()->where('is_active', true);

        return $query->clone()->count() === 1 ? (int) $query->value('id') : null;
    }

    /**
     * Tautkan wali pemilik akun orang tua ke siswa tanpa membuat orang baru.
     */
    private function linkGuardian(Student $student, ?int $guardianId, ?int $applicantGenderId, array &$skipped): void
    {
        if ($guardianId === null) {
            $skipped[] = 'wali_tidak_terhubung';

            return;
        }

        $guardian = Guardian::query()->find($guardianId);

        if ($guardian === null) {
            $skipped[] = 'wali_tidak_ditemukan';

            return;
        }

        StudentGuardian::query()->updateOrCreate(
            ['student_id' => $student->id, 'guardian_id' => $guardian->id],
            [
                'guardian_relation_id' => $this->deriveRelationId($guardian, $applicantGenderId),
                'is_primary' => true,
            ],
        );
    }

    /**
     * Hubungan wali: pola yang sudah dipakai wali ini pada siswa lain, lalu
     * ditebak dari jenis kelamin calon (Ayah/Ibu), terakhir "Wali".
     */
    private function deriveRelationId(Guardian $guardian, ?int $applicantGenderId): ?int
    {
        $used = StudentGuardian::query()
            ->where('guardian_id', $guardian->id)
            ->whereNotNull('guardian_relation_id')
            ->value('guardian_relation_id');

        if ($used !== null) {
            return (int) $used;
        }

        $code = $applicantGenderId === null ? null : Gender::query()->whereKey($applicantGenderId)->value('code');

        $name = match ($code) {
            'L' => 'Ayah',
            'P' => 'Ibu',
            default => 'Wali',
        };

        return GuardianRelation::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id')
            ?? GuardianRelation::query()->whereRaw('lower(name) = ?', ['wali'])->value('id');
    }

    /**
     * Pendaftaran kelas hanya bila skema lama memang mendukungnya: rombel aktif +
     * tahun ajaran yang cocok. Selain itu dilewati dan dilaporkan.
     */
    private function enrollInClass(Student $student, PpdbRegistration $registration, ?User $actor, array $options, array &$skipped): void
    {
        $classId = $options['school_class_id'] ?? null;

        if ($classId === null) {
            $skipped[] = 'rombel_tidak_dikirim';

            return;
        }

        $class = SchoolClass::query()->find($classId);

        if ($class === null || ! $class->is_active) {
            $skipped[] = 'rombel_tidak_valid';

            return;
        }

        $yearId = $options['academic_year_id'] ?? $class->academic_year_id ?? $registration->period?->academic_year_id;

        if ($yearId === null || AcademicYear::query()->whereKey($yearId)->doesntExist()) {
            $skipped[] = 'tahun_ajaran_tidak_diketahui';

            return;
        }

        if ((int) $class->academic_year_id !== (int) $yearId) {
            $skipped[] = 'rombel_bukan_milik_tahun_ajaran_terpilih';

            return;
        }

        ClassStudent::query()->updateOrCreate(
            ['student_id' => $student->id, 'academic_year_id' => (int) $yearId],
            ['school_class_id' => $class->id, 'status' => 'aktif'],
        );

        try {
            StudentHistory::query()->updateOrCreate(
                ['student_id' => $student->id, 'academic_year_id' => (int) $yearId],
                [
                    'school_class_id' => $class->id,
                    'education_level_id' => $class->education_level_id,
                    'status' => 'baru',
                    'note' => 'Peserta didik baru hasil PPDB.',
                    'created_by' => $actor?->id,
                ],
            );
        } catch (Throwable) {
            $skipped[] = 'riwayat_siswa_gagal_dibuat';
        }
    }
}
