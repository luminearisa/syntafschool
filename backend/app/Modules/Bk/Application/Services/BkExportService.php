<?php

namespace App\Modules\Bk\Application\Services;

use App\Models\User;
use App\Modules\Bk\Domain\Enums\AuditAction;
use App\Modules\Bk\Domain\Enums\ExportFormat;
use App\Modules\Bk\Exceptions\BkValidationException;
use App\Modules\Bk\Models\BkCase;
use App\Modules\Bk\Models\BkFollowUp;
use App\Modules\Bk\Models\BkReferral;
use App\Modules\Bk\Models\BkRetentionAction;
use App\Modules\Bk\Domain\Enums\RetentionActionType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ekspor data BK.
 *
 * Ekspor adalah titik paling berbahaya dalam modul ini: satu berkas CSV berisi
 * 300 baris catatan konseling dapat berpindah ke mana saja, jauh dari jangkauan
 * kewenangan aplikasi. Karena itu jalurnya dibuat berurutan dan tidak dapat
 * dilompati:
 *
 * 1. **Pemeriksaan izin** — format dan jenis datanya menentukan izin yang diminta.
 * 2. **Konfirmasi eksplisit** — permintaan harus menyatakan kesadaran akan
 *    konsekuensinya; tanpa itu, ekspor sensitif ditolak.
 * 3. **Pencatatan audit** — siapa, apa, berapa baris, dan berkas mana.
 * 4. **Pembuatan berkas** — dijalankan di latar belakang untuk data besar, disimpan
 *    di disk privat, dan kedaluwarsa dengan sendirinya.
 *
 * Isi catatan konseling **tidak pernah** masuk ke ekspor, bahkan oleh admin:
 * yang dapat diekspor hanyalah metadata operasional. Ini keputusan yang disengaja
 * agar "ekspor" tidak menjadi celah menembus kerahasiaan.
 */
class BkExportService
{
    /** Di bawah jumlah ini ekspor dikerjakan langsung; di atasnya lewat antrean. */
    private const SYNC_ROW_LIMIT = 500;

    public function __construct(
        private readonly BkAuditService $audit,
        private readonly BkAccessService $access,
    ) {
    }

    /** Izin yang diperlukan untuk setiap jenis ekspor. */
    public function requiredPermission(string $dataset): string
    {
        return match ($dataset) {
            'cases' => 'bk.case.export',
            'referrals' => 'bk.referral.export',
            'follow_ups' => 'bk.follow-up.export',
            'indicators' => 'bk.risk.export',
            'analytics' => 'bk.analytics.view',
            default => 'bk.report.export',
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{status: string, message: string, rows: int|null, path: string|null, download_url: string|null, expires_at: string|null}
     */
    public function create(User $actor, string $dataset, ExportFormat $format, array $filters = [], bool $confirmed = false): array
    {
        $permission = $this->requiredPermission($dataset);

        if (! $actor->can($permission)) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException("Ekspor {$dataset} memerlukan izin {$permission}.");
        }

        // Ekspor berisi data siswa selalu meminta konfirmasi eksplisit.
        if (! $confirmed) {
            throw new BkValidationException([
                'confirm' => [
                    'Ekspor ini memuat data siswa. Nyatakan konfirmasi bahwa berkas akan disimpan dan dibagikan sesuai kewenangan.',
                ],
            ]);
        }

        $rows = $this->rows($actor, $dataset, $filters);

        if (count($rows) > self::SYNC_ROW_LIMIT) {
            // Data besar: berkas dibuat di latar belakang, permintaan tidak menunggu.
            \App\Modules\Bk\Jobs\GenerateBkExportJob::dispatch($actor->id, $dataset, $format->value, $filters, count($rows));

            return [
                'status' => 'queued',
                'message' => 'Ekspor sedang dibuat di latar belakang dan akan tersedia pada daftar berkas.',
                'rows' => count($rows),
                'path' => null,
                'download_url' => null,
                'expires_at' => null,
            ];
        }

        return $this->write($actor, $dataset, $format, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{status: string, message: string, rows: int, path: string, download_url: string|null, expires_at: string}
     */
    public function write(User $actor, string $dataset, ExportFormat $format, array $rows): array
    {
        [$contents, $extension] = $this->render($dataset, $format, $rows);

        $path = trim((string) config('bk.queue.export_path', 'bk/exports'), '/')
            .'/'.now()->format('Ymd-His').'-'.$dataset.'-'.Str::random(6).'.'.$extension;

        Storage::disk((string) config('bk.queue.export_disk', 'local'))->put($path, $contents);

        $expiresAt = now()->addHours((int) config('bk.queue.export_expiry_hours', 24));

        // Jejak audit ekspor: siapa, apa, berapa baris, berkas mana, dan sampai kapan.
        $this->audit->record(AuditAction::EXPORT_CREATE, $actor, $actor, [
            'dataset' => $dataset,
            'format' => $format->value,
            'rows' => count($rows),
            'path' => $path,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        BkRetentionAction::query()->create([
            'entity_type' => \App\Modules\Bk\Domain\Enums\RetentionEntityType::CASE,
            'entity_id' => 0,
            'action' => RetentionActionType::EXPORTED,
            'status' => 'COMPLETED',
            'reason' => 'Ekspor '.$dataset.' ('.$format->value.')',
            'snapshot' => ['path' => $path, 'rows' => count($rows), 'dataset' => $dataset],
            'performed_at' => now(),
            'performed_by' => $actor->id,
        ]);

        return [
            'status' => 'ready',
            'message' => 'Ekspor siap diunduh. Berkas kedaluwarsa otomatis dalam '.(string) config('bk.queue.export_expiry_hours', 24).' jam.',
            'rows' => count($rows),
            'path' => $path,
            'download_url' => $this->temporaryUrl($path),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** URL bertanda tangan berumur pendek; berkas tetap di disk privat. */
    public function temporaryUrl(string $path): ?string
    {
        $disk = Storage::disk((string) config('bk.queue.export_disk', 'local'));

        try {
            return $disk->temporaryUrl($path, now()->addMinutes((int) config('bk.queue.signed_url_minutes', 10)));
        } catch (\Throwable) {
            // Disk lokal tidak mendukung URL bertanda tangan: kembalikan null dan
            // biarkan endpoint unduhan yang memeriksa izin + mencatat audit.
            return null;
        }
    }

    /**
     * Menyusun isi berkas.
     *
     * Kolom sensitif difilter **di sini**, bukan di pemanggil: satu tempat yang
     * menentukan apa yang tidak pernah boleh keluar.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{0: string, 1: string}
     */
    protected function render(string $dataset, ExportFormat $format, array $rows): array
    {
        $safe = array_map(fn (array $row): array => $this->scrub($row), $rows);
        $headers = $safe === [] ? ['kosong'] : array_keys($safe[0]);

        return match ($format) {
            ExportFormat::CSV => [$this->csv($headers, $safe), 'csv'],
            ExportFormat::JSON => [json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]', 'json'],
            ExportFormat::XLSX => [$this->spreadsheetXml($dataset, $headers, $safe), 'xls'],
            ExportFormat::PDF => [$this->printableHtml($dataset, $headers, $safe), 'html'],
        };
    }

    /**
     * Menghapus kolom yang tidak boleh diekspor.
     *
     * Daftar ini adalah pertahanan terakhir kerahasiaan: apa pun yang dilakukan
     * pemanggil, catatan konseling tidak akan muncul di berkas.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function scrub(array $row): array
    {
        $forbidden = [
            'counselor_note', 'counselor_notes', 'confidential_note', 'discussion', 'body',
            'session_summary', 'private_note', 'diagnosis', 'medical_record', 'prescription',
            'password', 'remember_token', 'email', 'phone',
        ];

        $clean = [];

        foreach ($row as $key => $value) {
            if (in_array($key, $forbidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }

        return $clean;
    }

    /** @param list<string> $headers @param list<array<string, mixed>> $rows */
    protected function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        // BOM agar Excel membaca UTF-8 dengan benar (nama siswa berdiakritik tidak rusak).
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $row[$header] ?? null, $headers));
        }

        rewind($handle);
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $contents;
    }

    /** @param list<string> $headers @param list<array<string, mixed>> $rows */
    protected function spreadsheetXml(string $dataset, array $headers, array $rows): string
    {
        // SpreadsheetML sederhana: terbuka di Excel/LibreOffice tanpa menambah
        // dependensi pustaka spreadsheet ke proyek ini.
        $escape = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_XML1);

        $xml = '<?xml version="1.0"?>'."\n";
        $xml .= '<?mso-application progid="Excel.Sheet"?>'."\n";
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'."\n";
        $xml .= '<Worksheet ss:Name="'.substr($dataset, 0, 30).'"><Table>'."\n";
        $xml .= '<Row>'.implode('', array_map(fn (string $h): string => '<Cell><Data ss:Type="String">'.$escape($h).'</Data></Cell>', $headers)).'</Row>'."\n";

        foreach ($rows as $row) {
            $cells = '';
            foreach ($headers as $header) {
                $value = $row[$header] ?? null;
                $type = is_numeric($value) ? 'Number' : 'String';
                $cells .= '<Cell><Data ss:Type="'.$type.'">'.$escape($value).'</Data></Cell>';
            }
            $xml .= '<Row>'.$cells.'</Row>'."\n";
        }

        return $xml.'</Table></Worksheet></Workbook>';
    }

    /** @param list<string> $headers @param list<array<string, mixed>> $rows */
    protected function printableHtml(string $dataset, array $headers, array $rows): string
    {
        $escape = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES);

        $html = '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Ekspor BK</title>';
        $html .= '<style>body{font-family:system-ui,sans-serif;font-size:12px}table{border-collapse:collapse;width:100%}'
            .'th,td{border:1px solid #ddd;padding:4px 6px;text-align:left}thead{background:#f3f4f6}</style></head><body>';
        $html .= '<h1>Ekspor '.$escape($dataset).'</h1>';
        $html .= '<p>Dibuat '.$escape(now()->toDateTimeString()).'. Catatan konseling tidak diikutkan pada ekspor ini.</p>';
        $html .= '<table><thead><tr>'.implode('', array_map(fn (string $h): string => '<th>'.$escape($h).'</th>', $headers)).'</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'.implode('', array_map(fn (string $header): string => '<td>'.$escape($row[$header] ?? '').'</td>', $headers)).'</tr>';
        }

        return $html.'</tbody></table></body></html>';
    }

    /**
     * Data yang diekspor.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function rows(User $actor, string $dataset, array $filters = []): array
    {
        return match ($dataset) {
            'cases' => BkCase::query()
                ->with('student:id,full_name,nis')
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
                ->get()
                ->map(fn (BkCase $case): array => [
                    'case_number' => $case->case_number,
                    'student' => $case->student?->full_name,
                    'nis' => $case->student?->nis,
                    'case_type' => $case->case_type->value,
                    'status' => $case->status->value,
                    'priority' => $case->priority->value,
                    'opened_at' => $case->opened_at?->toDateString(),
                    'closed_at' => $case->closed_at?->toDateString(),
                    // Catatan konseling sengaja tidak ada di daftar kolom.
                ])->all(),

            'referrals' => BkReferral::query()
                ->with('student:id,full_name,nis')
                ->get()
                ->map(fn (BkReferral $referral): array => [
                    'referral_number' => $referral->referral_number,
                    'student' => $referral->student?->full_name,
                    'source_type' => $referral->source_type->value,
                    'category' => $referral->category->value,
                    'priority' => $referral->priority->value,
                    'status' => $referral->status->value,
                    'referred_at' => $referral->referred_at?->toDateString(),
                ])->all(),

            'follow_ups' => BkFollowUp::query()
                ->with('case:id,case_number')
                ->get()
                ->map(fn (BkFollowUp $followUp): array => [
                    'case_number' => $followUp->case?->case_number,
                    'title' => $followUp->title,
                    'type' => $followUp->type->value,
                    'status' => $followUp->status->value,
                    'due_date' => $followUp->due_date?->toDateString(),
                    'is_overdue' => (bool) $followUp->is_overdue,
                ])->all(),

            'indicators' => \App\Modules\Bk\Models\BkRiskIndicator::query()
                ->with('student:id,full_name,nis')
                ->get()
                ->map(fn (\App\Modules\Bk\Models\BkRiskIndicator $indicator): array => [
                    'student' => $indicator->student?->full_name,
                    'indicator_code' => $indicator->indicator_code->value,
                    'indicator_type' => $indicator->indicator_type->value,
                    'severity' => $indicator->severity->value,
                    'source_module' => $indicator->source_module->value,
                    'status' => $indicator->status->value,
                    'detected_at' => $indicator->detected_at?->toDateString(),
                ])->all(),

            default => [],
        };
    }

    /** Daftar berkas ekspor yang masih ada (dipakai layar pengaturan). */
    public function files(User $actor): array
    {
        $disk = Storage::disk((string) config('bk.queue.export_disk', 'local'));
        $path = trim((string) config('bk.queue.export_path', 'bk/exports'), '/');

        return collect($disk->files($path))
            ->map(fn (string $file): array => [
                'path' => $file,
                'name' => basename($file),
                'size_kb' => round($disk->size($file) / 1024, 1),
                'modified_at' => date('c', (int) $disk->lastModified($file)),
                'download_url' => $this->temporaryUrl($file),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();
    }
}
