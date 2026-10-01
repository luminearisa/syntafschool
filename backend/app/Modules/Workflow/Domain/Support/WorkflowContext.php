<?php

namespace App\Modules\Workflow\Domain\Support;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Konteks workflow — "dunia" yang boleh dilihat engine (§31, §65).
 *
 * Konteks dirakit sekali oleh `WorkflowContextService` dari provider milik modul
 * pemilik subjek, lalu **dibekukan** menjadi snapshot. Kondisi, resolver, dan
 * aksi selalu membaca dari objek ini — tidak pernah dari tabel bisnis hidup.
 * Karena itu:
 *
 *  - hasil approval tetap dapat dipertanggungjawabkan setelah data berubah;
 *  - engine tidak bisa "diam-diam" membaca data yang tidak diizinkan modul.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class WorkflowContext implements ArrayAccess, Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta  metadata non-bisnis (subjek, requester, sumber)
     */
    public function __construct(
        private array $data = [],
        private array $meta = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, array $meta = []): self
    {
        return new self($data, $meta);
    }

    /** Konteks dari instance yang sudah tersimpan (snapshot beku). */
    public static function fromSnapshot(array $snapshot, array $meta = []): self
    {
        $meta = array_merge([
            'subject_type' => $snapshot['_subject_type'] ?? null,
            'subject_id' => $snapshot['_subject_id'] ?? null,
            'requester_id' => $snapshot['_requester_id'] ?? null,
        ], $meta);

        return new self($snapshot, $meta);
    }

    /** Ambil nilai dengan notasi titik, mis. `items.0.amount`. */
    public function get(string $path, mixed $default = null): mixed
    {
        if ($path === '') {
            return $this->data;
        }

        $value = $this->data;

        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];

                continue;
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray();

                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];

                    continue;
                }
            }

            return $default;
        }

        return $value;
    }

    public function has(string $path): bool
    {
        return $this->get($path, '__WF_MISSING__') !== '__WF_MISSING__';
    }

    public function isEmpty(string $path): bool
    {
        $value = $this->get($path);

        if (is_string($value)) {
            return trim($value) === '';
        }

        return $value === null || $value === [];
    }

    public function isNotEmpty(string $path): bool
    {
        return ! $this->isEmpty($path);
    }

    public function requesterId(): ?int
    {
        $id = $this->meta['requester_id'] ?? $this->get('requester_id');

        return $id === null ? null : (int) $id;
    }

    public function subjectType(): ?string
    {
        return $this->meta['subject_type'] ?? $this->get('_subject_type');
    }

    public function subjectId(): string|int|null
    {
        return $this->meta['subject_id'] ?? $this->get('_subject_id');
    }

    public function instanceId(): ?int
    {
        $id = $this->meta['instance_id'] ?? null;

        return $id === null ? null : (int) $id;
    }

    /** @param array<string, mixed> $extra */
    public function with(array $extra): self
    {
        return new self(array_merge($this->data, $extra), $this->meta);
    }

    /** @param array<string, mixed> $extra */
    public function withMeta(array $extra): self
    {
        return new self($this->data, array_merge($this->meta, $extra));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return $this->meta;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->data;
    }

    /**
     * Sidik jari konteks. Dipakai untuk membuktikan bahwa keputusan approver
     * diambil atas data yang sama dengan yang dilihat requester (§65).
     */
    public function hash(): string
    {
        $canonical = $this->canonicalize($this->data);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Urutkan kunci secara rekursif agar hash stabil terhadap urutan array. */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->canonicalize($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->canonicalize($item), $value);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Konteks workflow bersifat beku dan tidak dapat diubah setelah dirakit.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Konteks workflow bersifat beku dan tidak dapat diubah setelah dirakit.');
    }
}
