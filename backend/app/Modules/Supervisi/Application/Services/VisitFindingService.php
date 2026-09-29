<?php

namespace App\Modules\Supervisi\Application\Services;

use App\Models\User;
use App\Modules\Supervisi\Models\SupFinding;
use App\Modules\Supervisi\Models\SupInstrumentVersion;
use App\Modules\Supervisi\Models\SupVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Temuan analisis data supervisi.
 *
 * Satu temuan memuat kelebihan, kelemahan, masalah, faktor penyebab,
 * prioritas, dan rekomendasi — kerangka yang diminta pedoman supervisi
 * akademik, sekaligus bahan baku RTL pada Fase 3.
 */
class VisitFindingService
{
    public const COMPONENTS = [
        'PERENCANAAN', 'PELAKSANAAN', 'PENILAIAN', 'PENGELOLAAN_KELAS', 'SIKAP_PROFESIONAL',
        'KEPRIBADIAN', 'SOSIAL', 'MANAJERIAL', 'ADMINISTRASI', 'SARANA_PRASARANA', 'LAINNYA',
    ];

    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'URGENT'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly VisitLifecycleService $lifecycle,
    ) {}

    /**
     * Ganti seluruh daftar temuan satu kegiatan (payload = daftar lengkap).
     *
     * @param  list<array<string,mixed>>  $findings
     * @return list<SupFinding>
     */
    public function replace(SupVisit $visit, array $findings, User $actor): array
    {
        $this->lifecycle->assertEditable($visit);

        $version = $visit->instrumentVersion;
        $indicatorIds = $version instanceof SupInstrumentVersion
            ? $version->loadMissing('indicators')->indicators->pluck('id')->all()
            : [];

        $errors = [];
        $rows = [];

        foreach (array_values($findings) as $index => $finding) {
            $component = strtoupper((string) ($finding['component'] ?? 'PELAKSANAAN'));
            $priority = strtoupper((string) ($finding['priority'] ?? 'MEDIUM'));

            if (! in_array($component, self::COMPONENTS, true)) {
                $errors["findings.{$index}.component"] = 'Komponen temuan tidak dikenal.';
            }

            if (! in_array($priority, self::PRIORITIES, true)) {
                $errors["findings.{$index}.priority"] = 'Prioritas temuan tidak dikenal.';
            }

            $indicatorId = isset($finding['indicator_id']) && $finding['indicator_id'] !== null
                ? (int) $finding['indicator_id']
                : null;

            if ($indicatorId !== null && ! in_array($indicatorId, array_map('intval', $indicatorIds), true)) {
                $errors["findings.{$index}.indicator_id"] = 'Indikator tidak termasuk instrumen kegiatan ini.';
            }

            if (trim((string) ($finding['recommendation'] ?? '')) === '' && trim((string) ($finding['weakness'] ?? '')) === '') {
                $errors["findings.{$index}.recommendation"] = 'Isi kelemahan atau rekomendasi agar temuan bermakna.';
            }

            $rows[] = [
                'component' => $component,
                'priority' => $priority,
                'strength' => $this->text($finding['strength'] ?? null),
                'weakness' => $this->text($finding['weakness'] ?? null),
                'problem' => $this->text($finding['problem'] ?? null),
                'cause' => $this->text($finding['cause'] ?? null),
                'recommendation' => $this->text($finding['recommendation'] ?? null),
                'indicator_id' => $indicatorId,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($visit, $rows): void {
            $visit->findings()->delete();

            foreach ($rows as $row) {
                $visit->findings()->create($row);
            }
        });

        $this->audit->log('visit.findings_replaced', $visit, null, ['count' => count($rows)], null, $actor);

        return $visit->findings()->get()->all();
    }

    protected function text(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
