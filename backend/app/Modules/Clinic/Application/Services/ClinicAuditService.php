<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Models\ClinicRecordAmendment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Jejak audit & amendment catatan klinis.
 *
 * Dua janji yang dijaga kelas ini:
 *
 * 1. **Tidak ada perubahan senyap.** Setiap akses baris pasien dan setiap
 *    perubahan catatan penting meninggalkan baris di `clinic_audit_logs`.
 * 2. **Tidak ada penghapusan destruktif.** Koreksi catatan klinis menambah
 *    versi baru dan menyimpan nilai sebelumnya di `clinic_record_amendments`.
 */
class ClinicAuditService
{
    /**
     * Catat satu peristiwa audit.
     *
     * @param array<string, mixed>|null $changes
     * @param array<string, mixed> $context
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        ?int $patientId = null,
        ?User $actor = null,
        ?array $changes = null,
        array $context = [],
        ?string $reason = null,
    ): void {
        $actor ??= $this->currentUser();

        DB::table('clinic_audit_logs')->insert([
            'action' => $action->value,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'patient_id' => $patientId,
            'actor_id' => $actor?->id,
            'actor_role' => $this->roleLabel($actor),
            'access_reason' => $reason,
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'context' => json_encode($context + [
                'ip' => request()?->ip(),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 255),
                'request_id' => request()?->header('X-Request-Id'),
            ], JSON_UNESCAPED_UNICODE),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log terstruktur untuk observability (dibaca ops/tim keamanan).
        Log::info('clinic.audit', [
            'action' => $action->value,
            'subject' => $subject === null ? null : $subject::class.'#'.$subject->getKey(),
            'patient_id' => $patientId,
            'actor_id' => $actor?->id,
        ]);
    }

    /** Catat akses baca ke data pasien (dipakai endpoint yang membuka rekam medis). */
    public function recordView(?Model $subject, int $patientId, ?User $actor = null, ?string $reason = null): void
    {
        $this->record(AuditAction::VIEW, $subject, $patientId, $actor, null, [], $reason);
    }

    /**
     * Simpan amendment: nilai lama, nilai baru, alasan, pelaku, dan versi.
     *
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $new
     */
    public function amend(
        Model $record,
        int $version,
        string $reason,
        array $previous,
        array $new,
        ?int $patientId = null,
        ?User $actor = null,
    ): void {
        $actor ??= $this->currentUser();

        ClinicRecordAmendment::query()->create([
            'record_type' => $record::class,
            'record_id' => $record->getKey(),
            'version' => $version,
            'reason' => $reason,
            'previous_value' => $this->sanitize($previous),
            'new_value' => $this->sanitize($new),
            'patient_id' => $patientId,
            'changed_by' => $actor?->id,
            'changed_at' => now(),
        ]);

        $this->record(
            AuditAction::AMEND,
            $record,
            $patientId,
            $actor,
            ['previous' => $this->sanitize($previous), 'new' => $this->sanitize($new)],
            [],
            $reason,
        );
    }

    /**
     * Buang field yang tidak boleh masuk jejak audit: berkas mentah, password,
     * dan nilai biner besar. Jejak audit harus dapat dibaca manusia.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $blocked = ['password', 'remember_token', 'file', 'content'];

        return collect($values)
            ->reject(fn ($value, $key): bool => in_array((string) $key, $blocked, true))
            ->map(fn ($value) => is_string($value) && mb_strlen($value) > 2000 ? mb_substr($value, 0, 2000).'…' : $value)
            ->all();
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function roleLabel(?User $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        $role = $actor->getRoleNames()->first();

        return $role === null ? null : mb_substr((string) $role, 0, 40);
    }
}
