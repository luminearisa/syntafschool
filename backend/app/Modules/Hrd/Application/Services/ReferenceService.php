<?php

namespace App\Modules\Hrd\Application\Services;

use App\Modules\Hrd\Domain\Contracts\HrReferenceProviderInterface;
use App\Modules\Hrd\Domain\Exceptions\HrdValidationException;
use App\Modules\Hrd\Models\HrReferenceGroup;
use App\Modules\Hrd\Models\HrReferenceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Referensi yang dapat dikonfigurasi (§18, §41, §87).
 *
 * Jenis organisasi, worker type, employment type, status kepegawaian, alasan
 * keluar, hubungan keluarga, dan jenjang pendidikan hidup di sini — bukan sebagai
 * `enum` di kode. Akibatnya sekolah dapat menambah "Guru Kontrak Yayasan" sendiri,
 * dan aturan validasi tetap dapat memeriksa keabsahan nilai tanpa menghafal daftar.
 *
 * Cache berisi **array biasa**, bukan model Eloquent: menyimpan model ke dalam
 * cache membuat modul ini gagal ketika kelasnya belum termuat (mis. setelah
 * pemasangan baru atau saat kelas dipindahkan). Nilai yang di-cache harus selalu
 * dapat dibaca tanpa asumsi urutan pemuatan kelas.
 */
class ReferenceService implements HrReferenceProviderInterface
{
    private const CACHE_TTL_MINUTES = 30;

    /** @var list<string> */
    public const SYSTEM_GROUPS = [
        'organization_type', 'worker_type', 'employment_type', 'employee_status',
        'exit_reason', 'relationship_type', 'education_level', 'skill_proficiency',
        'position_type', 'document_category', 'assignment_type', 'transaction_reason',
    ];

    /**
     * Nilai referensi aktif pada satu kelompok.
     *
     * @return list<array{code: string, label: string, description: ?string, sort_order: int, is_default: bool, meta: array<string, mixed>}>
     */
    public function items(string $groupCode): array
    {
        return $this->cachedItems($groupCode, true);
    }

    /** @return list<array<string, mixed>> */
    public function allItems(string $groupCode): array
    {
        return $this->cachedItems($groupCode, false);
    }

    public function label(string $groupCode, ?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        foreach ($this->cachedItems($groupCode, false) as $item) {
            if ($item['code'] === $code) {
                return $item['label'];
            }
        }

        return $code;
    }

    public function isValid(string $groupCode, ?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        foreach ($this->cachedItems($groupCode, true) as $item) {
            if ($item['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function defaultCode(string $groupCode): ?string
    {
        $items = $this->cachedItems($groupCode, true);

        foreach ($items as $item) {
            if ($item['is_default'] === true) {
                return $item['code'];
            }
        }

        return $items[0]['code'] ?? null;
    }

    /** @return array<string, mixed> */
    public function metadata(string $groupCode): array
    {
        $default = $this->defaultCode($groupCode);

        foreach ($this->cachedItems($groupCode, true) as $item) {
            if ($item['code'] === $default) {
                return $item['meta'];
            }
        }

        return [];
    }

    /** Pastikan kode sah, atau hentikan dengan pesan yang menyebut pilihan yang ada. */
    public function assertValid(string $groupCode, ?string $code, string $field): void
    {
        if ($code === null || $code === '') {
            return;
        }

        if ($this->isValid($groupCode, $code)) {
            return;
        }

        $available = array_map(fn (array $item) => $item['code'], $this->items($groupCode));

        throw HrdValidationException::forField(
            $field,
            "Nilai \"{$code}\" tidak dikenal pada kelompok {$groupCode}. Pilihan yang tersedia: ".implode(', ', $available).'.',
            'hr.reference.invalid_code',
        );
    }

    /** @return Collection<int, HrReferenceGroup> */
    public function groups(): Collection
    {
        return HrReferenceGroup::query()->orderBy('name')->get();
    }

    /** Tambah/perbarui satu nilai referensi (§81 Administration → Status, Worker Types, …). */
    public function upsertItem(string $groupCode, string $code, array $attributes): HrReferenceItem
    {
        HrReferenceGroup::query()->firstOrCreate(
            ['code' => $groupCode],
            [
                'name' => str_replace('_', ' ', ucfirst($groupCode)),
                'is_system' => in_array($groupCode, self::SYSTEM_GROUPS, true),
            ],
        );

        $item = HrReferenceItem::query()->updateOrCreate(
            ['group_code' => $groupCode, 'code' => $code],
            [
                'label' => $attributes['label'] ?? $code,
                'description' => $attributes['description'] ?? null,
                'sort_order' => $attributes['sort_order'] ?? 0,
                'is_active' => $attributes['is_active'] ?? true,
                'is_default' => $attributes['is_default'] ?? false,
                'meta' => $attributes['meta'] ?? null,
            ],
        );

        if (($attributes['is_default'] ?? false) === true) {
            HrReferenceItem::query()
                ->where('group_code', $groupCode)
                ->whereKeyNot($item->getKey())
                ->update(['is_default' => false]);
        }

        $this->forget($groupCode);

        return $item;
    }

    /** Nonaktifkan nilai tanpa menghapusnya: data lama tetap menunjuk kode yang sah. */
    public function deactivateItem(string $groupCode, string $code): bool
    {
        $item = HrReferenceItem::query()->where('group_code', $groupCode)->where('code', $code)->first();

        if ($item === null) {
            return false;
        }

        // Nilai sistem tidak dihapus — hanya dinonaktifkan — supaya baris historis
        // yang menunjuk kode tersebut tetap dapat dibaca.
        if ($item->is_system) {
            $item->forceFill(['is_active' => false])->save();
        } else {
            $item->delete();
        }

        $this->forget($groupCode);

        return true;
    }

    public function forget(?string $groupCode = null): void
    {
        $groups = $groupCode === null ? self::SYSTEM_GROUPS : [$groupCode];

        foreach ($groups as $group) {
            Cache::forget("hrd-reference:{$group}:active");
            Cache::forget("hrd-reference:{$group}:all");
        }
    }

    /** @return list<array<string, mixed>> */
    private function cachedItems(string $groupCode, bool $activeOnly): array
    {
        $key = "hrd-reference:{$groupCode}:".($activeOnly ? 'active' : 'all');

        return Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($groupCode, $activeOnly): array {
            return HrReferenceItem::query()
                ->where('group_code', $groupCode)
                ->when($activeOnly, fn ($query) => $query->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get()
                ->map(fn (HrReferenceItem $item) => [
                    'code' => (string) $item->code,
                    'label' => (string) $item->label,
                    'description' => $item->description,
                    'sort_order' => (int) $item->sort_order,
                    'is_default' => (bool) $item->is_default,
                    'is_active' => (bool) $item->is_active,
                    'meta' => $item->meta ?? [],
                ])
                ->all();
        });
    }
}
