<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\EvidenceKind;
use App\Modules\Supervisi\Domain\Enums\VisibilityScope;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Bukti supervisi: foto kegiatan, rekaman, dokumen perangkat, atau tautan.
 *
 * Berkas disimpan pada disk `public` di bawah `supervisi/{tahun}/{kode}` dan
 * hanya tautan relatifnya yang tercatat di basis data; lampiran bersifat
 * pribadi (`SUPERVISEE`) secara bawaan sehingga tidak bocor ke pihak lain.
 */
class VisitEvidenceService
{
    public const KINDS = ['PHOTO', 'VIDEO', 'AUDIO', 'DOCUMENT', 'LINK'];

    public const VISIBILITIES = ['SUPERVISOR', 'SUPERVISEE', 'INTERNAL', 'PUBLIC'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VisitLifecycleService $lifecycle,
    ) {}

    /**
     * Tambahkan bukti (unggahan berkas atau tautan).
     *
     * @param  array<string,mixed>  $data
     */
    public function store(SupVisit $visit, array $data, User $actor): SupVisitEvidence
    {
        $this->lifecycle->assertEditable($visit);

        $kind = strtoupper((string) ($data['kind'] ?? 'DOCUMENT'));
        $visibility = strtoupper((string) ($data['visibility'] ?? 'SUPERVISEE'));

        if (! in_array($kind, self::KINDS, true)) {
            $this->fail('kind', 'Jenis bukti tidak dikenal.');
        }

        if (! in_array($visibility, self::VISIBILITIES, true)) {
            $this->fail('visibility', 'Cakupan visibilitas bukti tidak dikenal.');
        }

        $file = $data['file'] ?? null;
        $url = trim((string) ($data['url'] ?? ''));

        if ($file === null && $url === '') {
            $this->fail('file', 'Unggah berkas atau isi tautan bukti.');
        }

        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->fail('url', 'Tautan bukti tidak valid.');
        }

        $path = null;
        if ($file instanceof UploadedFile) {
            $path = $file->store('supervisi/'.$visit->observed_at?->format('Y') ?? now()->format('Y').'/'.$visit->code, 'public');
            $kind = $kind === 'LINK' ? 'DOCUMENT' : $kind;
        }

        $evidence = $visit->evidence()->create([
            'visit_answer_id' => $data['visit_answer_id'] ?? null,
            'kind' => $kind,
            'path' => $path,
            'url' => $url !== '' ? $url : null,
            'caption' => $data['caption'] ?? null,
            'visibility' => $visibility,
            'uploaded_by' => $actor->id,
        ]);

        $this->audit->log('visit.evidence_added', $visit, null, [
            'evidence_id' => $evidence->id,
            'kind' => $kind,
        ], null, $actor);

        return $evidence;
    }

    public function destroy(SupVisit $visit, SupVisitEvidence $evidence, User $actor): void
    {
        $this->lifecycle->assertEditable($visit);

        if ((int) $evidence->visit_id !== (int) $visit->id) {
            $this->fail('evidence', 'Bukti ini bukan milik kegiatan tersebut.');
        }

        if ($evidence->path !== null) {
            Storage::disk('public')->delete($evidence->path);
        }

        $evidenceId = $evidence->id;
        $evidence->delete();

        $this->audit->log('visit.evidence_removed', $visit, ['evidence_id' => $evidenceId], null, null, $actor);
    }

    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
