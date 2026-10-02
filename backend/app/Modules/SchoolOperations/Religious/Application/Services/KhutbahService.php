<?php

namespace App\Modules\SchoolOperations\Religious\Application\Services;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\DocumentGatewayInterface;
use App\Modules\SchoolOperations\Religious\Domain\Support\ReligiousContext;
use App\Modules\SchoolOperations\Religious\Models\{Khutbah, KhutbahCategory, KhutbahVersion};
use Illuminate\Validation\ValidationException;

/**
 * Khutbah (§29-§31, §92): siklus hidup draft → review → approve
 * → schedule → delivered → arsip, versi dokumen, dan pustaka khutbah.
 */
class KhutbahService
{
    public function __construct(protected ReligiousContext $context, protected DocumentGatewayInterface $documents)
    {
    }

    public function seedCategories(): int
    {
        $count = 0;
        foreach ((array) config('religious.khutbah.categories', []) as $code => $definition) {
            KhutbahCategory::query()->updateOrCreate(
                ['code' => strtoupper((string) $code)],
                [
                    'name' => is_array($definition) ? ($definition['name'] ?? $code) : $definition,
                    'description' => is_array($definition) ? ($definition['description'] ?? null) : null,
                    'sort_order' => $count,
                    'active' => true,
                ]
            );
            $count++;
        }

        return $count;
    }

    public function create(array $payload): Khutbah
    {
        $khutbah = Khutbah::query()->create([
            'code' => $payload['code'] ?? $this->context->nextCode('KHB', 'khutbahs'),
            'title' => $payload['title'],
            'theme' => $payload['theme'] ?? null,
            'category_id' => $payload['category_id'] ?? KhutbahCategory::query()->where('code', strtoupper((string) ($payload['category_code'] ?? '')))->value('id'),
            'category_code' => isset($payload['category_code']) ? strtoupper((string) $payload['category_code']) : null,
            'khatib_id' => $payload['khatib_id'] ?? null,
            'khatib_name' => $payload['khatib_name'] ?? null,
            'mosque_id' => $payload['mosque_id'] ?? null,
            'prayer_date' => $payload['prayer_date'] ?? null,
            'language' => $payload['language'] ?? config('religious.khutbah.default_language', 'id'),
            'duration_minutes' => $payload['duration_minutes'] ?? null,
            'reference_text' => $payload['reference_text'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'content' => $payload['content'] ?? null,
            'status' => 'DRAFT',
            'version_number' => 1,
            'created_by' => $this->context->actorId(),
            'notes' => $payload['notes'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        $this->context->audit('religious.khutbah.created', $khutbah, ['mosque_id' => $khutbah->mosque_id]);

        return $khutbah;
    }

    /** Simpan versi baru isi khutbah (§30) — riwayat tidak pernah hilang. */
    public function newVersion(Khutbah $khutbah, array $payload): Khutbah
    {
        KhutbahVersion::query()->create([
            'khutbah_id' => $khutbah->getKey(),
            'version_number' => (int) $khutbah->version_number,
            'title' => $khutbah->title,
            'content' => $khutbah->content,
            'document_id' => $khutbah->document_id,
            'change_note' => $payload['change_note'] ?? null,
            'changed_by' => $this->context->actorId(),
            'status' => $khutbah->status,
            'created_at' => now(),
        ]);

        $khutbah->fill(array_filter([
            'title' => $payload['title'] ?? null,
            'content' => $payload['content'] ?? null,
            'summary' => $payload['summary'] ?? null,
            'reference_text' => $payload['reference_text'] ?? null,
        ], fn ($v): bool => $v !== null));

        $khutbah->forceFill(['version_number' => (int) $khutbah->version_number + 1])->save();

        $this->context->audit('religious.khutbah.version_created', $khutbah, [
            'version' => $khutbah->version_number, 'change_note' => $payload['change_note'] ?? null,
        ]);

        return $khutbah;
    }

    /** Lampirkan dokumen (PDF/DOCX/teks) dan simpan versinya. */
    public function attachDocument(Khutbah $khutbah, array $payload): array
    {
        $result = $this->documents->store([
            'path' => $payload['path'] ?? ('khutbah/'.$khutbah->code.'-v'.$khutbah->version_number),
            'title' => $khutbah->title,
            'type' => 'KHUTBAH',
            'reference' => $khutbah->code,
            'mime' => $payload['mime'] ?? null,
        ]);

        if (! empty($result['id'])) {
            $khutbah->forceFill(['document_id' => $result['id']])->save();
        }

        $this->context->audit('religious.khutbah.document_attached', $khutbah, ['document' => $result]);

        return ['khutbah_id' => $khutbah->getKey(), 'document' => $result, 'provider_available' => $this->documents->available()];
    }

    /** Alur persetujuan khutbah (§79). */
    public function transition(Khutbah $khutbah, string $target, array $payload = []): Khutbah
    {
        $target = strtoupper($target);
        $allowed = (array) config('religious.khutbah.transitions.'.strtoupper((string) $khutbah->status), []);
        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'Perpindahan status '.$khutbah->status.' → '.$target.' tidak diizinkan.',
            ]);
        }

        $fill = ['status' => $target];
        if ($target === 'REVIEW') {
            $fill['reviewed_by'] = $this->context->actorId();
            $fill['reviewed_at'] = now();
        }
        if ($target === 'APPROVED') {
            $fill['approved_by'] = $this->context->actorId();
            $fill['approved_at'] = now();
        }
        if ($target === 'DELIVERED') {
            $fill['delivered_at'] = now();
        }
        if (! empty($payload['prayer_date'])) {
            $fill['prayer_date'] = $payload['prayer_date'];
        }

        $khutbah->forceFill($fill)->save();

        $this->context->audit('religious.khutbah.'.strtolower($target), $khutbah, ['status' => $target], $payload['reason'] ?? null);
        $this->context->publish(match ($target) {
            'APPROVED' => 'KhutbahApproved',
            'DELIVERED' => 'KhutbahDelivered',
            default => 'KhutbahStatusChanged',
        }, $khutbah, ['status' => $target]);

        return $khutbah;
    }

    /** Pustaka khutbah dengan pencarian (§31). */
    public function library(array $filters = []): array
    {
        $query = Khutbah::query();

        if (! empty($filters['term'])) {
            $query->where(fn ($q) => $q->where('title', 'like', '%'.$filters['term'].'%')
                ->orWhere('theme', 'like', '%'.$filters['term'].'%')
                ->orWhere('summary', 'like', '%'.$filters['term'].'%')
                ->orWhere('content', 'like', '%'.$filters['term'].'%'));
        }
        if (! empty($filters['category_code'])) {
            $query->where('category_code', strtoupper((string) $filters['category_code']));
        }
        if (! empty($filters['khatib_id'])) {
            $query->where('khatib_id', $filters['khatib_id']);
        }
        if (! empty($filters['language'])) {
            $query->where('language', $filters['language']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('prayer_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('prayer_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('prayer_date')->limit((int) ($filters['limit'] ?? 100))->get()
            ->map(fn (Khutbah $k): array => [
                'id' => $k->getKey(),
                'code' => $k->code,
                'title' => $k->title,
                'theme' => $k->theme,
                'category_code' => $k->category_code,
                'khatib_name' => $k->khatib_name,
                'prayer_date' => $k->prayer_date?->toDateString(),
                'language' => $k->language,
                'status' => $k->status,
                'version' => (int) $k->version_number,
                'has_document' => $k->document_id !== null,
            ])->all();
    }

    public function versions(int $khutbahId): array
    {
        return KhutbahVersion::query()->where('khutbah_id', $khutbahId)
            ->orderByDesc('version_number')->get()
            ->map(fn (KhutbahVersion $v): array => [
                'version_number' => $v->version_number,
                'title' => $v->title,
                'change_note' => $v->change_note,
                'status' => $v->status,
                'created_at' => $v->created_at?->toIso8601String(),
            ])->all();
    }
}
