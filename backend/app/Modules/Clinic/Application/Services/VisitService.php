<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Contracts\BoardingProviderInterface;
use App\Modules\Clinic\Domain\Contracts\StudentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\Priority;
use App\Modules\Clinic\Domain\Enums\QueueStage;
use App\Modules\Clinic\Domain\Enums\VisitStatus;
use App\Modules\Clinic\Models\ClinicLocation;
use App\Modules\Clinic\Models\ClinicPatient;
use App\Modules\Clinic\Models\ClinicQueueEntry;
use App\Modules\Clinic\Models\ClinicTriage;
use App\Modules\Clinic\Models\ClinicVisit;
use App\Modules\Clinic\Models\ClinicVitalSign;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alur kunjungan klinis: pendaftaran → antrean → triase → tanda vital →
 * penanganan → penutupan.
 *
 * Kelas ini pemegang **mesin status** kunjungan. Aturan yang ditegakkan:
 *
 * - Kunjungan tidak boleh dianggap selesai tanpa penutupan eksplisit
 *   (pemulangan/rujukan/pembatalan) — Rule 12.
 * - Prioritas klinis hanya boleh berasal dari triase atau override gawat
 *   darurat, bukan dari klien biasa.
 * - Permintaan ulang akibat jaringan putus tidak menghasilkan dua kunjungan
 *   (kunci idempotensi) — Rule 6.
 * - Urutan nomor kunjungan diambil di dalam transaksi sehingga tidak ada
 *   dua kunjungan dengan nomor sama.
 */
class VisitService
{
    public function __construct(
        private readonly PatientService $patients,
        private readonly ClinicAuditService $audit,
        private readonly StudentProviderInterface $students,
        private readonly BoardingProviderInterface $boarding,
    ) {
    }

    /**
     * Pendaftaran kunjungan baru beserta baris antreannya.
     *
     * @param array<string, mixed> $data
     */
    public function register(array $data, User $actor): ClinicVisit
    {
        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = ClinicVisit::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->load(['patient.student', 'triage', 'queue']);
            }
        }

        $patient = $this->resolvePatient($data, $actor);
        $clinic = $this->resolveClinic($data['clinic_id'] ?? null);

        if (! $clinic->supports_emergency && ($data['visit_type'] ?? 'GENERAL') === 'EMERGENCY') {
            throw ValidationException::withMessages([
                'visit_type' => "Lokasi {$clinic->name} tidak melayani kunjungan gawat darurat; pilih klinik utama.",
            ]);
        }

        return DB::transaction(function () use ($data, $actor, $patient, $clinic, $idempotencyKey): ClinicVisit {
            $sequence = $this->nextFreeVisitSequence();
            $isEmergency = ($data['visit_type'] ?? 'GENERAL') === 'EMERGENCY';
            $priority = $isEmergency
                ? Priority::EMERGENCY->value
                : (($data['priority'] ?? null) ?: Priority::NON_URGENT->value);

            $snapshot = $patient->student_id === null ? null : $this->students->snapshot((int) $patient->student_id);

            $visit = ClinicVisit::query()->create([
                'visit_number' => ClinicNumber::visit($sequence),
                'patient_id' => $patient->id,
                'clinic_id' => $clinic->id,
                'student_id' => $patient->student_id,
                'appointment_id' => $data['appointment_id'] ?? null,
                'visit_at' => $data['visit_at'] ?? now(),
                'visit_type' => $data['visit_type'] ?? 'GENERAL',
                'source' => $data['source'] ?? 'SELF',
                'priority' => $priority,
                'status' => VisitStatus::REGISTERED->value,
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'complaint_id' => $data['complaint_id'] ?? null,
                'attended_by' => $data['attended_by'] ?? null,
                'registered_by' => $actor->id,
                'school_class_id' => $snapshot['class_id'] ?? null,
                'academic_year_id' => DB::table('academic_years')->where('is_active', true)->value('id'),
                'is_emergency' => $isEmergency,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->enqueue($visit, $isEmergency);

            if ($visit->appointment_id !== null) {
                DB::table('clinic_appointments')->where('id', $visit->appointment_id)->update([
                    'status' => 'CHECKED_IN',
                    'visit_id' => $visit->id,
                    'updated_at' => now(),
                ]);
            }

            $this->audit->record(AuditAction::CREATE, $visit, (int) $patient->id, $actor, [
                'visit_number' => $visit->visit_number,
                'visit_type' => $visit->visit_type,
                'priority' => $visit->priority,
                'source' => $visit->source,
            ], [], 'Pendaftaran kunjungan klinis');

            return $visit->fresh(['patient.student', 'triage', 'queue', 'clinic']);
        });
    }

    /**
     * Triase: keluhan utama, prioritas, penilaian awal.
     * Prioritas dari triase menggantikan prioritas awal kunjungan.
     *
     * @param array<string, mixed> $data
     */
    public function recordTriage(ClinicVisit $visit, array $data, User $actor): ClinicTriage
    {
        $this->assertWritable($visit);

        return DB::transaction(function () use ($visit, $data, $actor): ClinicTriage {
            $priority = $data['priority'] ?? $visit->priority;

            $triage = ClinicTriage::query()->updateOrCreate(
                ['visit_id' => $visit->id],
                [
                    'priority' => $priority,
                    'chief_complaint' => $data['chief_complaint'] ?? $visit->chief_complaint ?? '-',
                    'pain_score' => $data['pain_score'] ?? null,
                    'consciousness' => $data['consciousness'] ?? 'ALERT',
                    'general_condition' => $data['general_condition'] ?? 'GOOD',
                    'duration' => $data['duration'] ?? null,
                    'onset' => $data['onset'] ?? null,
                    'severity_self' => $data['severity_self'] ?? null,
                    'associated_symptoms' => $data['associated_symptoms'] ?? null,
                    'is_infectious_suspect' => (bool) ($data['is_infectious_suspect'] ?? false),
                    'requires_isolation' => (bool) ($data['requires_isolation'] ?? false),
                    'notes' => $data['notes'] ?? null,
                    'triaged_by' => $actor->id,
                    'triaged_at' => now(),
                ]
            );

            $visit->priority = $priority;
            $visit->chief_complaint = $triage->chief_complaint;
            if ($visit->status === VisitStatus::REGISTERED->value) {
                $visit->status = VisitStatus::TRIAGED->value;
            }
            $visit->updated_by = $actor->id;
            $visit->save();

            $this->moveQueue($visit, QueueStage::WAITING_PROVIDER, $actor);

            $this->audit->record(AuditAction::CREATE, $triage, (int) $visit->patient_id, $actor, [
                'priority' => $priority,
                'chief_complaint' => $triage->chief_complaint,
                'pain_score' => $triage->pain_score,
            ], [], 'Triase dicatat');

            return $triage->fresh();
        });
    }

    /**
     * Tanda vital. BMI dihitung otomatis; peringatan dihitung dari konfigurasi
     * klinik (bukan keputusan klinis otomatis).
     *
     * @param array<string, mixed> $data
     * @return array{vital: ClinicVitalSign, warnings: list<string>}
     */
    public function recordVitals(ClinicVisit $visit, array $data, User $actor): array
    {
        $this->assertWritable($visit);

        $vital = DB::transaction(function () use ($visit, $data, $actor): ClinicVitalSign {
            $vital = ClinicVitalSign::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'temperature' => $data['temperature'] ?? null,
                'systolic' => $data['systolic'] ?? null,
                'diastolic' => $data['diastolic'] ?? null,
                'heart_rate' => $data['heart_rate'] ?? null,
                'respiratory_rate' => $data['respiratory_rate'] ?? null,
                'oxygen_saturation' => $data['oxygen_saturation'] ?? null,
                'pain_score' => $data['pain_score'] ?? null,
                'weight_kg' => $data['weight_kg'] ?? null,
                'height_cm' => $data['height_cm'] ?? null,
                'bmi' => $this->bmi($data['weight_kg'] ?? null, $data['height_cm'] ?? null),
                'blood_glucose' => $data['blood_glucose'] ?? null,
                'blood_pressure_note' => $data['blood_pressure_note'] ?? null,
                'device_ref' => $data['device_ref'] ?? null,
                'measured_at' => $data['measured_at'] ?? now(),
                'measured_by' => $actor->id,
            ]);

            if ($vital->bmi !== null) {
                $vital->bmi_category = ClinicVitalSign::classifyBmi((float) $vital->bmi);
                $vital->save();
            }

            if ($visit->status === VisitStatus::TRIAGED->value) {
                $visit->status = VisitStatus::IN_PROGRESS->value;
                $visit->updated_by = $actor->id;
                $visit->save();
            }

            $this->audit->record(AuditAction::CREATE, $vital, (int) $visit->patient_id, $actor, [
                'temperature' => $vital->temperature,
                'systolic' => $vital->systolic,
                'diastolic' => $vital->diastolic,
                'bmi' => $vital->bmi,
            ], [], 'Tanda vital dicatat');

            return $vital;
        });

        return ['vital' => $vital, 'warnings' => $this->vitalWarnings($vital)];
    }

    /**
     * Peringatan tanda vital berbasis ambang konfigurasi.
     *
     * Ini **peringatan**, bukan diagnosis: petugas tetap memutuskan tindakan.
     *
     * @return list<string>
     */
    public function vitalWarnings(ClinicVitalSign $vital): array
    {
        $cfg = (array) config('clinic.vitals', []);
        $warnings = [];

        $threshold = fn (string $key, float $default): float => (float) ($cfg[$key] ?? $default);

        if ($vital->temperature !== null) {
            $temperature = (float) $vital->temperature;
            if ($temperature >= $threshold('high_fever_celsius', 39.0)) {
                $warnings[] = "Suhu {$temperature}°C — demam tinggi, pertimbangkan penanganan segera.";
            } elseif ($temperature >= $threshold('fever_celsius', 37.5)) {
                $warnings[] = "Suhu {$temperature}°C — demam.";
            } elseif ($temperature <= $threshold('hypothermia_celsius', 35.0)) {
                $warnings[] = "Suhu {$temperature}°C — hipotermia.";
            }
        }

        if ($vital->oxygen_saturation !== null && (int) $vital->oxygen_saturation <= (int) $threshold('hypoxia_spo2', 94)) {
            $warnings[] = "SpO₂ {$vital->oxygen_saturation}% — saturasi rendah.";
        }

        if ($vital->heart_rate !== null) {
            if ((int) $vital->heart_rate >= (int) $threshold('tachycardia_bpm', 120)) {
                $warnings[] = "Nadi {$vital->heart_rate} bpm — takikardia.";
            } elseif ((int) $vital->heart_rate <= (int) $threshold('bradycardia_bpm', 50)) {
                $warnings[] = "Nadi {$vital->heart_rate} bpm — bradikardia.";
            }
        }

        if ($vital->respiratory_rate !== null && (int) $vital->respiratory_rate >= (int) $threshold('tachypnea_rpm', 26)) {
            $warnings[] = "Napas {$vital->respiratory_rate} x/menit — takipnea.";
        }

        if ($vital->systolic !== null) {
            if ((int) $vital->systolic >= (int) $threshold('hypertensive_systolic', 140)) {
                $warnings[] = "Tekanan darah {$vital->systolic}/{$vital->diastolic} mmHg — hipertensi.";
            } elseif ((int) $vital->systolic <= (int) $threshold('hypotensive_systolic', 90)) {
                $warnings[] = "Tekanan darah {$vital->systolic}/{$vital->diastolic} mmHg — hipotensi.";
            }
        }

        if ($vital->pain_score !== null && (int) $vital->pain_score >= (int) $threshold('severe_pain', 7)) {
            $warnings[] = "Skala nyeri {$vital->pain_score}/10 — nyeri berat.";
        }

        if ($vital->bmi !== null && in_array($vital->bmi_category, ['KURUS', 'KURANG', 'OBESITAS'], true)) {
            $warnings[] = "IMT {$vital->bmi} ({$vital->bmi_category}) — perlu perhatian gizi.";
        }

        return $warnings;
    }

    /** Pindahkan tahap antrean kunjungan. */
    public function moveQueue(ClinicVisit $visit, QueueStage $stage, User $actor): void
    {
        $entry = ClinicQueueEntry::query()->where('visit_id', $visit->id)->first();

        if ($entry === null) {
            return;
        }

        $entry->stage = $stage->value;
        $entry->assigned_to = $stage === QueueStage::WAITING ? $entry->assigned_to : $actor->id;

        if ($stage === QueueStage::IN_EXAMINATION && $entry->started_at === null) {
            $entry->started_at = now();
        }

        if ($stage === QueueStage::COMPLETED) {
            $entry->finished_at = now();
        }

        $entry->save();
    }

    /** Ubah status kunjungan dengan pemeriksaan transisi yang sah. */
    public function advanceStatus(ClinicVisit $visit, VisitStatus $target, User $actor): ClinicVisit
    {
        $current = $visit->statusEnum();

        if ($current === $target) {
            return $visit;
        }

        if ($current->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => "Kunjungan sudah {$current->label()} dan tidak dapat diubah lagi.",
            ]);
        }

        $allowed = match ($current) {
            VisitStatus::REGISTERED => [VisitStatus::TRIAGED, VisitStatus::IN_PROGRESS, VisitStatus::OBSERVATION, VisitStatus::REFERRED, VisitStatus::CANCELLED],
            VisitStatus::TRIAGED => [VisitStatus::IN_PROGRESS, VisitStatus::OBSERVATION, VisitStatus::REFERRED, VisitStatus::CANCELLED],
            VisitStatus::IN_PROGRESS => [VisitStatus::OBSERVATION, VisitStatus::REFERRED, VisitStatus::DISCHARGED, VisitStatus::CANCELLED],
            VisitStatus::OBSERVATION => [VisitStatus::IN_PROGRESS, VisitStatus::REFERRED, VisitStatus::DISCHARGED, VisitStatus::CANCELLED],
            VisitStatus::REFERRED => [VisitStatus::OBSERVATION, VisitStatus::DISCHARGED, VisitStatus::CANCELLED],
            VisitStatus::DISCHARGED, VisitStatus::CANCELLED => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => "Status tidak dapat berpindah dari {$current->label()} ke {$target->label()}.",
            ]);
        }

        $before = $visit->status;
        $visit->status = $target->value;
        $visit->updated_by = $actor->id;

        if ($target->isTerminal()) {
            $visit->closed_at = now();
        }

        $visit->save();

        if ($target === VisitStatus::OBSERVATION) {
            $visit->requires_observation = true;
            $visit->save();
        }

        $this->audit->record(AuditAction::UPDATE, $visit, (int) $visit->patient_id, $actor, [
            'status_before' => $before,
            'status_after' => $target->value,
        ], [], 'Perubahan status kunjungan');

        return $visit->fresh();
    }

    /** Tutup kunjungan (dipakai pemulangan/batal). */
    public function close(ClinicVisit $visit, VisitStatus $target, User $actor): ClinicVisit
    {
        $closed = $this->advanceStatus($visit, $target, $actor);

        $this->moveQueue($closed, QueueStage::COMPLETED, $actor);

        return $closed;
    }

    public function assertWritable(ClinicVisit $visit): void
    {
        if (! $visit->isOpen()) {
            throw ValidationException::withMessages([
                'visit' => "Kunjungan {$visit->visit_number} sudah {$visit->statusEnum()->label()}; catatan klinis tidak dapat ditambah.",
            ]);
        }
    }

    /** BMI = berat / (tinggi dalam meter)². */
    public function bmi(null|int|float|string $weightKg, null|int|float|string $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null) {
            return null;
        }

        $weight = (float) $weightKg;
        $height = (float) $heightCm / 100;

        if ($weight <= 0 || $height <= 0) {
            return null;
        }

        return round($weight / ($height * $height), 2);
    }

    /** @param array<string, mixed> $data */
    private function resolvePatient(array $data, User $actor): ClinicPatient
    {
        if (! empty($data['patient_id'])) {
            return ClinicPatient::query()->findOrFail((int) $data['patient_id']);
        }

        if (! empty($data['student_id'])) {
            return $this->patients->forStudent((int) $data['student_id'], $actor);
        }

        throw ValidationException::withMessages([
            'patient_id' => 'Kunjungan harus merujuk pada pasien (patient_id) atau siswa (student_id).',
        ]);
    }

    private function resolveClinic(?int $clinicId): ClinicLocation
    {
        if ($clinicId !== null) {
            $clinic = ClinicLocation::query()->find($clinicId);

            if ($clinic === null) {
                throw ValidationException::withMessages(['clinic_id' => 'Lokasi layanan tidak ditemukan.']);
            }

            return $clinic;
        }

        $clinic = ClinicLocation::default();

        if ($clinic === null) {
            throw ValidationException::withMessages([
                'clinic_id' => 'Belum ada lokasi layanan aktif. Tambahkan UKS/klinik terlebih dahulu.',
            ]);
        }

        return $clinic;
    }

    /** Buat baris antrean; nomor antrean diurut per hari per lokasi. */
    private function enqueue(ClinicVisit $visit, bool $isEmergency): void
    {
        $today = now()->toDateString();

        $sequence = ClinicQueueEntry::query()
            ->where('clinic_id', $visit->clinic_id)
            ->whereDate('queue_date', $today)
            ->count() + 1;

        ClinicQueueEntry::query()->create([
            'visit_id' => $visit->id,
            'clinic_id' => $visit->clinic_id,
            // Gawat darurat tidak ikut nomor antrean biasa: langsung dipanggil.
            'queue_number' => $isEmergency ? 'DARURAT' : str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'sequence' => $sequence,
            'queue_date' => $today,
            'stage' => $isEmergency ? QueueStage::IN_EXAMINATION->value : QueueStage::WAITING->value,
            'started_at' => $isEmergency ? now() : null,
        ]);
    }

    private function nextFreeVisitSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_visits', 'visit_at', now()->toDateString());

        while (ClinicVisit::withTrashed()->where('visit_number', ClinicNumber::visit($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
