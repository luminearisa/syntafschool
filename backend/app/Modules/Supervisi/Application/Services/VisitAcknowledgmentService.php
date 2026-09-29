<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\VisitStatus;
use App\Modules\Supervisi\Models\SupAcknowledgment;
use App\Modules\Supervisi\Models\SupDispute;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tanda tangan digital sederhana & hak tanggapan.
 *
 * Guru menandai "sudah dibaca dan diterima" (dengan sidik jari kriptografis
 * atas isi snapshot), lalu boleh mengajukan keberatan dalam tenggat tertentu.
 * Keberatan tidak menghapus nilai: status kegiatan berubah menjadi DISPUTED
 * dan moderator memutuskan, semuanya tercatat untuk audit.
 */
class VisitAcknowledgmentService
{
    public const DISPUTE_STATUSES = ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED', 'WITHDRAWN'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SupervisiSettings $settings,
    ) {}

    /** Tandai hasil sudah dibaca/diterima oleh yang disupervisi. */
    public function acknowledge(SupVisit $visit, Employee $employee, ?string $note = null, ?string $ip = null): SupAcknowledgment
    {
        $this->assertFinalized($visit);

        if ((int) $visit->supervisee_employee_id !== (int) $employee->id) {
            $this->fail('employee_id', 'Hanya pegawai yang disupervisi yang dapat menandai hasil ini.');
        }

        $existing = $visit->acknowledgments()->where('employee_id', $employee->id)->first();

        if ($existing?->acknowledged_at !== null) {
            $this->fail('visit', 'Hasil ini sudah Anda tandai sebelumnya.');
        }

        $acknowledgedAt = now();

        $acknowledgment = SupAcknowledgment::query()->updateOrCreate(
            ['visit_id' => $visit->id, 'employee_id' => $employee->id],
            [
                'acknowledged_at' => $acknowledgedAt,
                'signature_hash' => $this->signature($visit, $employee, $acknowledgedAt->toIso8601String()),
                'note' => $note,
                'ip_address' => $ip,
            ],
        );

        $this->audit->log('visit.acknowledged', $visit, null, [
            'employee_id' => $employee->id,
            'signature_hash' => $acknowledgment->signature_hash,
        ], null, null);

        return $acknowledgment;
    }

    /**
     * Ajukan keberatan atas hasil yang sudah difinalkan.
     *
     * @param  array<string,mixed>  $data
     */
    public function dispute(SupVisit $visit, Employee $employee, array $data): SupDispute
    {
        $this->assertFinalized($visit);

        if ((int) $visit->supervisee_employee_id !== (int) $employee->id) {
            $this->fail('employee_id', 'Hanya pegawai yang disupervisi yang dapat mengajukan keberatan.');
        }

        $acknowledgment = $visit->acknowledgments()->where('employee_id', $employee->id)->first();

        if ($acknowledgment?->acknowledged_at === null) {
            $this->fail('visit', 'Tandai hasil terlebih dahulu sebelum mengajukan keberatan.');
        }

        $window = (int) $this->settings->get('dispute_window_days', 7);
        if ($window > 0 && $visit->finalized_at !== null && $visit->finalized_at->diffInDays(now()) > $window) {
            $this->fail('visit', "Masa pengajuan keberatan ({$window} hari) sudah berakhir.");
        }

        if ($visit->disputes()->whereIn('status', ['OPEN', 'UNDER_REVIEW'])->exists()) {
            $this->fail('visit', 'Masih ada keberatan yang belum diputuskan pada kegiatan ini.');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $this->fail('reason', 'Alasan keberatan wajib diisi.');
        }

        return DB::transaction(function () use ($visit, $employee, $data, $reason): SupDispute {
            $dispute = $visit->disputes()->create([
                'raised_by_employee_id' => $employee->id,
                'reason' => $reason,
                'proposed_scores' => $data['proposed_scores'] ?? null,
                'attachments' => $data['attachments'] ?? null,
                'status' => 'OPEN',
            ]);

            $before = ['status' => $visit->status->value, 'is_disputed' => $visit->is_disputed];
            $visit->forceFill(['status' => VisitStatus::DISPUTED->value, 'is_disputed' => true])->save();

            $this->audit->log('visit.disputed', $visit, $before, [
                'status' => VisitStatus::DISPUTED->value,
                'dispute_id' => $dispute->id,
            ], $reason, null);

            return $dispute;
        });
    }

    /**
     * Putuskan keberatan (moderator/pemegang izin `supervisi.dispute.resolve`).
     *
     * @param  array<string,mixed>  $data
     */
    public function resolve(SupDispute $dispute, User $actor, array $data): SupDispute
    {
        $status = strtoupper((string) ($data['status'] ?? 'REJECTED'));

        if (! in_array($status, self::DISPUTE_STATUSES, true)) {
            $this->fail('status', 'Keputusan keberatan tidak dikenal.');
        }

        if ($dispute->status !== 'OPEN' && $dispute->status !== 'UNDER_REVIEW') {
            $this->fail('status', 'Keberatan ini sudah diputuskan sebelumnya.');
        }

        $note = trim((string) ($data['decision_note'] ?? ''));
        if ($note === '') {
            $this->fail('decision_note', 'Catatan keputusan wajib diisi.');
        }

        return DB::transaction(function () use ($dispute, $actor, $status, $note, $data): SupDispute {
            $visit = $dispute->visit;

            $dispute->forceFill([
                'status' => $status,
                'moderator_employee_id' => $data['moderator_employee_id'] ?? null,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ])->save();

            $accepted = in_array($status, ['ACCEPTED', 'PARTIALLY_ACCEPTED'], true);

            if ($visit !== null) {
                $before = ['status' => $visit->status->value, 'is_disputed' => $visit->is_disputed];

                $visit->forceFill([
                    // Diterima → kembali ke analisis untuk penilaian ulang;
                    // ditolak/ditarik → hasil lama berlaku kembali.
                    'status' => $accepted ? VisitStatus::ANALYZED->value : VisitStatus::COMPLETED->value,
                    'is_disputed' => false,
                ])->save();

                $this->audit->log('visit.dispute_resolved', $visit, $before, [
                    'status' => $visit->status->value,
                    'dispute_status' => $status,
                    'decision_note' => $note,
                ], null, $actor);
            }

            return $dispute->refresh();
        });
    }

    /** Sidik jari isi hasil: nomor kegiatan + pegawai + checksum snapshot + waktu. */
    protected function signature(SupVisit $visit, Employee $employee, string $timestamp): string
    {
        $checksum = (string) ($visit->snapshot['checksum'] ?? '');

        return hash('sha256', implode('|', [$visit->code, $employee->id, $checksum, $timestamp]));
    }

    protected function assertFinalized(SupVisit $visit): void
    {
        if ($visit->finalized_at === null) {
            $this->fail('visit', 'Hasil supervisi belum difinalkan oleh pelaksana.');
        }
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
