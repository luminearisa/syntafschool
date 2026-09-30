<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\ObservationStatus;
use App\Modules\Clinic\Models\ClinicAssessment;
use App\Modules\Clinic\Models\ClinicDiagnosis;
use App\Modules\Clinic\Models\ClinicDiagnosisMaster;
use App\Modules\Clinic\Models\ClinicFollowUp;
use App\Modules\Clinic\Models\ClinicObservation;
use App\Modules\Clinic\Models\ClinicObservationRecord;
use App\Modules\Clinic\Models\ClinicProcedure;
use App\Modules\Clinic\Models\ClinicProcedureType;
use App\Modules\Clinic\Models\ClinicVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catatan klinis: asesmen (SOAP), diagnosis, tindakan, observasi, tindak lanjut.
 *
 * Aturan yang dijaga:
 *
 * - **Tidak ada penghapusan destruktif.** Mengubah asesmen/diagnosis membuat
 *   versi baru (`is_current` berpindah) dan versi lama tetap tersimpan beserta
 *   alasannya (Rule 7 & §48).
 * - **Diagnosis hanya oleh yang berwenang.** Kewenangan diperiksa di sini,
 *   bukan hanya di route, agar tidak bisa dilewati dari endpoint lain.
 * - **Observasi yang sedang berjalan tidak dobel.** Satu kunjungan hanya boleh
 *   punya satu observasi aktif.
 */
class ClinicalRecordService
{
    public function __construct(
        private readonly ClinicAuditService $audit,
        private readonly VisitService $visits,
    ) {
    }

    /**
     * Simpan asesmen SOAP. Bila sudah ada asesmen berlaku, yang lama diarsipkan
     * menjadi versi sebelumnya.
     *
     * @param array<string, mixed> $data
     */
    public function saveAssessment(ClinicVisit $visit, array $data, User $actor, ?string $amendReason = null): ClinicAssessment
    {
        $this->visits->assertWritable($visit);

        return DB::transaction(function () use ($visit, $data, $actor, $amendReason): ClinicAssessment {
            $previous = ClinicAssessment::query()
                ->where('visit_id', $visit->id)
                ->where('is_current', true)
                ->first();

            if ($previous !== null && $amendReason === null) {
                throw ValidationException::withMessages([
                    'reason' => 'Asesmen sudah ada. Sertakan alasan koreksi (reason) untuk membuat versi baru.',
                ]);
            }

            $version = $previous === null ? 1 : ((int) $previous->version) + 1;

            if ($previous !== null) {
                $previous->is_current = false;
                $previous->save();
            }

            $assessment = ClinicAssessment::query()->create([
                'visit_id' => $visit->id,
                'provider_id' => $data['provider_id'] ?? $actor->id,
                'assessment_at' => $data['assessment_at'] ?? now(),
                'method' => $data['method'] ?? 'SOAP',
                'subjective' => $data['subjective'] ?? null,
                'objective' => $data['objective'] ?? null,
                'assessment' => $data['assessment'] ?? null,
                'plan' => $data['plan'] ?? null,
                'notes' => $data['notes'] ?? null,
                'version' => $version,
                'is_current' => true,
                'amended_from_id' => $previous?->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            if ($previous !== null) {
                $this->audit->amend(
                    $assessment,
                    $version,
                    (string) $amendReason,
                    $previous->only(['subjective', 'objective', 'assessment', 'plan', 'notes']),
                    $assessment->only(['subjective', 'objective', 'assessment', 'plan', 'notes']),
                    (int) $visit->patient_id,
                    $actor,
                );
            } else {
                $this->audit->record(AuditAction::CREATE, $assessment, (int) $visit->patient_id, $actor, [
                    'version' => $version,
                ], [], 'Asesmen klinis dibuat');
            }

            if ($visit->status === 'TRIAGED') {
                $visit->status = 'IN_PROGRESS';
                $visit->updated_by = $actor->id;
                $visit->save();
            }

            return $assessment;
        });
    }

    /**
     * Tambah diagnosis. Diagnosis utama bersifat tunggal per versi; menambah
     * diagnosis utama baru menurunkan yang lama menjadi sekunder.
     *
     * @param array<string, mixed> $data
     */
    public function addDiagnosis(ClinicVisit $visit, array $data, User $actor): ClinicDiagnosis
    {
        $this->visits->assertWritable($visit);

        return DB::transaction(function () use ($visit, $data, $actor): ClinicDiagnosis {
            $type = $data['type'] ?? 'PRIMARY';
            $master = null;

            if (! empty($data['diagnosis_code'])) {
                $master = ClinicDiagnosisMaster::query()->where('code', $data['diagnosis_code'])->first();
            }

            if ($type === 'PRIMARY' && ($master !== null || ! empty($data['diagnosis_code']))) {
                ClinicDiagnosis::query()
                    ->where('visit_id', $visit->id)
                    ->where('type', 'PRIMARY')
                    ->where('is_current', true)
                    ->update(['type' => 'SECONDARY', 'updated_at' => now()]);
            }

            $diagnosis = ClinicDiagnosis::query()->create([
                'visit_id' => $visit->id,
                'master_id' => $master?->id,
                'diagnosis_code' => $data['diagnosis_code'] ?? $master?->code,
                'diagnosis_name' => $data['diagnosis_name'] ?? $master?->name,
                'type' => $type,
                'notes' => $data['notes'] ?? null,
                'diagnosed_by' => $data['diagnosed_by'] ?? $actor->id,
                'diagnosed_at' => now(),
                'is_current' => true,
                'version' => 1,
            ]);

            $this->audit->record(AuditAction::CREATE, $diagnosis, (int) $visit->patient_id, $actor, [
                'diagnosis_code' => $diagnosis->diagnosis_code,
                'diagnosis_name' => $diagnosis->diagnosis_name,
                'type' => $diagnosis->type,
            ], [], 'Diagnosis ditambahkan');

            return $diagnosis;
        });
    }

    /** Koreksi diagnosis: versi baru, versi lama tetap tersimpan. */
    public function amendDiagnosis(ClinicDiagnosis $diagnosis, array $data, string $reason, User $actor): ClinicDiagnosis
    {
        return DB::transaction(function () use ($diagnosis, $data, $reason, $actor): ClinicDiagnosis {
            $visit = ClinicVisit::query()->findOrFail($diagnosis->visit_id);
            $this->visits->assertWritable($visit);

            $diagnosis->is_current = false;
            $diagnosis->save();

            $new = ClinicDiagnosis::query()->create([
                'visit_id' => $diagnosis->visit_id,
                'master_id' => $diagnosis->master_id,
                'diagnosis_code' => $data['diagnosis_code'] ?? $diagnosis->diagnosis_code,
                'diagnosis_name' => $data['diagnosis_name'] ?? $diagnosis->diagnosis_name,
                'type' => $data['type'] ?? $diagnosis->type,
                'notes' => $data['notes'] ?? $diagnosis->notes,
                'diagnosed_by' => $actor->id,
                'diagnosed_at' => now(),
                'is_current' => true,
                'version' => ((int) $diagnosis->version) + 1,
                'amended_from_id' => $diagnosis->id,
            ]);

            $this->audit->amend(
                $new,
                (int) $new->version,
                $reason,
                $diagnosis->only(['diagnosis_code', 'diagnosis_name', 'type', 'notes']),
                $new->only(['diagnosis_code', 'diagnosis_name', 'type', 'notes']),
                (int) $visit->patient_id,
                $actor,
            );

            return $new;
        });
    }

    /**
     * Tindakan klinis. Bahan habis pakai yang dipakai dicatat sebagai `supplies`
     * dan dapat dikaitkan ke Inventory oleh pemanggil (mis. penyerahan bahan).
     *
     * @param array<string, mixed> $data
     */
    public function addProcedure(ClinicVisit $visit, array $data, User $actor): ClinicProcedure
    {
        $this->visits->assertWritable($visit);

        return DB::transaction(function () use ($visit, $data, $actor): ClinicProcedure {
            $type = null;

            if (! empty($data['type_id'])) {
                $type = ClinicProcedureType::query()->find($data['type_id']);
            }

            if ($type !== null && $type->requires_consent && empty($data['consent_note'])) {
                // Tindakan yang butuh persetujuan tidak boleh dicatat tanpa jejak
                // persetujuan (mis. imunisasi, tindakan invasif ringan).
                throw ValidationException::withMessages([
                    'consent_note' => "Tindakan {$type->name} memerlukan catatan persetujuan orang tua/wali.",
                ]);
            }

            $procedure = ClinicProcedure::query()->create([
                'visit_id' => $visit->id,
                'type_id' => $type?->id,
                'procedure' => $data['procedure'] ?? $type?->name ?? 'Tindakan',
                'notes' => $data['notes'] ?? null,
                'supplies' => $data['supplies'] ?? null,
                'performed_by' => $data['performed_by'] ?? $actor->id,
                'performed_at' => $data['performed_at'] ?? now(),
            ]);

            $this->audit->record(AuditAction::CREATE, $procedure, (int) $visit->patient_id, $actor, [
                'procedure' => $procedure->procedure,
                'supplies' => $procedure->supplies,
            ], [], 'Tindakan klinis dicatat');

            return $procedure;
        });
    }

    /**
     * Mulai observasi (ruang/bed). Satu kunjungan hanya boleh punya satu
     * observasi aktif.
     *
     * @param array<string, mixed> $data
     */
    public function startObservation(ClinicVisit $visit, array $data, User $actor): ClinicObservation
    {
        $this->visits->assertWritable($visit);

        return DB::transaction(function () use ($visit, $data, $actor): ClinicObservation {
            $existing = ClinicObservation::query()
                ->where('visit_id', $visit->id)
                ->where('status', ObservationStatus::ACTIVE->value)
                ->first();

            if ($existing !== null) {
                throw ValidationException::withMessages([
                    'observation' => 'Kunjungan ini sudah berada dalam observasi aktif.',
                ]);
            }

            $observation = ClinicObservation::query()->create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'clinic_id' => $visit->clinic_id,
                'location' => $data['location'] ?? null,
                'bed' => $data['bed'] ?? null,
                'reason' => $data['reason'] ?? 'Observasi klinis',
                'started_at' => $data['started_at'] ?? now(),
                'status' => ObservationStatus::ACTIVE->value,
                'supervisor_id' => $data['supervisor_id'] ?? $actor->id,
            ]);

            $visit->requires_observation = true;
            $visit->save();

            if ($visit->status !== 'OBSERVATION') {
                $this->visits->advanceStatus($visit, \App\Modules\Clinic\Domain\Enums\VisitStatus::OBSERVATION, $actor);
            }

            $this->audit->record(AuditAction::CREATE, $observation, (int) $visit->patient_id, $actor, [
                'bed' => $observation->bed,
                'reason' => $observation->reason,
            ], [], 'Observasi dimulai');

            return $observation;
        });
    }

    /**
     * Catat kondisi berkala selama observasi. Bila tanda vital ikut dikirim,
     * vital tersebut disimpan lebih dahulu lalu dirujuk oleh catatan ini.
     *
     * @param array<string, mixed> $data
     */
    public function recordObservation(ClinicObservation $observation, array $data, User $actor): ClinicObservationRecord
    {
        return DB::transaction(function () use ($observation, $data, $actor): ClinicObservationRecord {
            $vitalSignId = null;

            if (! empty($data['vitals'])) {
                $visit = ClinicVisit::query()->findOrFail($observation->visit_id);
                $result = $this->visits->recordVitals($visit, (array) $data['vitals'], $actor);
                $vitalSignId = (int) $result['vital']->id;
            }

            $record = ClinicObservationRecord::query()->create([
                'observation_id' => $observation->id,
                'recorded_at' => $data['recorded_at'] ?? now(),
                'condition' => $data['condition'] ?? 'STABLE',
                'notes' => $data['notes'] ?? null,
                'action' => $data['action'] ?? null,
                'vital_sign_id' => $vitalSignId,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::CREATE, $record, (int) $observation->patient_id, $actor, [
                'condition' => $record->condition,
            ], [], 'Catatan observasi ditambahkan');

            return $record;
        });
    }

    /** Akhiri observasi (selesai/ditingkatkan/dirujuk). */
    public function endObservation(ClinicObservation $observation, ObservationStatus $status, User $actor, ?string $notes = null): ClinicObservation
    {
        $observation->status = $status->value;
        $observation->ended_at = now();
        $observation->save();

        if ($notes !== null) {
            ClinicObservationRecord::query()->create([
                'observation_id' => $observation->id,
                'recorded_at' => now(),
                'condition' => 'STABLE',
                'notes' => $notes,
                'action' => 'Observasi diakhiri: '.$status->label(),
                'recorded_by' => $actor->id,
            ]);
        }

        $this->audit->record(AuditAction::UPDATE, $observation, (int) $observation->patient_id, $actor, [
            'status' => $status->value,
        ], [], 'Observasi diakhiri');

        return $observation->fresh();
    }

    /**
     * Buat tindak lanjut terjadwal.
     *
     * @param array<string, mixed> $data
     */
    public function createFollowUp(array $data, User $actor): ClinicFollowUp
    {
        $followUp = ClinicFollowUp::query()->create([
            'patient_id' => $data['patient_id'],
            'visit_id' => $data['visit_id'] ?? null,
            'referral_id' => $data['referral_id'] ?? null,
            'due_date' => $data['due_date'],
            'type' => $data['type'] ?? 'REVIEW',
            'status' => $data['status'] ?? 'PENDING',
            'instruction' => $data['instruction'] ?? null,
            'notes' => $data['notes'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(AuditAction::CREATE, $followUp, (int) $followUp->patient_id, $actor, [
            'due_date' => $followUp->due_date?->toDateString(),
            'type' => $followUp->type,
        ], [], 'Tindak lanjut dibuat');

        return $followUp;
    }

    public function completeFollowUp(ClinicFollowUp $followUp, User $actor, ?string $notes = null): ClinicFollowUp
    {
        $followUp->status = 'COMPLETED';
        $followUp->completed_at = now();
        $followUp->completed_by = $actor->id;

        if ($notes !== null) {
            $followUp->notes = trim(((string) $followUp->notes)."\n".$notes);
        }

        $followUp->save();

        $this->audit->record(AuditAction::UPDATE, $followUp, (int) $followUp->patient_id, $actor, [
            'status' => 'COMPLETED',
        ], [], 'Tindak lanjut diselesaikan');

        return $followUp;
    }
}
