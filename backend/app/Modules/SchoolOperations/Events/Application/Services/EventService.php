<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Events\Application\Services;

use App\Modules\SchoolOperations\Events\Domain\Support\EventContext;
use App\Modules\SchoolOperations\Events\Domain\Support\EventStatus;
use App\Modules\SchoolOperations\Events\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Siklus hidup event (§9).
 *
 * Perpindahan status TIDAK ditulis sebagai daftar if di sini, melainkan
 * diserahkan kepada EventStatus::assertTransition() yang membacanya dari
 * config/events.php. Dengan begitu aturan siklus hidup hanya punya satu
 * sumber kebenaran, dan menambah status tidak menuntut perubahan di kelas ini.
 *
 * Layanan ini hanya menyentuh tabel `events`. Penulisan jejak audit dan
 * penyiaran domain event belum disambungkan di sini karena skema
 * `event_audit_logs` dan `event_domain_events` perlu diverifikasi lebih dulu —
 * menyambungkannya dengan nama kolom tebakan akan gagal senyap.
 */
class EventService
{
    public function __construct(private readonly EventContext $context) {}

    /**
     * Buat event baru berstatus awal.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Event
    {
        $code = $data['code'] ?? $this->context->generateCode('EVT', 'events', 'code');

        // Slug wajib ada dan unik. Bila pemanggil tidak menyediakannya, slug
        // diturunkan dari nama ditambah akhiran acak — tanpa akhiran, dua event
        // bernama sama akan saling menabrak pada batasan unik.
        $slug = $data['slug'] ?? $this->uniqueSlug((string) ($data['name'] ?? 'event'));

        return DB::transaction(function () use ($data, $code, $slug): Event {
            $event = new Event();
            $event->fill($this->fillable($data));
            $event->code = $code;
            $event->slug = $slug;

            // Status awal SELALU diambil dari konfigurasi, tidak pernah dari
            // masukan pemanggil. Tanpa ini, pendaftar dapat melahirkan event
            // yang sudah berstatus disetujui dan melewati seluruh alur.
            $event->status = EventStatus::all()[0] ?? 'DRAFT';
            $event->version_number = 1;
            $event->save();

            return $event->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Event $event, array $data): Event
    {
        $this->assertMutable($event);

        return DB::transaction(function () use ($event, $data): Event {
            $event->fill($this->fillable($data));
            $event->save();

            return $event->refresh();
        });
    }

    /**
     * Pindahkan status event mengikuti aturan perpindahan yang berlaku.
     *
     * @param  array<string, mixed>  $meta
     */
    public function transition(Event $event, string $to, ?string $reason = null, array $meta = []): Event
    {
        $from = (string) $event->status;

        // Melempar DomainException bila perpindahan tidak diizinkan — inilah
        // satu-satunya gerbang perubahan status.
        EventStatus::assertTransition($from, $to, "event#{$event->id}");

        return DB::transaction(function () use ($event, $to, $reason, $meta, $from): Event {
            $event->status = $to;
            $event->version_number = (int) $event->version_number + 1;

            // Cap waktu tahapan ditulis hanya sekali, agar riwayat tidak
            // tertimpa bila transisi yang sama diulang.
            match ($to) {
                'PUBLISHED' => $event->published_at ??= now(),
                'COMPLETED' => $event->completed_at ??= now(),
                'CLOSED', 'ARCHIVED' => $event->closed_at ??= now(),
                'CANCELLED' => $event->cancelled_at ??= now(),
                default => null,
            };

            if ($reason !== null) {
                $event->cancellation_reason = $reason;
            }

            if ($meta !== []) {
                $event->metadata = array_merge((array) $event->metadata, $meta);
            }

            $event->save();

            return $event->refresh();
        });
    }

    /** Ajukan event untuk persetujuan. */
    public function submitForApproval(Event $event): Event
    {
        return $this->transition($event, $this->reachable($event, ['PROPOSED', 'UNDER_REVIEW', 'PENDING_APPROVAL']) ?? 'PROPOSED');
    }

    /** Setujui event dan catat penyetuju beserta waktunya. */
    public function approve(Event $event, ?string $note = null): Event
    {
        $target = $this->reachable($event, ['APPROVED']) ?? 'APPROVED';

        return DB::transaction(function () use ($event, $target, $note): Event {
            $approved = $this->transition($event, $target, null, $note !== null ? ['approval_note' => $note] : []);
            $approved->approved_by = $this->context->userId();
            $approved->approved_at = now();
            $approved->save();

            return $approved->refresh();
        });
    }

    /** Umumkan event ke publik. */
    public function publish(Event $event): Event
    {
        return $this->transition($event, 'PUBLISHED');
    }

    /** Batalkan event beserta alasannya. */
    public function cancel(Event $event, string $reason): Event
    {
        return $this->transition($event, 'CANCELLED', $reason);
    }

    /** Tutup event setelah seluruh tahapannya selesai. */
    public function complete(Event $event): Event
    {
        return $this->transition($event, 'COMPLETED');
    }

    /** Susun slug unik dari nama event. */
    public function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'event';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = $base.'-'.Str::lower(Str::random(6));

            if (! Event::withTrashed()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Jalan terakhir: token acak panjang, praktis tidak mungkin bertabrakan.
        return $base.'-'.Str::lower(Str::random(20));
    }

    /**
     * Status boleh berubah selama belum mencapai keadaan terminal.
     *
     * @throws \DomainException
     */
    public function assertMutable(Event $event): void
    {
        if (EventStatus::isTerminal((string) $event->status)) {
            throw new \DomainException(
                "Event #{$event->id} berstatus {$event->status} dan tidak dapat diubah lagi."
            );
        }
    }

    /**
     * Cari status tujuan pertama yang memang dapat dicapai dari status sekarang.
     *
     * Kandidat diurutkan sesuai prioritas pemanggil; bila tidak ada yang cocok,
     * kembalikan null agar pemanggil memutuskan sendiri — bukan menebak.
     *
     * @param  list<string>  $candidates
     */
    public function reachable(Event $event, array $candidates): ?string
    {
        $from = (string) $event->status;

        foreach ($candidates as $candidate) {
            if (EventStatus::canTransition($from, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Saring data masukan agar hanya kolom `events` yang terisi.
     *
     * Kolom status, kode, dan versi sengaja dibuang: ketiganya dikelola layanan
     * ini melalui jalur yang tervalidasi, bukan lewat mass assignment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fillable(array $data): array
    {
        $guarded = ['id', 'code', 'slug', 'status', 'version_number', 'approved_by', 'approved_at', 'published_at', 'completed_at', 'closed_at', 'cancelled_at'];

        return array_diff_key($data, array_flip($guarded));
    }
}
