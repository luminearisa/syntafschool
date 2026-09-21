<?php

namespace App\Services\Ppdb;

use App\Http\Resources\PpdbApplicantDocumentResource;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbRegistration;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dokumen calon: unggah ber-versi, tinjau (review), dan rangkum kelengkapan.
 *
 * Versi lama tidak pernah dihapus — unggah ulang membuat baris baru dengan
 * `version = max + 1` dan `is_active = true`, versi sebelumnya menjadi
 * `is_active = false` (rekam jejak audit tetap utuh).
 *
 * Berkas disimpan pada disk `public` dengan pola
 * `ppdb/documents/{registration_id}/{requirement_id}/v{version}/{nama-aman}`,
 * kolom `file_path` menyimpan path RELATIF; URL dibuat saat respons.
 */
class PpdbDocumentService
{
    /**
     * Batas kapan calon boleh mengunggah/mengganti dokumen: selama pendaftaran
     * masih dapat diedit (draft, need_revision) DAN selama berkas masih dalam
     * proses verifikasi panitia (submitted, under_review). Setelah terverifikasi
     * dokumen dikunci — perbaikan hanya lewat permintaan revisi.
     */
    public const UPLOADABLE_STATUSES = [
        PpdbRegistration::STATUS_DRAFT,
        PpdbRegistration::STATUS_NEED_REVISION,
        PpdbRegistration::STATUS_SUBMITTED,
        PpdbRegistration::STATUS_UNDER_REVIEW,
    ];

    public function __construct(
        private readonly PpdbScopeService $scope,
        private readonly PpdbRegistrationStateMachine $machine,
        private readonly PpdbNotificationService $notifications,
    ) {
    }

    /**
     * Persyaratan dokumen yang berlaku untuk satu pendaftaran (sudah di-resolve
     * per program & gelombang) + dokumen aktif & seluruh versinya.
     *
     * @return array{
     *     requirements: list<array<string, mixed>>,
     *     unlinked: list<array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     can_upload: bool
     * }
     */
    public function annotated(PpdbRegistration $registration): array
    {
        $requirements = $this->scope->documentRequirements(
            (int) $registration->period_id,
            $registration->program_id === null ? null : (int) $registration->program_id,
            $registration->wave_id === null ? null : (int) $registration->wave_id,
        );

        $versions = PpdbApplicantDocument::query()
            ->where('registration_id', $registration->id)
            ->orderByDesc('version')
            ->get()
            ->groupBy(fn (PpdbApplicantDocument $document) => (int) $document->requirement_id);

        $rows = [];
        $known = [];

        foreach ($requirements as $requirement) {
            $id = (int) $requirement['id'];
            $known[] = $id;
            $history = $versions->get($id, collect());
            $active = $history->firstWhere('is_active', true);

            $rows[] = $requirement + [
                'status' => $active?->status ?? PpdbRegistration::DOCUMENT_NOT_UPLOADED,
                'status_label' => PpdbRegistrationQueryService::documentItemLabel($active?->status),
                'document' => $active === null ? null : $this->present($active),
                'versions' => $history->map(fn (PpdbApplicantDocument $document) => $this->present($document))->values()->all(),
                'version_count' => $history->count(),
                'uploaded' => $active !== null,
            ];
        }

        // Berkas yang requirement-nya sudah tidak ada di scope aktif. `except()`
        // punya `Illuminate\Database\Eloquent\Collection` menyaring berdasarkan
        // KEY MODEL, bukan kunci array — dan di sini nilainya adalah kumpulan
        // versi, jadi saringnya harus eksplisit per kunci requirement.
        $unlinked = $versions
            ->reject(fn ($history, $requirementId) => in_array((int) $requirementId, $known, true))
            ->flatten()
            ->where('is_active', true)
            ->map(fn (PpdbApplicantDocument $document) => $this->present($document))
            ->values()
            ->all();

        return [
            'requirements' => $rows,
            'unlinked_documents' => $unlinked,
            'summary' => $this->summarize($rows),
            'can_upload' => $this->canUpload($registration),
        ];
    }

    /**
     * Ringkasan kelengkapan dokumen.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summarize(array $rows): array
    {
        $required = array_values(array_filter($rows, static fn (array $row) => (bool) ($row['required'] ?? false)));
        $missing = array_values(array_filter(
            $required,
            static fn (array $row): bool => ! ($row['uploaded'] ?? false),
        ));

        return [
            'total' => count($rows),
            'required_total' => count($required),
            'uploaded' => count(array_filter($rows, static fn (array $row) => (bool) ($row['uploaded'] ?? false))),
            'approved' => count(array_filter($rows, static fn (array $row) => ($row['status'] ?? '') === PpdbApplicantDocument::STATUS_APPROVED)),
            'rejected' => count(array_filter($rows, static fn (array $row) => ($row['status'] ?? '') === PpdbApplicantDocument::STATUS_REJECTED)),
            'under_review' => count(array_filter($rows, static fn (array $row) => ($row['status'] ?? '') === PpdbApplicantDocument::STATUS_UNDER_REVIEW)),
            'missing' => array_map(
                static fn (array $row) => ['id' => $row['id'], 'name' => $row['name'], 'required' => $row['required']],
                $missing,
            ),
            'complete' => $missing === [],
        ];
    }

    public function canUpload(PpdbRegistration $registration): bool
    {
        return in_array((string) $registration->status, self::UPLOADABLE_STATUSES, true);
    }

    /**
     * Syarat dokumen yang berlaku untuk pendaftaran, sebagai collection larik.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function requirementsFor(PpdbRegistration $registration): Collection
    {
        return $this->scope->documentRequirements(
            (int) $registration->period_id,
            $registration->program_id === null ? null : (int) $registration->program_id,
            $registration->wave_id === null ? null : (int) $registration->wave_id,
        );
    }

    /**
     * Find the requirement definition inside the registration's resolved scope.
     */
    public function findRequirement(PpdbRegistration $registration, int $requirementId): ?array
    {
        return $this->requirementsFor($registration)->first(
            static fn (array $requirement): bool => (int) $requirement['id'] === $requirementId
        );
    }

    /**
     * Unggah dokumen (membuat versi baru).
     *
     * @param  array<string, mixed>  $requirement
     */
    public function upload(PpdbRegistration $registration, array $requirement, UploadedFile $file, User $actor): PpdbApplicantDocument
    {
        if (! $this->canUpload($registration)) {
            throw ValidationException::withMessages([
                'file' => 'Dokumen tidak dapat diunggah pada status pendaftaran "'.
                    PpdbRegistrationQueryService::statusLabel((string) $registration->status).'".'.
                    ' Status yang diizinkan: '.
                    implode(', ', array_map(
                        [PpdbRegistrationQueryService::class, 'statusLabel'],
                        self::UPLOADABLE_STATUSES,
                    )),
            ]);
        }

        $requirementId = (int) $requirement['id'];
        $version = (int) PpdbApplicantDocument::withTrashed()
            ->where('registration_id', $registration->id)
            ->where('requirement_id', $requirementId)
            ->max('version') + 1;

        $directory = 'ppdb/documents/'.$registration->id.'/'.$requirementId.'/v'.$version;
        $stored = Storage::disk('public')->putFileAs($directory, $file, $this->safeName($file, $version));

        if ($stored === false) {
            throw ValidationException::withMessages([
                'file' => 'Berkas gagal disimpan. Silakan coba unggah ulang.',
            ]);
        }

        // Versi lama menjadi tidak aktif; berkas & barisnya TIDAK dihapus.
        PpdbApplicantDocument::query()
            ->where('registration_id', $registration->id)
            ->where('requirement_id', $requirementId)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        $document = PpdbApplicantDocument::create([
            'registration_id' => $registration->id,
            'applicant_id' => $registration->applicant_id,
            'requirement_id' => $requirementId,
            'requirement_name' => (string) $requirement['name'],
            'version' => $version,
            'is_active' => true,
            'file_path' => $stored,
            'original_filename' => Str::limit((string) $file->getClientOriginalName(), 250, ''),
            'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
            'file_size' => (int) $file->getSize(),
            'status' => PpdbApplicantDocument::STATUS_UPLOADED,
            'review_note' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
            'uploaded_at' => now(),
            'uploaded_by' => $actor->id,
        ]);

        $this->machine->syncDocumentStatus($registration);
        $registration->save();
        $this->machine->record(
            $registration,
            'document.uploaded',
            sprintf('%s versi %d diunggah oleh %s.', $requirement['name'], $version, $actor->name),
            $actor,
        );

        return $document;
    }

    /**
     * Tinjau satu dokumen (panitia). Status `document_status` pendaftaran ikut
     * dihitung ulang, dan penolakan diberitahukan ke orang tua.
     */
    public function review(PpdbApplicantDocument $document, string $status, ?string $note, User $actor): PpdbApplicantDocument
    {
        $document->forceFill([
            'status' => $status,
            'review_note' => $note,
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
        ])->save();

        $registration = $document->registration;

        if ($registration !== null) {
            $this->machine->syncDocumentStatus($registration);
            $registration->save();

            $this->machine->record(
                $registration,
                'document.'.$status,
                sprintf('%s versi %d %s%s.', (string) $document->requirement_name, (int) $document->version,
                    PpdbRegistrationQueryService::documentItemLabel($status),
                    $note !== null && $note !== '' ? ' — '.$note : ''),
                $actor,
            );

            if ($status === PpdbApplicantDocument::STATUS_REJECTED) {
                $this->notifications->aboutRegistration(
                    $registration,
                    PpdbActivityNotification::TYPE_DOCUMENT_REJECTED,
                    'Dokumen ditolak',
                    sprintf(
                        'Dokumen "%s" pada pendaftaran %s ditolak%s. Silakan unggah ulang.',
                        (string) $document->requirement_name,
                        (string) $registration->registration_number,
                        $note !== null && $note !== '' ? ' dengan alasan: '.$note : '',
                    ),
                    [
                        'document_id' => $document->id,
                        'requirement_id' => $document->requirement_id,
                        'requirement_name' => $document->requirement_name,
                        'version' => $document->version,
                        'reason' => $note,
                    ],
                );
            }
        }

        return $document->refresh();
    }

    /**
     * Bentuk seragam satu dokumen untuk respons API.
     *
     * @return array<string, mixed>
     */
    public function present(PpdbApplicantDocument $document): array
    {
        return PpdbApplicantDocumentResource::row($document);
    }

    /**
     * Nama berkas aman: slug nama asli + ekstensi aslinya.
     */
    private function safeName(UploadedFile $file, int $version): string
    {
        $original = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = Str::lower((string) $file->getClientOriginalExtension());
        $name = Str::slug($original) ?: 'dokumen';

        return $name.'-v'.$version.($extension !== '' ? '.'.$extension : '');
    }
}
