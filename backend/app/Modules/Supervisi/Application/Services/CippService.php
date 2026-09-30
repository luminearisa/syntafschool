<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupProgram;
use App\Modules\Supervisi\Models\SupProgramEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Evaluasi program supervisi dengan model CIPP (Fase 4).
 *
 * Context — apakah program menjawab kebutuhan nyata sekolah.
 * Input   — kesiapan sumber daya: supervisor, instrumen, jadwal, anggaran.
 * Process — apakah pelaksanaan sesuai rencana (cakupan, ketepatan waktu).
 * Product — hasil: nilai guru, tindak lanjut, dan perubahan praktik kelas.
 *
 * Skor tiap dimensi (0–100) diberi bobot sama; narasi bukti disimpan sebagai
 * JSON agar dapat dicetak apa adanya di laporan wajib.
 */
class CippService
{
    public const DIMENSIONS = ['context', 'input', 'process', 'product'];

    public function __construct(protected AnalyticsService $analytics)
    {
    }

    /** @param array<string, mixed> $payload */
    public function create(SupProgram $program, array $payload, User $actor): SupProgramEvaluation
    {
        $evaluation = SupProgramEvaluation::query()->create([
            'program_id' => $program->id,
            'model' => 'CIPP',
            'period' => $payload['period'] ?? null,
            'context' => $payload['context'] ?? null,
            'input' => $payload['input'] ?? null,
            'process' => $payload['process'] ?? null,
            'product' => $payload['product'] ?? null,
            'context_score' => $payload['context_score'] ?? null,
            'input_score' => $payload['input_score'] ?? null,
            'process_score' => $payload['process_score'] ?? null,
            'product_score' => $payload['product_score'] ?? null,
            'stakeholders' => $payload['stakeholders'] ?? null,
            'finding' => $payload['finding'] ?? null,
            'recommendation' => $payload['recommendation'] ?? null,
            'created_by' => $actor->id,
        ]);

        return $evaluation->refresh();
    }

    /** @param array<string, mixed> $payload */
    public function update(SupProgramEvaluation $evaluation, array $payload): SupProgramEvaluation
    {
        if ($evaluation->finalized_at !== null) {
            throw ValidationException::withMessages([
                'evaluation' => 'Evaluasi sudah difinalkan dan tidak dapat diubah lagi.',
            ]);
        }

        foreach ([...self::DIMENSIONS, 'stakeholders'] as $dimension) {
            if (array_key_exists($dimension, $payload)) {
                $evaluation->{$dimension} = $payload[$dimension];
            }
        }

        foreach ([...self::DIMENSIONS] as $dimension) {
            if (array_key_exists($dimension.'_score', $payload)) {
                $evaluation->{$dimension.'_score'} = $payload[$dimension.'_score'];
            }
        }

        foreach (['period', 'finding', 'recommendation'] as $field) {
            if (array_key_exists($field, $payload)) {
                $evaluation->{$field} = $payload[$field];
            }
        }

        $evaluation->save();

        return $evaluation->refresh();
    }

    public function finalize(SupProgramEvaluation $evaluation, User $actor): SupProgramEvaluation
    {
        if ($evaluation->averageScore() === null) {
            throw ValidationException::withMessages([
                'context_score' => 'Keempat dimensi CIPP harus diberi skor sebelum evaluasi difinalkan.',
            ]);
        }

        if (blank($evaluation->recommendation)) {
            throw ValidationException::withMessages([
                'recommendation' => 'Rekomendasi wajib diisi: evaluasi tanpa tindak lanjut tidak mengubah apa pun.',
            ]);
        }

        $evaluation->forceFill(['finalized_at' => now()])->save();

        return $evaluation->refresh();
    }

    /**
     * Rincian evaluasi + angka otomatis dari data supervisi sebagai bahan
     * pembanding penilaian manual (mis. capaian program, kinerja guru, RTL).
     *
     * @return array<string, mixed>
     */
    public function detail(SupProgramEvaluation $evaluation): array
    {
        $evaluation->loadMissing('program:id,title,scope,status,starts_at,ends_at');

        $from = $evaluation->program?->starts_at ?? now()->startOfYear();
        $to = $evaluation->program?->ends_at ?? now()->endOfYear();

        return [
            'evaluation' => [
                'id' => $evaluation->id,
                'program' => $evaluation->program?->title,
                'period' => $evaluation->period,
                'model' => $evaluation->model,
                'context' => $evaluation->context,
                'input' => $evaluation->input,
                'process' => $evaluation->process,
                'product' => $evaluation->product,
                'stakeholders' => $evaluation->stakeholders,
                'scores' => [
                    'context' => $evaluation->context_score,
                    'input' => $evaluation->input_score,
                    'process' => $evaluation->process_score,
                    'product' => $evaluation->product_score,
                ],
                'average' => $evaluation->averageScore(),
                'finding' => $evaluation->finding,
                'recommendation' => $evaluation->recommendation,
                'finalized_at' => $evaluation->finalized_at?->toIso8601String(),
                'created_at' => $evaluation->created_at?->toIso8601String(),
            ],
            'evidence' => [
                'visits' => $this->analytics->visitStats($from, $to),
                'coverage' => $this->analytics->coverage($from, $to),
                'scores' => $this->analytics->scoreStats($from, $to),
                'follow_up' => $this->analytics->followUpStats($from, $to),
                'growth' => $this->analytics->growthStats($from, $to),
            ],
            'guide' => [
                'context' => 'Kesesuaian tujuan program dengan kebutuhan nyata: rapor pendidikan, keluhan orang tua, hasil supervisi sebelumnya.',
                'input' => 'Kesiapan sumber daya: jumlah & kompetensi supervisor, instrumen yang dipakai, jadwal, anggaran.',
                'process' => 'Kesesuaian pelaksanaan: cakupan guru, ketepatan waktu, mutu percakapan pasca-observasi.',
                'product' => 'Hasil: perubahan nilai, tindak lanjut yang tuntas, perubahan praktik kelas, dampak ke peserta didik.',
            ],
            'abilities' => [
                'update' => $evaluation->finalized_at === null,
                'finalize' => $evaluation->finalized_at === null,
            ],
        ];
    }

    /** Daftar evaluasi CIPP (untuk tabel & ringkasan program). */
    public function listing(array $filters = []): Collection
    {
        return SupProgramEvaluation::query()
            ->with('program:id,title,scope,status,starts_at,ends_at')
            ->when(! empty($filters['program_id']), fn ($query) => $query->where('program_id', (int) $filters['program_id']))
            ->when(! empty($filters['period']), fn ($query) => $query->where('period', $filters['period']))
            ->orderByDesc('id')
            ->get()
            ->map(fn (SupProgramEvaluation $evaluation) => [
                'id' => $evaluation->id,
                'program' => $evaluation->program?->title,
                'program_id' => $evaluation->program_id,
                'scope' => $evaluation->program?->scope,
                'period' => $evaluation->period,
                'scores' => [
                    'context' => $evaluation->context_score,
                    'input' => $evaluation->input_score,
                    'process' => $evaluation->process_score,
                    'product' => $evaluation->product_score,
                ],
                'average' => $evaluation->averageScore(),
                'finding' => $evaluation->finding,
                'recommendation' => $evaluation->recommendation,
                'finalized_at' => $evaluation->finalized_at?->toIso8601String(),
            ]);
    }
}
