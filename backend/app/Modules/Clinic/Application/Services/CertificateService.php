<?php

namespace App\Modules\Clinic\Application\Services;

use App\Models\User;
use App\Modules\Clinic\Application\Support\ClinicNumber;
use App\Modules\Clinic\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Clinic\Domain\Enums\AuditAction;
use App\Modules\Clinic\Domain\Enums\CertificateStatus;
use App\Modules\Clinic\Models\ClinicDocument;
use App\Modules\Clinic\Models\ClinicHealthCertificate;
use App\Modules\Clinic\Models\ClinicPatient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Surat keterangan kesehatan / clearance & dokumen klinis.
 *
 * Alur surat dibuat eksplisit: **diminta → ditinjau → disetujui/ditolak →
 * diterbitkan**. Surat tidak pernah langsung "terbit" dari permintaan, karena
 * penerbitan adalah pernyataan klinis yang harus disetujui tenaga berwenang.
 *
 * Setiap surat punya kode verifikasi unik sehingga pihak luar (panitia kegiatan,
 * penyelenggara lomba) dapat memeriksa keasliannya tanpa membuka rekam medis.
 */
class CertificateService
{
    public function __construct(
        private readonly ClinicAuditService $audit,
        private readonly DocumentProviderInterface $documents,
        private readonly ClinicNotificationService $notifications,
    ) {
    }

    /**
     * Ajukan permintaan surat keterangan.
     *
     * @param array<string, mixed> $data
     */
    public function request(ClinicPatient $patient, array $data, User $actor): ClinicHealthCertificate
    {
        return DB::transaction(function () use ($patient, $data, $actor): ClinicHealthCertificate {
            $certificate = ClinicHealthCertificate::query()->create([
                'certificate_number' => ClinicNumber::certificate($this->nextSequence()),
                'patient_id' => $patient->id,
                'visit_id' => $data['visit_id'] ?? null,
                'type' => $data['type'] ?? 'RETURN_TO_SCHOOL',
                'status' => CertificateStatus::REQUESTED->value,
                'valid_from' => $data['valid_from'] ?? now()->toDateString(),
                'valid_until' => $data['valid_until'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'restrictions' => null,
                'recommendation' => null,
                'verification_code' => ClinicNumber::verificationCode(),
                'requested_by' => $actor->id,
                'version' => 1,
            ]);

            $this->audit->record(AuditAction::CREATE, $certificate, (int) $patient->id, $actor, [
                'certificate_number' => $certificate->certificate_number,
                'type' => $certificate->type,
            ], [], 'Permintaan surat keterangan dibuat');

            return $certificate;
        });
    }

    /**
     * Tinjau permintaan: setujui (dengan ringkasan klinis) atau tolak.
     *
     * @param array<string, mixed> $data
     */
    public function review(ClinicHealthCertificate $certificate, array $data, User $actor): ClinicHealthCertificate
    {
        if (in_array($certificate->status, [CertificateStatus::APPROVED->value, CertificateStatus::REJECTED->value], true)
            && empty($data['reason'])) {
            throw ValidationException::withMessages([
                'reason' => 'Surat sudah ditinjau. Sertakan alasan untuk mengubah keputusan.',
            ]);
        }

        $approve = (bool) ($data['approve'] ?? true);

        $certificate->status = $approve ? CertificateStatus::APPROVED->value : CertificateStatus::REJECTED->value;
        $certificate->clinical_summary = $data['clinical_summary'] ?? $certificate->clinical_summary;
        $certificate->restrictions = $data['restrictions'] ?? $certificate->restrictions;
        $certificate->recommendation = $data['recommendation'] ?? $certificate->recommendation;
        $certificate->rejection_reason = $approve ? null : ($data['reason'] ?? 'Tidak memenuhi syarat');
        $certificate->reviewed_by = $actor->id;
        $certificate->valid_from = $data['valid_from'] ?? $certificate->valid_from;
        $certificate->valid_until = $data['valid_until'] ?? $certificate->valid_until;
        $certificate->save();

        $this->audit->record(AuditAction::UPDATE, $certificate, (int) $certificate->patient_id, $actor, [
            'status' => $certificate->status,
            'reason' => $data['reason'] ?? null,
        ], [], $approve ? 'Surat keterangan disetujui' : 'Surat keterangan ditolak');

        return $certificate->fresh();
    }

    /** Terbitkan surat yang sudah disetujui dan beri tahu orang tua. */
    public function issue(ClinicHealthCertificate $certificate, User $actor): ClinicHealthCertificate
    {
        if ($certificate->status !== CertificateStatus::APPROVED->value) {
            throw ValidationException::withMessages([
                'status' => 'Hanya surat yang sudah disetujui dapat diterbitkan.',
            ]);
        }

        $certificate->status = CertificateStatus::APPROVED->value;
        $certificate->issued_by = $actor->id;
        $certificate->issued_at = now();
        $certificate->save();

        $this->audit->record(AuditAction::PRINT, $certificate, (int) $certificate->patient_id, $actor, [
            'certificate_number' => $certificate->certificate_number,
            'verification_code' => $certificate->verification_code,
        ], [], 'Surat keterangan diterbitkan');

        $this->notifications->notifyCertificateReady($certificate, $actor);

        return $certificate->fresh();
    }

    /**
     * Simpan dokumen kesehatan (versi baru menandai versi lama sebagai bukan berlaku).
     *
     * @param array<string, mixed> $data
     */
    public function storeDocument(?ClinicPatient $patient, UploadedFile $file, array $data, User $actor): ClinicDocument
    {
        if ($patient === null) {
            throw ValidationException::withMessages(['patient_id' => 'Dokumen kesehatan harus terhubung ke pasien.']);
        }

        $type = $data['type'] ?? 'OTHER';
        $stored = $this->documents->store($file, (int) $patient->id, (string) $type);

        return DB::transaction(function () use ($patient, $stored, $data, $actor, $type): ClinicDocument {
            $previous = ClinicDocument::query()
                ->where('patient_id', $patient->id)
                ->where('type', $type)
                ->where('is_current', true)
                ->first();

            if ($previous !== null) {
                $previous->is_current = false;
                $previous->save();
            }

            $document = ClinicDocument::query()->create([
                'document_number' => ClinicNumber::document($this->nextDocumentSequence()),
                'documentable_type' => $data['documentable_type'] ?? null,
                'documentable_id' => $data['documentable_id'] ?? null,
                'patient_id' => $patient->id,
                'type' => $type,
                'title' => $data['title'] ?? $stored['original_name'],
                'disk' => $stored['disk'],
                'path' => $stored['path'],
                'original_name' => $stored['original_name'],
                'mime_type' => $stored['mime_type'],
                'size_bytes' => $stored['size_bytes'],
                'version' => $previous === null ? 1 : ((int) $previous->version) + 1,
                'is_current' => true,
                'replaces_id' => $previous?->id,
                // Dokumen kesehatan default hanya untuk lingkungan klinis;
                // menaikkan visibilitas adalah keputusan sadar petugas.
                'visibility' => $data['visibility'] ?? 'CLINICAL',
                'is_signed' => (bool) ($data['is_signed'] ?? false),
                'uploaded_by' => $actor->id,
            ]);

            $this->audit->record(AuditAction::CREATE, $document, (int) $patient->id, $actor, [
                'document_number' => $document->document_number,
                'type' => $document->type,
                'version' => $document->version,
                'visibility' => $document->visibility,
            ], [], 'Dokumen kesehatan diunggah');

            return $document;
        });
    }

    /** Isi berkas untuk diunduh — pemanggil sudah memeriksa otorisasi. */
    public function read(ClinicDocument $document, ?User $actor = null): string
    {
        $this->audit->record(AuditAction::SHARE, $document, (int) $document->patient_id, $actor, [
            'document_number' => $document->document_number,
        ], [], 'Dokumen kesehatan diunduh');

        return $this->documents->read($document->disk, $document->path);
    }

    private function nextSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_health_certificates', 'created_at', now()->toDateString());

        while (ClinicHealthCertificate::withTrashed()->where('certificate_number', ClinicNumber::certificate($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }

    private function nextDocumentSequence(): int
    {
        $sequence = ClinicNumber::nextSequence('clinic_documents', 'created_at', now()->toDateString());

        while (ClinicDocument::withTrashed()->where('document_number', ClinicNumber::document($sequence))->exists()) {
            $sequence++;
        }

        return $sequence;
    }
}
