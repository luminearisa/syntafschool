<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Domain\Enums\IndicatorInputType;
use App\Modules\Supervisi\Models\SupIndicator;
use App\Modules\Supervisi\Models\SupVisit;
use App\Modules\Supervisi\Models\SupVisitAnswer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sinkronisasi jawaban yang dikumpulkan tanpa jaringan (mode luring).
 *
 * Observasi kelas sering terjadi di ruang tanpa sinyal. Aplikasi menyimpan
 * jawaban di perangkat lebih dulu (localStorage/IndexedDB), lalu mengirimnya
 * saat jaringan kembali — bisa beberapa kali, bisa dari perangkat berbeda.
 *
 * Sifat yang dijaga:
 *  - **Idempoten**: satu jawaban per indikator (`visit_id` + `indicator_id`
 *    unik) dan satu `client_uuid` tidak pernah menjadi dua baris.
 *  - **Tahan sebagian**: satu baris rusak tidak membatalkan seluruh kiriman —
 *    baris itu dilaporkan ditolak, sisanya tersimpan.
 *  - **Jujur soal konflik**: bila salinan server sudah berubah setelah
 *    perangkat merekam (dan nilainya berbeda), server tidak ditimpa diam-diam;
 *    baris itu dilaporkan sebagai konflik beserta kedua nilainya.
 *  - **Tidak menembus kunci**: kegiatan yang sudah difinalkan menolak
 *    sinkronisasi (koreksi hanya lewat pembukaan ulang).
 */
class OfflineSyncService
{
    public function __construct(private readonly VisitAnswerService $answers)
    {
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    public function sync(SupVisit $visit, User $actor, array $rows): array
    {
        if ($visit->finalized_at !== null) {
            throw ValidationException::withMessages([
                'visit' => 'Kegiatan ini sudah difinalkan; sinkronisasi luring tidak dapat menimpa hasil yang terkunci.',
            ]);
        }

        $version = $visit->instrumentVersion()->with('sections.indicators.options')->first();

        if ($version === null) {
            throw ValidationException::withMessages(['visit' => 'Kegiatan ini belum memiliki versi instrumen.']);
        }

        $indicators = $version->indicators->keyBy('id');

        /** @var array<int, SupVisitAnswer> $existing */
        $existing = SupVisitAnswer::query()->where('visit_id', $visit->id)->get()->keyBy('indicator_id')->all();

        $applied = 0;
        $skipped = [];
        $rejected = [];
        $conflicts = [];

        foreach ($rows as $index => $row) {
            $indicatorId = (int) ($row['indicator_id'] ?? 0);
            $indicator = $indicators->get($indicatorId);

            if ($indicator === null) {
                $rejected[] = ['index' => $index, 'indicator_id' => $indicatorId, 'reason' => "Indikator {$indicatorId} bukan bagian instrumen kegiatan ini."];

                continue;
            }

            $clientUuid = isset($row['client_uuid']) ? (string) $row['client_uuid'] : null;

            if ($clientUuid !== null && $this->alreadyStored($existing, $clientUuid)) {
                $skipped[] = ['index' => $index, 'indicator_id' => $indicatorId, 'client_uuid' => $clientUuid, 'reason' => 'Sudah tersimpan sebelumnya.'];

                continue;
            }

            $payload = $this->payloadFor($indicator, $row, $index, $rejected);

            if ($payload === null) {
                continue;
            }

            $payload['client_uuid'] = $clientUuid;

            $current = $existing[$indicatorId] ?? null;
            $capturedAt = isset($row['captured_at']) ? Carbon::parse($row['captured_at']) : null;

            if ($current !== null && $capturedAt !== null && $current->updated_at !== null
                && $current->updated_at->greaterThan($capturedAt)
                && ! $this->sameValue($current, $payload)) {
                $conflicts[] = [
                    'index' => $index,
                    'indicator_id' => $indicatorId,
                    'statement' => $indicator->statement,
                    'captured_at' => $capturedAt->toIso8601String(),
                    'server_updated_at' => $current->updated_at->toIso8601String(),
                    'local' => $this->valueOf($payload),
                    'server' => $this->valueOf($current->toArray()),
                    'reason' => 'Nilai di server lebih baru daripada rekaman perangkat; server dipertahankan.',
                ];

                continue;
            }

            try {
                // Satu baris = satu simpanan, sehingga satu baris rusak tidak
                // membatalkan puluhan jawaban lain yang sudah diketik di kelas.
                $this->answers->save($visit, [$payload], $actor);
                $applied++;

                foreach ($visit->answers()->get() as $answer) {
                    $existing[(int) $answer->indicator_id] = $answer;
                }
            } catch (ValidationException $exception) {
                $rejected[] = [
                    'index' => $index,
                    'indicator_id' => $indicatorId,
                    'reason' => (string) (collect($exception->errors())->flatten()->first() ?? 'Nilai tidak valid.'),
                ];
            }
        }

        $visit->refresh();

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'rejected' => $rejected,
            'conflicts' => $conflicts,
            'server_time' => now()->toIso8601String(),
            'visit' => [
                'id' => $visit->id,
                'code' => $visit->code,
                'status' => $visit->status->value,
                'answers_count' => $visit->answers()->count(),
                'is_locked' => $visit->finalized_at !== null,
            ],
        ];
    }

    /** @param array<int, SupVisitAnswer> $existing */
    protected function alreadyStored(array $existing, string $clientUuid): bool
    {
        foreach ($existing as $answer) {
            if ($answer->client_uuid !== null && $answer->client_uuid === $clientUuid) {
                return true;
            }
        }

        return false;
    }

    /**
     * Terjemahkan satu baris kiriman luring menjadi payload jawaban.
     *
     * Nilai boleh dikirim sebagai `option_id` (pilihan dari daftar di
     * perangkat) atau `option_code` (dari formulir kertas yang dicetak).
     *
     * @param  array<int, array<string,mixed>>  $rejected
     * @return array<string,mixed>|null
     */
    protected function payloadFor(SupIndicator $indicator, array $row, int $index, array &$rejected): ?array
    {
        $base = ['indicator_id' => (int) $indicator->id];

        if (($row['is_na'] ?? false) === true || ($row['is_na'] ?? 'false') === 'true') {
            return [...$base, 'is_na' => true, 'comment' => $row['comment'] ?? null];
        }

        $optionId = $row['option_id'] ?? null;

        if ($optionId === null && isset($row['option_code'])) {
            $optionId = $this->resolveOptionByCode($indicator, (string) $row['option_code']);
        }

        $payload = match ($indicator->input_type) {
            IndicatorInputType::SCALE => [...$base, 'option_id' => $optionId === null ? null : (int) $optionId],
            IndicatorInputType::NUMBER => [...$base, 'value_number' => isset($row['value_number']) && $row['value_number'] !== '' ? (float) $row['value_number'] : null],
            IndicatorInputType::BOOLEAN => [...$base, 'value_text' => isset($row['value_text']) ? strtoupper((string) $row['value_text']) : null],
            default => [...$base, 'value_long_text' => $row['value_long_text'] ?? null],
        };

        $empty = match ($indicator->input_type) {
            IndicatorInputType::SCALE => $payload['option_id'] === null,
            IndicatorInputType::NUMBER => $payload['value_number'] === null,
            IndicatorInputType::BOOLEAN => $payload['value_text'] === null,
            default => ($payload['value_long_text'] ?? null) === null,
        };

        if ($empty) {
            $rejected[] = ['index' => $index, 'indicator_id' => $base['indicator_id'], 'reason' => 'Nilai jawaban kosong.'];

            return null;
        }

        if (isset($row['comment'])) {
            $payload['comment'] = (string) $row['comment'];
        }

        return $payload;
    }

    /**
     * Petakan kode pilihan dari formulir kertas menjadi id opsi.
     *
     * Formulir cetak memberi label A/B/C/D menurut urutan opsi; supervisor juga
     * boleh menuliskan skornya langsung (mis. "4") atau label opsi.
     */
    protected function resolveOptionByCode(SupIndicator $indicator, string $code): ?int
    {
        $code = trim($code);

        if ($code === '') {
            return null;
        }

        $options = $indicator->options->sortBy('sort_order')->values();

        if ($options->isEmpty()) {
            return null;
        }

        $upper = strtoupper($code);

        if (preg_match('/^[A-Z]$/', $upper) === 1) {
            $index = ord($upper) - 65;

            return isset($options[$index]) ? (int) $options[$index]->id : null;
        }

        if (is_numeric($code)) {
            $match = $options->first(fn ($option) => (float) $option->score === (float) $code);

            return $match === null ? null : (int) $match->id;
        }

        $match = $options->first(fn ($option) => mb_strtolower((string) $option->label) === mb_strtolower($code));

        return $match === null ? null : (int) $match->id;
    }

    /** @param array<string,mixed> $candidate */
    protected function sameValue(SupVisitAnswer $answer, array $candidate): bool
    {
        return $this->valueOf($answer->toArray()) === $this->valueOf($candidate);
    }

    /** @param array<string,mixed> $row */
    protected function valueOf(array $row): string
    {
        return implode('|', [
            $row['option_id'] ?? '-',
            $row['value_number'] ?? '-',
            $row['value_text'] ?? '-',
            $row['value_long_text'] ?? '-',
            ($row['is_na'] ?? false) ? '1' : '0',
        ]);
    }
}
