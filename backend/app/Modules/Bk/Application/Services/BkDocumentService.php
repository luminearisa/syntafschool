<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Contracts\DocumentProviderInterface;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\CaseEventType;
use App\Modules\Bk\Domain\Enums\ConfidentialityLevel;
use App\Modules\Bk\Domain\Enums\DocumentType;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkCaseDocument;
use Illuminate\Http\UploadedFile;

/**
 * Dokumen kasus — metadata di BK, isi berkas di `DocumentProviderInterface`.
 *
 * Dokumen mewarisi kerahasiaan kasusnya bila tidak ditentukan lain, dan
 * pengunduhan selalu melewati `BkAccessService`. Dokumen yang ditandai sensitif
 * tidak dapat diunduh walau pemanggil memegang izin daftar dokumen.
 */
class BkDocumentService
{
    public function __construct(
        private readonly DocumentProviderInterface $storage,
        private readonly BkTimelineService $timeline,
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /**
     * Unggah dokumen ke kasus.
     *
     * @param  array<string, mixed>  $data
     */
    public function store(User $actor, BkCase $case, UploadedFile $file, array $data): BkCaseDocument
    {
        $this->access->assertCanViewCase($actor, $case, 'Mengunggah dokumen kasus');

        $type = DocumentType::from($data['document_type'] ?? DocumentType::CASE_ATTACHMENT->value);
        $visibility = isset($data['visibility'])
            ? ConfidentialityLevel::from($data['visibility'])
            : $case->confidentiality_level;

        // Dokumen tidak boleh lebih terbuka daripada kasusnya.
        if ($visibility->rank() < $case->confidentiality_level->rank()) {
            $visibility = $case->confidentiality_level;
        }

        $isSensitive = (bool) ($data['is_sensitive'] ?? false) || $visibility->isSecret();

        if ($isSensitive && ! $this->access->canViewCounselingContent($actor, $case)) {
            throw new BkValidationException(['is_sensitive' => ['Dokumen rahasia hanya dapat diunggah pemegang akses konseling.']]);
        }

        $stored = $this->storage->store(
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName(),
            ['mime' => $file->getClientMimeType()],
        );

        $document = BkCaseDocument::query()->create([
            'case_id' => $case->id,
            'referral_id' => $case->source_referral_id,
            'student_id' => $case->student_id,
            'document_type' => $type->value,
            'title' => $data['title'] ?? $file->getClientOriginalName(),
            'provider' => $stored['provider'],
            'provider_reference' => $stored['reference'],
            'mime_type' => $stored['mime'] ?? $file->getClientMimeType(),
            'byte_size' => $stored['size'],
            'checksum' => $stored['checksum'],
            'document_date' => $data['document_date'] ?? now()->toDateString(),
            'visibility' => $visibility->value,
            'is_sensitive' => $isSensitive,
            'shared_with' => $data['shared_with'] ?? null,
            'uploaded_by' => $actor->id,
            'notes' => $data['notes'] ?? null,
        ]);

        $this->timeline->record(CaseEventType::DOCUMENT_ADDED, $actor, $case,
            summary: $type->label().' diunggah: '.$document->title, related: $document);

        $this->audit->record(AuditAction::DOCUMENT_ADD, $document, $actor, [
            'confidentiality_level' => $visibility->value,
            'reason' => $data['reason'] ?? null,
        ]);

        return $document;
    }

    /** Baca isi dokumen (dengan pemeriksaan kerahasiaan + jejak audit). */
    public function contents(User $actor, BkCaseDocument $document): string
    {
        $this->access->assertCanViewDocument($actor, $document);

        $contents = $this->storage->contents($document->provider_reference);

        if ($contents === null) {
            throw new BkValidationException(['document' => ['Berkas dokumen tidak ditemukan pada penyimpanan.']]);
        }

        return $contents;
    }

    public function delete(User $actor, BkCaseDocument $document): void
    {
        $this->access->assertCanViewDocument($actor, $document);

        if (! $this->access->canViewCounselingContent($actor, $document->case)) {
            throw new BkValidationException(['document' => ['Hanya pemegang akses konseling yang dapat menghapus dokumen ini.']]);
        }

        $this->storage->delete($document->provider_reference);

        $this->audit->record(AuditAction::DELETE, $document, $actor, ['reason' => 'Menghapus dokumen kasus']);

        $document->delete();
    }

    /** @return list<BkCaseDocument> hanya dokumen yang boleh dilihat pengguna */
    public function forCase(User $actor, BkCase $case): array
    {
        $this->access->assertCanViewCase($actor, $case, 'Membuka dokumen kasus');

        return $case->documents()
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (BkCaseDocument $document): bool => $this->access->canViewDocument($actor, $document))
            ->values()
            ->all();
    }
}
