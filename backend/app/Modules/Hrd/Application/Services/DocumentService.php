<?php

namespace App\Modules\Hrd\Application\Services;

use App\Models\User;
use App\Modules\Hrd\Application\Support\HrdSettingsService;
use App\Modules\Hrd\Domain\Contracts\DocumentStorageProviderInterface;
use App\Modules\Hrd\Domain\Enums\DocumentVisibility;
use App\Modules\Hrd\Domain\Exceptions\HrdAccessDeniedException;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrDocumentType;
use App\Modules\Hrd\Models\HrEmployee;
use App\Modules\Hrd\Models\HrEmployeeDocument;
use App\Modules\Hrd\Models\HrEmployeeDocumentVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Dokumen pegawai: unggah, versi, verifikasi, kedaluwarsa, dan akses (§31, §32, §52).
 *
 * Dua hal yang tidak dapat dilanggar dari luar:
 *
 *  1. **Berkas tidak pernah ditimpa.** Unggahan baru menjadi *versi baru*; versi
 *     lama tetap ada beserta checksum, alasan, dan siapa yang mengunggah. Kontrak
 *     kerja yang diperpanjang tiga kali tetap dapat dibuka dalam bentuk aslinya.
 *  2. **Akses berkas tidak lewat URL publik.** Berkas disimpan pada disk privat;
 *     tautan yang diberikan adalah tautan bertanda tangan berbatas waktu, dan
 *     hanya diberikan setelah pemeriksaan izin + tingkat kerahasiaan dokumen.
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentStorageProviderInterface $storage,
        private readonly HrdSettingsService $settings,
        private readonly HrdTimelineService $timeline,
        private readonly HrdAuditService $audit,
    ) {}

    /**
     * Unggah dokumen baru (versi pertama).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upload(HrEmployee $employee, UploadedFile $file, array $attributes, User $actor): HrEmployeeDocument
    {
        $documentType = HrDocumentType::query()->find($attributes['document_type_id']);

        if ($documentType === null) {
            throw HrdValidationException::forField('document_type_id', 'Jenis dokumen tidak ditemukan.', 'hr.document.type_missing');
        }

        $this->assertFileAcceptable($file);

        if ($documentType->requires_number && empty($attributes['document_number'])) {
            throw HrdValidationException::forField('document_number', "Dokumen \"{$documentType->name}\" wajib memiliki nomor.", 'hr.document.number_required');
        }

        if ($documentType->requires_expiry && empty($attributes['expiry_date'])) {
            throw HrdValidationException::forField('expiry_date', "Dokumen \"{$documentType->name}\" wajib memiliki tanggal berakhir.", 'hr.document.expiry_required');
        }

        return DB::transaction(function () use ($employee, $file, $attributes, $actor, $documentType): HrEmployeeDocument {
            $document = HrEmployeeDocument::query()->create([
                'employee_id' => $employee->getKey(),
                'document_type_id' => $documentType->getKey(),
                'title' => $attributes['title'] ?? $documentType->name,
                'document_number' => $attributes['document_number'] ?? null,
                'issued_by' => $attributes['issued_by'] ?? null,
                'issue_date' => $attributes['issue_date'] ?? null,
                'expiry_date' => $attributes['expiry_date'] ?? null,
                'status' => 'active',
                'visibility' => $attributes['visibility'] ?? $documentType->default_visibility ?? 'hr',
                'current_version' => 1,
                'verified' => false,
                'notes' => $attributes['notes'] ?? null,
                'uploaded_by' => $actor->getKey(),
            ]);

            $this->storeVersion($document, $file, $actor, $attributes['version_reason'] ?? 'Unggahan pertama');

            $this->timeline->record($employee, 'document.uploaded', 'Dokumen diunggah: '.($document->title ?? $documentType->name), [
                'category' => 'document',
                'source_type' => HrEmployeeDocument::class,
                'source_id' => $document->getKey(),
                'visibility' => $this->visibilityToTimeline($document->visibility),
                'actor' => $actor,
                'meta' => ['document_type' => $documentType->code, 'version' => 1],
            ]);

            $this->audit->log($actor, 'document.uploaded', $document, null, $document->toArray(), null, null, (int) $employee->getKey());

            return $document->refresh();
        });
    }

    /** Unggah versi baru dokumen yang sudah ada (§32). */
    public function addVersion(HrEmployeeDocument $document, UploadedFile $file, User $actor, ?string $reason = null): HrEmployeeDocumentVersion
    {
        $this->assertFileAcceptable($file);

        return DB::transaction(function () use ($document, $file, $actor, $reason): HrEmployeeDocumentVersion {
            $document = HrEmployeeDocument::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            $nextVersion = (int) $document->current_version + 1;

            HrEmployeeDocumentVersion::query()
                ->where('document_id', $document->getKey())
                ->update(['is_current' => false, 'status' => 'superseded']);

            $version = $this->storeVersion($document, $file, $actor, $reason ?? 'Pembaruan berkas', $nextVersion);

            $document->forceFill([
                'current_version' => $nextVersion,
                'status' => 'active',
                // Berkas berubah berarti verifikasi lama tidak lagi berlaku.
                'verified' => false,
                'verified_by' => null,
                'verified_at' => null,
                'verification_notes' => null,
            ])->save();

            $this->timeline->record($document->employee_id, 'document.version_added', 'Versi baru dokumen: '.($document->title ?? '')." (v{$nextVersion})", [
                'category' => 'document',
                'source_type' => HrEmployeeDocumentVersion::class,
                'source_id' => $version->getKey(),
                'visibility' => $this->visibilityToTimeline($document->visibility),
                'actor' => $actor,
                'meta' => ['version' => $nextVersion, 'reason' => $reason],
            ]);

            $this->audit->log($actor, 'document.version_added', $version, null, $version->toArray(), $reason, null, (int) $document->employee_id);

            return $version->refresh();
        });
    }

    /** Tandai dokumen sudah diperiksa terhadap berkas aslinya (§31 "verification"). */
    public function verify(HrEmployeeDocument $document, User $actor, ?string $notes = null): HrEmployeeDocument
    {
        $before = $document->toArray();

        $document->forceFill([
            'verified' => true,
            'verified_by' => $actor->getKey(),
            'verified_at' => now(),
            'verification_notes' => $notes,
            'status' => $this->recalculateStatus($document),
        ])->save();

        $this->timeline->record($document->employee_id, 'document.verified', 'Dokumen diverifikasi: '.($document->title ?? ''), [
            'category' => 'document',
            'source_type' => HrEmployeeDocument::class,
            'source_id' => $document->getKey(),
            'visibility' => 'employee',
            'actor' => $actor,
        ]);

        $this->audit->log($actor, 'document.verified', $document, $before, $document->toArray(), $notes, null, (int) $document->employee_id);

        return $document->refresh();
    }

    /** Batalkan verifikasi (mis. berkas ternyata tidak sah). */
    public function revokeVerification(HrEmployeeDocument $document, User $actor, string $reason): HrEmployeeDocument
    {
        if ($reason === '') {
            throw HrdValidationException::forField('reason', 'Alasan pembatalan verifikasi wajib diisi.', 'hr.document.reason_required');
        }

        $before = $document->toArray();

        $document->forceFill([
            'verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'verification_notes' => $reason,
        ])->save();

        $this->audit->log($actor, 'document.verification_revoked', $document, $before, $document->toArray(), $reason, null, (int) $document->employee_id);

        return $document->refresh();
    }

    /**
     * Tautan unduh berbatas waktu, setelah pemeriksaan izin (§52).
     */
    public function downloadUrl(HrEmployeeDocument $document, int $userId, int $maxRank, bool $isOwnerOrHr): string
    {
        if (! $isOwnerOrHr && DocumentVisibility::from($document->visibility)->rank() > $maxRank) {
            throw HrdAccessDeniedException::forScope('document', 'dokumen ini');
        }

        $version = HrEmployeeDocumentVersion::query()
            ->where('document_id', $document->getKey())
            ->where('is_current', true)
            ->firstOrFail();

        $this->audit->logEvent(
            User::query()->find($userId),
            'document.downloaded',
            HrEmployeeDocument::class,
            $document->getKey(),
            ['version' => $version->version],
        );

        $ttl = (int) $this->settings->integer('documents.signed_url_ttl_minutes', 15);
        $url = $this->storage->temporaryUrl($version->file_path, $ttl);

        if ($url === null) {
            // Penyimpanan tanpa tautan bertanda tangan: berkas tetap hanya dapat
            // diambil lewat endpoint ber-otorisasi milik modul HRD.
            throw HrdValidationException::forField('file', 'Penyimpanan tidak mendukung tautan bertanda tangan; gunakan endpoint unduh ber-otorisasi.', 'hr.document.no_signed_url');
        }

        return $url;
    }

    /** Hitung ulang status kedaluwarsa — dipanggil penjadwal harian (§70). */
    public function recalculateStatus(HrEmployeeDocument $document): string
    {
        if (in_array($document->status, ['revoked', 'replaced', 'archived'], true)) {
            return $document->status;
        }

        if ($document->expiry_date === null) {
            return 'active';
        }

        $warningDays = (int) $this->settings->integer('expiry_warning_days', 60);

        return match (true) {
            $document->expiry_date->isPast() => 'expired',
            $document->expiry_date->diffInDays(now()) <= $warningDays => 'expiring',
            default => 'active',
        };
    }

    /** Dokumen kedaluwarsa/akan kedaluwarsa (§47 Lifecycle, §48 laporan). */
    public function expiring(int $days = 60, ?int $organizationId = null): array
    {
        return HrEmployeeDocument::query()
            ->with(['employee.person:id,full_name', 'documentType:id,name,code,category', 'employee:id,employee_number,person_id,organization_id'])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->when($organizationId !== null, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('organization_id', $organizationId)))
            ->orderBy('expiry_date')
            ->get()
            ->map(fn (HrEmployeeDocument $document) => [
                'id' => (int) $document->getKey(),
                'employee_id' => (int) $document->employee_id,
                'employee_name' => $document->employee?->displayName(),
                'employee_number' => $document->employee?->employee_number,
                'document_type' => $document->documentType?->name,
                'document_number' => $document->document_number,
                'expiry_date' => $document->expiry_date?->toDateString(),
                'days_left' => $document->expiry_date === null ? null : (int) now()->startOfDay()->diffInDays($document->expiry_date, false),
                'verified' => (bool) $document->verified,
                'visibility' => $document->visibility,
            ])
            ->all();
    }

    /* ── Bantu ──────────────────────────────────────────────────────────── */

    private function storeVersion(HrEmployeeDocument $document, UploadedFile $file, User $actor, string $reason, ?int $version = null): HrEmployeeDocumentVersion
    {
        $directory = 'employees/'.$document->employee_id.'/documents/'.$document->getKey();
        $path = $this->storage->store($file, $directory);

        return HrEmployeeDocumentVersion::query()->create([
            'document_id' => $document->getKey(),
            'version' => $version ?? (int) $document->current_version,
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            'reason' => $reason,
            'is_current' => true,
            'status' => 'active',
            'uploaded_by' => $actor->getKey(),
            'uploaded_at' => now(),
        ]);
    }

    private function assertFileAcceptable(UploadedFile $file): void
    {
        $maxKb = (int) $this->settings->integer('documents.max_size_kb', 10240);

        if ($file->getSize() > $maxKb * 1024) {
            throw HrdValidationException::forField('file', "Ukuran berkas melebihi batas {$maxKb} KB.", 'hr.document.too_large');
        }

        $allowed = (array) $this->settings->get('documents.allowed_mimes', ['pdf', 'jpg', 'jpeg', 'png']);
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, $allowed, true)) {
            throw HrdValidationException::forField('file', 'Jenis berkas tidak diizinkan: '.implode(', ', $allowed).'.', 'hr.document.mime_not_allowed');
        }
    }

    private function visibilityToTimeline(string $visibility): string
    {
        return in_array($visibility, ['employee', 'public'], true) ? 'employee' : 'hr';
    }
}
