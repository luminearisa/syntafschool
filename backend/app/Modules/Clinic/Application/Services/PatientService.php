<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Contracts\ParentProviderInterface;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AllergyCategory;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\PatientType;
use App\Modules\Clinic\Domain\Enums\SpecialConditionCategory;
use App\Modules\Clinic\Models\ClinicAllergy;
use App\Modules\Clinic\Models\ClinicMedicalHistory;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicSpecialCondition;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rekam medis induk pasien.
 *
 * Prinsip yang dijaga kelas ini:
 *
 * - **Tidak ada duplikasi master siswa.** Nama, NIS, kelas, dan program selalu
 *   diambil dari `StudentProviderInterface`. Tabel `clinic_patients` hanya
 *   menyimpan hal yang memang milik klinik: nomor rekam medis, kontak darurat,
 *   asuransi, dan penanda peringatan.
 * - **Nomor rekam medis imun.** Dibuat sekali dari urutan dalam transaksi,
 *   tidak pernah berubah meski siswa pindah kelas atau tahun ajaran berganti.
 * - **Peringatan alergi tidak dihitung di klien.** Penanda `allergy_alert`
 *   dan `special_care_alert` diperbarui di server setiap ada perubahan.
 */
class PatientService
{
    public function __construct(
        private readonly StudentProviderInterface $students,
        private readonly ParentProviderInterface $parents,
        private readonly ClinicAuditService $audit,
    ) {
    }

    /**
     * Rekam medis untuk seorang siswa, dibuat bila belum ada.
     *
     * Aman terhadap permintaan bersamaan: kolom `student_id` unik, sehingga
     * dua permintaan serentak tetap menghasilkan satu rekam medis.
     */
    public function forStudent(int $studentId, ?User $actor = null): ClinicPatient
    {
        $existing = ClinicPatient::query()->where('student_id', $studentId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $snapshot = $this->students->snapshot($studentId);

        if ($snapshot === null) {
            throw ValidationException::withMessages([
                'student_id' => 'Siswa tidak ditemukan pada master ERP.',
            ]);
        }

        return DB::transaction(function () use ($studentId, $snapshot, $actor): ClinicPatient {
            $patient = ClinicPatient::query()->where('student_id', $studentId)->first();

            if ($patient !== null) {
                return $patient;
            }

            // Urutan nomor dihitung di dalam transaksi; kolomnya unik sehingga
            // nomor ganda akan gagal keras, bukan menghasilkan dua MRN.
            $sequence = ClinicNumber::nextSequence('clinic_patients') ;

            $patient = ClinicPatient::query()->create([
                'medical_record_number' => ClinicNumber::medicalRecord($this->nextFreeSequence($sequence)),
                'patient_type' => PatientType::STUDENT->value,
                'student_id' => $studentId,
                'status' => 'ACTIVE',
                'blood_type' => $snapshot['blood_type'],
                'emergency_contact_name' => null,
                'emergency_contact_phone' => null,
                'created_by' => $actor?->id,
            ]);

            // Kontak darurat diisi otomatis dari wali utama bila tersedia:
            // mempercepat penanganan gawat darurat tanpa mengetik ulang.
            $guardians = $this->parents->guardiansForStudent($studentId);
            $primary = collect($guardians)->firstWhere('is_primary', true) ?? ($guardians[0] ?? null);

            if ($primary !== null) {
                $patient->emergency_contact_name = $primary['name'];
                $patient->emergency_contact_phone = $primary['phone'];
                $patient->emergency_contact_relation = $primary['relation'];
                $patient->save();
            }

            $this->audit->record(AuditAction::CREATE, $patient, (int) $patient->id, $actor, [
                'medical_record_number' => $patient->medical_record_number,
            ], [], 'Pembuatan rekam medis baru');

            return $patient;
        });
    }

    /**
     * Rekam medis untuk pasien non-siswa (guru, pegawai, tamu).
     * Fokus modul adalah siswa, namun struktur pasien sudah mendukung tipe lain.
     */
    public function forUser(User $subject, PatientType $type = PatientType::STAFF, ?User $actor = null): ClinicPatient
    {
        $existing = ClinicPatient::query()->where('user_id', $subject->id)->where('patient_type', $type->value)->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($subject, $type, $actor): ClinicPatient {
            $sequence = $this->nextFreeSequence(ClinicNumber::nextSequence('clinic_patients'));

            return ClinicPatient::query()->create([
                'medical_record_number' => ClinicNumber::medicalRecord($sequence),
                'patient_type' => $type->value,
                'user_id' => $subject->id,
                'status' => 'ACTIVE',
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Profil kesehatan lengkap untuk header pasien & portal orang tua.
     * Data siswa diambil dari provider, bukan dari tabel Clinic.
     *
     * @return array<string, mixed>
     */
    public function profile(ClinicPatient $patient, bool $includeClinicalDetail = true): array
    {
        $patient->loadMissing(['student', 'allergies', 'specialConditions', 'medicalHistories']);

        $snapshot = $patient->student_id === null ? null : $this->students->snapshot((int) $patient->student_id);

        $allergies = $patient->allergies
            ->where('status', 'ACTIVE')
            ->map(fn (ClinicAllergy $a): array => [
                'id' => $a->id,
                'category' => $a->category,
                'category_label' => AllergyCategory::from($a->category)->label(),
                'allergen' => $a->allergen,
                'reaction' => $a->reaction,
                'severity' => $a->severity,
                'is_critical' => $a->isCritical(),
                'identified_at' => $a->identified_at?->toDateString(),
            ])->values()->all();

        $conditions = $patient->specialConditions
            ->where('status', 'ACTIVE')
            ->map(fn (ClinicSpecialCondition $c): array => [
                'id' => $c->id,
                'category' => $c->category,
                'category_label' => SpecialConditionCategory::from($c->category)->label(),
                'condition' => $c->condition,
                'care_instruction' => $c->care_instruction,
                'start_date' => $c->start_date?->toDateString(),
                'end_date' => $c->end_date?->toDateString(),
                'visible_to_kitchen' => (bool) $c->visible_to_kitchen,
                'visible_to_boarding' => (bool) $c->visible_to_boarding,
            ])->values()->all();

        $payload = [
            'patient' => [
                'id' => $patient->id,
                'medical_record_number' => $patient->medical_record_number,
                'patient_type' => $patient->patient_type,
                'status' => $patient->status,
                'blood_type' => $patient->blood_type,
                'emergency_contact' => [
                    'name' => $patient->emergency_contact_name,
                    'phone' => $patient->emergency_contact_phone,
                    'relation' => $patient->emergency_contact_relation,
                ],
                'insurance' => [
                    'provider' => $patient->insurance_provider,
                    'number' => $patient->insurance_number,
                ],
                'alerts' => [
                    'allergy' => (bool) $patient->allergy_alert,
                    'special_care' => (bool) $patient->special_care_alert,
                ],
            ],
            'identity' => $snapshot,
            'guardians' => $this->parents->guardiansForStudent((int) $patient->student_id),
            'allergies' => $allergies,
            'special_conditions' => $conditions,
            'vitals_latest' => $this->latestVitals($patient),
            'visit_summary' => $this->visitSummary($patient),
            'medication_history' => $includeClinicalDetail ? $this->medicationHistory($patient) : [],
            'follow_ups' => $includeClinicalDetail ? $this->openFollowUps($patient) : [],
        ];

        if ($includeClinicalDetail) {
            $payload['medical_history'] = $patient->medicalHistories
                ->map(fn (ClinicMedicalHistory $h): array => [
                    'id' => $h->id,
                    'category' => $h->category,
                    'title' => $h->title,
                    'year' => $h->year,
                    'disclosure' => $h->disclosure,
                    'status' => $h->status,
                ])->values()->all();
        } else {
            // Versi ringkas untuk peran yang tidak memerlukan detail (mis. kepala
            // sekolah pada laporan agregat): cukup penanda, bukan isi rekam medis.
            $payload['medical_history'] = [];
            $payload['has_medical_history'] = $patient->medicalHistories->isNotEmpty();
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function latestVitals(ClinicPatient $patient): ?array
    {
        $vital = $patient->vitals()->latest('measured_at')->first();

        if ($vital === null) {
            return null;
        }

        return [
            'measured_at' => $vital->measured_at->toIso8601String(),
            'temperature' => $vital->temperature === null ? null : (float) $vital->temperature,
            'systolic' => $vital->systolic,
            'diastolic' => $vital->diastolic,
            'heart_rate' => $vital->heart_rate,
            'respiratory_rate' => $vital->respiratory_rate,
            'oxygen_saturation' => $vital->oxygen_saturation,
            'weight_kg' => $vital->weight_kg === null ? null : (float) $vital->weight_kg,
            'height_cm' => $vital->height_cm === null ? null : (float) $vital->height_cm,
            'bmi' => $vital->bmi === null ? null : (float) $vital->bmi,
            'bmi_category' => $vital->bmi_category,
        ];
    }

    /** @return array<string, mixed> */
    public function visitSummary(ClinicPatient $patient): array
    {
        $visits = ClinicVisit::query()->where('patient_id', $patient->id);

        return [
            'total' => (clone $visits)->count(),
            'last_30_days' => (clone $visits)->where('visit_at', '>=', now()->subDays(30))->count(),
            'last_visit_at' => (clone $visits)->max('visit_at'),
            'open_visits' => (clone $visits)->whereNotIn('status', ['DISCHARGED', 'CANCELLED'])->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function medicationHistory(ClinicPatient $patient, int $limit = 20): array
    {
        return $patient->administrations()
            ->with('medication:id,name,unit')
            ->where('is_current', true)
            ->latest('administered_at')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'medication_name' => $row->medication_name,
                'dose' => $row->dose,
                'route' => $row->route,
                'status' => $row->status,
                'administered_at' => $row->administered_at?->toIso8601String(),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    public function openFollowUps(ClinicPatient $patient): array
    {
        return $patient->followUps()
            ->whereIn('status', ['PENDING', 'SCHEDULED', 'OVERDUE'])
            ->orderBy('due_date')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'due_date' => $row->due_date?->toDateString(),
                'type' => $row->type,
                'instruction' => $row->instruction,
                'overdue' => $row->isOverdue(),
            ])->all();
    }

    /** @param array<string, mixed> $data */
    public function updateContact(ClinicPatient $patient, array $data, User $actor): ClinicPatient
    {
        $before = $patient->only([
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
            'insurance_provider', 'insurance_number', 'blood_type',
        ]);

        $patient->fill(array_intersect_key($data, array_flip([
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
            'insurance_provider', 'insurance_number', 'blood_type',
        ])));
        $patient->save();

        $this->audit->record(AuditAction::UPDATE, $patient, (int) $patient->id, $actor, [
            'before' => $before,
            'after' => $patient->only(array_keys($before)),
        ], [], 'Pembaruan data kontak/asuransi pasien');

        return $patient;
    }

    /** @param array<string, mixed> $data */
    public function addAllergy(ClinicPatient $patient, array $data, User $actor): ClinicAllergy
    {
        $allergy = ClinicAllergy::query()->create([
            'patient_id' => $patient->id,
            'category' => $data['category'] ?? AllergyCategory::MEDICATION->value,
            'allergen' => $data['allergen'],
            'reaction' => $data['reaction'] ?? null,
            'severity' => $data['severity'] ?? 'UNKNOWN',
            'status' => 'ACTIVE',
            'medication_id' => $data['medication_id'] ?? null,
            'identified_at' => $data['identified_at'] ?? now()->toDateString(),
            'notes' => $data['notes'] ?? null,
            'recorded_by' => $actor->id,
        ]);

        $this->refreshAlerts($patient);

        $this->audit->record(AuditAction::CREATE, $allergy, (int) $patient->id, $actor, [
            'allergen' => $allergy->allergen,
            'severity' => $allergy->severity,
        ], [], 'Penambahan alergi pasien');

        return $allergy;
    }

    public function resolveAllergy(ClinicAllergy $allergy, User $actor, string $status = 'RESOLVED'): ClinicAllergy
    {
        $allergy->status = $status;
        $allergy->save();

        $this->refreshAlerts($allergy->patient);

        $this->audit->record(AuditAction::UPDATE, $allergy, (int) $allergy->patient_id, $actor, [
            'status' => $status,
        ], [], 'Perubahan status alergi');

        return $allergy;
    }

    /** @param array<string, mixed> $data */
    public function addSpecialCondition(ClinicPatient $patient, array $data, User $actor): ClinicSpecialCondition
    {
        $condition = ClinicSpecialCondition::query()->create([
            'patient_id' => $patient->id,
            'category' => $data['category'] ?? SpecialConditionCategory::SPECIAL_CARE->value,
            'condition' => $data['condition'],
            'description' => $data['description'] ?? null,
            'care_instruction' => $data['care_instruction'] ?? null,
            'start_date' => $data['start_date'] ?? now()->toDateString(),
            'end_date' => $data['end_date'] ?? null,
            'status' => 'ACTIVE',
            // Dapur & asrama hanya menerima instruksi operasional, bukan diagnosis.
            'visible_to_kitchen' => (bool) ($data['visible_to_kitchen'] ?? false),
            'visible_to_boarding' => (bool) ($data['visible_to_boarding'] ?? false),
            'recorded_by' => $actor->id,
        ]);

        $this->refreshAlerts($patient);

        $this->audit->record(AuditAction::CREATE, $condition, (int) $patient->id, $actor, [
            'condition' => $condition->condition,
            'category' => $condition->category,
        ], [], 'Penambahan kondisi khusus pasien');

        return $condition;
    }

    /** @param array<string, mixed> $data */
    public function addMedicalHistory(ClinicPatient $patient, array $data, User $actor): ClinicMedicalHistory
    {
        $history = ClinicMedicalHistory::query()->create([
            'patient_id' => $patient->id,
            'category' => $data['category'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'year' => $data['year'] ?? null,
            'disclosure' => $data['disclosure'] ?? 'KNOWN',
            'status' => 'ACTIVE',
            'recorded_at' => $data['recorded_at'] ?? now()->toDateString(),
            'recorded_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::CREATE, $history, (int) $patient->id, $actor, [
            'category' => $history->category,
            'title' => $history->title,
        ], [], 'Penambahan riwayat medis');

        return $history;
    }

    /** Menghitung ulang penanda peringatan pada rekam medis pasien. */
    public function refreshAlerts(ClinicPatient $patient): void
    {
        $hasAllergy = ClinicAllergy::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'ACTIVE')
            ->exists();

        $hasCondition = ClinicSpecialCondition::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'ACTIVE')
            ->exists();

        $patient->allergy_alert = $hasAllergy;
        $patient->special_care_alert = $hasCondition;
        $patient->save();
    }

    /**
     * Peringatan alergi untuk sekumpulan obat yang akan diresepkan.
     *
     * Pengembaliannya berupa daftar peringatan, bukan keputusan: dokter tetap
     * dapat melanjutkan dengan alasan tertulis. Ini memenuhi aturan
     * "peringatkan, jangan ganti keputusan klinis".
     *
     * @param list<int|null> $medicationIds
     * @return list<array<string, mixed>>
     */
    public function allergyWarnings(ClinicPatient $patient, array $medicationIds, array $medicationNames = []): array
    {
        $allergies = ClinicAllergy::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'ACTIVE')
            ->get();

        if ($allergies->isEmpty()) {
            return [];
        }

        $warnings = [];

        foreach ($allergies as $allergy) {
            $needle = mb_strtolower(trim((string) $allergy->allergen));

            if ($needle === '') {
                continue;
            }

            foreach ($medicationIds as $index => $medicationId) {
                $name = mb_strtolower((string) ($medicationNames[$index] ?? ''));

                $matchesId = $medicationId !== null && (int) $allergy->medication_id === (int) $medicationId;
                $matchesName = $name !== '' && (str_contains($name, $needle) || str_contains($needle, $name));

                if (! ($matchesId || $matchesName)) {
                    continue;
                }

                $warnings[] = [
                    'allergy_id' => $allergy->id,
                    'allergen' => $allergy->allergen,
                    'severity' => $allergy->severity,
                    'reaction' => $allergy->reaction,
                    'medication_id' => $medicationId,
                    'medication_name' => $medicationNames[$index] ?? null,
                    'is_critical' => $allergy->isCritical(),
                ];
            }
        }

        return $warnings;
    }

    /** Nomor MRN yang belum terpakai (kolom unik, jadi aman bila tabrakan ditolak DB). */
    private function nextFreeSequence(int $start): int
    {
        $sequence = max(1, $start);

        while (ClinicPatient::query()
            ->where('medical_record_number', ClinicNumber::medicalRecord($sequence))
            ->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
