<?php

namespace App\Services\Ppdb;

use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbInvoice;
use App\Models\PpdbParentAccount;
use App\Models\PpdbRegistration;
use App\Models\PpdbSelectionParticipant;
use Barryvdh\DomPDF\PDF;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Generator;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Laporan, ekspor CSV, dan PDF modul PPDB.
 *
 * Empat grup laporan (pendaftaran, pembayaran, seleksi, dokumen) plus dua
 * daftar datar (calon & akun orang tua) disusun dari SATU spesifikasi per
 * jenis: kolom, query SQL, dan ringkasan. Bentuk responsnya seragam:
 * `type, title, generated_at, filters, columns, rows, summary, pagination`.
 *
 * Prinsip kerja:
 * - Baris laporan adalah hasil query SQL (join + group by + agregat). Tidak ada
 *   pembebanan tabel ke PHP dan tidak ada N+1: satu query untuk baris, beberapa
 *   query agregat untuk ringkasan.
 * - `summary` selalu dihitung atas SELURUH himpunan terfilter, bukan atas
 *   halaman yang ditampilkan.
 * - Cakupan periode/gelombang/program/tanggal/status diterapkan lewat
 *   `applyFilters()` sehingga dashboard dan laporan tidak berbeda angka.
 * - Tiap jenis laporan punya builder "scope" (join + filter, TANPA select &
 *   order) yang dipakai bersama oleh builder baris dan oleh agregat ringkasan,
 *   jadi filter tidak bisa menyimpang.
 *
 * Keamanan data: tidak ada kolom kredensial (`password`, `remember_token`) yang
 * ikut terseleksi di mana pun. NIK/NISN calon tetap disertakan karena memang
 * dipakai panitia memverifikasi berkas, dan kontak orang tua (email/telepon)
 * disertakan karena laporan dipakai untuk menindaklanjuti pendaftaran.
 */
class PpdbReportService
{
    /** Empat grup laporan utama. */
    public const REPORT_TYPES = ['registration', 'payment', 'selection', 'document'];

    /** Jenis tambahan berupa daftar datar (ekspor CSV & PDF). */
    public const FLAT_TYPES = ['applicants', 'parent_accounts'];

    /** Semua jenis yang bisa diekspor/dicetak. */
    public const EXPORT_TYPES = ['registration', 'payment', 'selection', 'document', 'applicants', 'parent_accounts'];

    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 500;

    /** Kolom tempat `date_from`/`date_to` diterapkan pada entitas induk. */
    public const DATE_BASES = ['created', 'submitted'];

    /** Granularitas tren pada dashboard. */
    public const GRANULARITIES = ['weekly', 'monthly'];

    /** Batas baris pada PDF agar berkas tetap ringan (Helvetica core font). */
    public const PDF_ROW_LIMIT = 200;

    /**
     * Param status → daftar nilai sah (diambil dari konstanta model, yang
     * merupakan cerminan `enum` pada migration). Dipakai juga oleh
     * `PpdbReportFilterRequest`.
     *
     * @var array<string, list<string>>
     */
    public const LIST_PARAMS = [
        'status' => PpdbRegistration::STATUSES,
        'payment_status' => PpdbRegistration::PAYMENT_STATUSES,
        'document_status' => PpdbRegistration::DOCUMENT_STATUSES,
        'selection_result' => ['passed', 'failed', 'waitlisted'],
        'invoice_status' => PpdbInvoice::STATUSES,
        'participant_status' => PpdbSelectionParticipant::STATUSES,
        'review_status' => [...PpdbApplicantDocument::STATUSES, 'missing'],
        'account_status' => PpdbParentAccount::STATUSES,
        'applicant_status' => PpdbApplicant::STATUSES,
    ];

    /**
     * Label Indonesia untuk nilai status. Laporan JSON mengirim kode mentah
     * (frontend yang menerjemahkan); PDF mencetak label.
     */
    public const LABELS = [
        'status' => [
            'draft' => 'Draft', 'submitted' => 'Terkirim', 'under_review' => 'Sedang Ditinjau',
            'need_revision' => 'Perlu Revisi', 'verified' => 'Terverifikasi', 'selection' => 'Seleksi',
            'passed' => 'Lulus', 'failed' => 'Tidak Lulus', 'waitlisted' => 'Daftar Tunggu',
            're_registration' => 'Daftar Ulang', 'completed' => 'Selesai', 'cancelled' => 'Dibatalkan',
        ],
        'payment_status' => [
            'unpaid' => 'Belum Bayar', 'pending' => 'Menunggu Verifikasi', 'paid' => 'Lunas',
            'failed' => 'Gagal', 'expired' => 'Kedaluwarsa', 'cancelled' => 'Dibatalkan',
            'refunded' => 'Dikembalikan',
        ],
        'invoice_status' => [
            'unpaid' => 'Belum Bayar', 'pending' => 'Menunggu Verifikasi', 'paid' => 'Lunas',
            'failed' => 'Gagal', 'expired' => 'Kedaluwarsa', 'cancelled' => 'Dibatalkan',
            'refunded' => 'Dikembalikan',
        ],
        'document_status' => [
            'not_uploaded' => 'Belum Diunggah', 'under_review' => 'Ditinjau',
            'approved' => 'Disetujui', 'rejected' => 'Ditolak',
        ],
        'review_status' => [
            'uploaded' => 'Terunggah', 'under_review' => 'Ditinjau', 'approved' => 'Disetujui',
            'rejected' => 'Ditolak', 'missing' => 'Belum Ada',
        ],
        'participant_status' => [
            'invited' => 'Diundang', 'attended' => 'Hadir', 'absent' => 'Tidak Hadir', 'scored' => 'Nilai Terisi',
        ],
        'selection_result' => ['passed' => 'Lulus', 'failed' => 'Tidak Lulus', 'waitlisted' => 'Daftar Tunggu'],
        'account_status' => [
            'pending_verification' => 'Menunggu Verifikasi', 'active' => 'Aktif',
            'inactive' => 'Tidak Aktif', 'blocked' => 'Diblokir',
        ],
        'applicant_status' => ['active' => 'Aktif', 'inactive' => 'Tidak Aktif'],
        'invoice_type' => ['registration' => 'Pendaftaran', 're_registration' => 'Daftar Ulang', 'other' => 'Lainnya'],
        'stage_type' => [
            'administrative' => 'Administrasi', 'academic_test' => 'Tes Akademik', 'interview' => 'Wawancara',
            'tahfidz' => 'Tahfidz', 'psychological_test' => 'Psikotes', 'health_check' => 'Cek Kesehatan',
            'other' => 'Lainnya',
        ],
        'channel' => ['manual' => 'Manual', 'midtrans' => 'Midtrans (online)'],
        'record_source' => ['uploaded' => 'Berkas terunggah', 'missing' => 'Belum diunggah'],
    ];

    /** Status yang dianggap sudah diterima (untuk pemenuhan kuota). */
    public const ACCEPTED_STATUSES = [
        PpdbRegistration::STATUS_PASSED,
        PpdbRegistration::STATUS_RE_REGISTRATION,
        PpdbRegistration::STATUS_COMPLETED,
    ];

    /** Kolom yang difilter `date_from`/`date_to` per jenis laporan. */
    public const DATE_BASIS = [
        'registration' => 'tanggal pendaftaran dibuat (date_basis=submitted untuk tanggal kirim)',
        'payment' => 'tanggal transaksi: paid_at, lalu issued_at, lalu dibuat',
        'selection' => 'tanggal peserta ditambahkan ke tahap',
        'document' => 'tanggal berkas diunggah (baris "belum ada" memakai tanggal pendaftaran)',
        'applicants' => 'tanggal data calon dibuat',
        'parent_accounts' => 'tanggal akun dibuat',
    ];

    // ==================================================================
    // NORMALISASI & PENERAPAN FILTER
    // ==================================================================

    /**
     * Filter mentah → filter kanonik. Idempoten (penanda `_normalized`).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        if (($raw['_normalized'] ?? false) === true) {
            return $raw;
        }

        $filters = [];

        foreach (self::LIST_PARAMS as $param => $allowed) {
            $filters[$param] = $this->stringList($raw[$param] ?? null, $allowed);
        }

        return $filters + [
            'period_id' => $this->intOrNull($raw['period_id'] ?? null),
            'all_periods' => $this->boolValue($raw['all_periods'] ?? false, false),
            'wave_id' => $this->intOrNull($raw['wave_id'] ?? null),
            'program_id' => $this->intOrNull($raw['program_id'] ?? null),
            'stage_id' => $this->intOrNull($raw['stage_id'] ?? null),
            'requirement_id' => $this->intOrNull($raw['requirement_id'] ?? null),
            'date_from' => $this->dateOrNull($raw['date_from'] ?? null),
            'date_to' => $this->dateOrNull($raw['date_to'] ?? null),
            'date_basis' => in_array((string) ($raw['date_basis'] ?? ''), self::DATE_BASES, true)
                ? (string) $raw['date_basis']
                : 'created',
            'keyword' => trim((string) ($raw['keyword'] ?? '')),
            'granularity' => in_array((string) ($raw['granularity'] ?? ''), self::GRANULARITIES, true)
                ? (string) $raw['granularity']
                : 'monthly',
            'include_missing' => $this->boolValue($raw['include_missing'] ?? true, true),
            '_normalized' => true,
        ];
    }

    /**
     * Cakupan registrasi: query ber-alias `r` di atas `ppdb_registrations`
     * dengan SELURUH filter aplicado. Dasar agregat dashboard & laporan
     * pendaftaran.
     *
     * @param  array<string, mixed>  $filters
     */
    public function registrationQuery(array $filters, string $as = 'r'): BaseBuilder
    {
        return $this->applyFilters(
            DB::table('ppdb_registrations as '.$as),
            $this->normalize($filters),
            $as
        );
    }

    /**
     * Hanya satu kolom id registrasi yang lolos filter — dipakai sebagai
     * `whereIn(..., subquery)` pada tabel anak (invoice, dokumen, peserta)
     * sehingga filter cakupan tidak disalin per tabel.
     *
     * @param  array<string, mixed>  $filters
     */
    public function registrationIds(array $filters, string $column = 'id'): BaseBuilder
    {
        return $this->registrationQuery($filters)->select('r.'.$column);
    }

    /**
     * Terapkan filter cakupan ke query mana pun yang me-join
     * `ppdb_registrations as $as`.
     *
     * `date_from`/`date_to` hanya diterapkan di sini bila `$applyDates` true;
     * laporan bertabel anak (tagihan/berkas) punya basis tanggal sendiri.
     *
     * @param  array<string, mixed>  $f  hasil normalize()
     */
    public function applyFilters(BaseBuilder $query, array $f, string $as = 'r', bool $applyDates = true): BaseBuilder
    {
        $query->whereNull("{$as}.deleted_at")
            ->when($f['period_id'] !== null, fn (BaseBuilder $q) => $q->where("{$as}.period_id", $f['period_id']))
            ->when($f['wave_id'] !== null, fn (BaseBuilder $q) => $q->where("{$as}.wave_id", $f['wave_id']))
            ->when($f['program_id'] !== null, fn (BaseBuilder $q) => $q->where("{$as}.program_id", $f['program_id']))
            ->when($f['status'] !== [], fn (BaseBuilder $q) => $q->whereIn("{$as}.status", $f['status']))
            ->when($f['payment_status'] !== [], fn (BaseBuilder $q) => $q->whereIn("{$as}.payment_status", $f['payment_status']))
            ->when($f['document_status'] !== [], fn (BaseBuilder $q) => $q->whereIn("{$as}.document_status", $f['document_status']))
            ->when($f['selection_result'] !== [], fn (BaseBuilder $q) => $q->whereIn("{$as}.selection_result", $f['selection_result']));

        if ($applyDates) {
            $this->dateRange($query, $f['date_basis'] === 'submitted' ? "{$as}.submitted_at" : "{$as}.created_at", $f);
        }

        return $this->applyKeyword($query, $f, $as);
    }

    /**
     * Ada filter yang membatasi himpunan registrasi (bukan "semua data")?
     *
     * @param  array<string, mixed>  $f
     */
    public function hasRegistrationScope(array $f): bool
    {
        return $f['period_id'] !== null
            || $f['wave_id'] !== null
            || $f['program_id'] !== null
            || $f['status'] !== []
            || $f['payment_status'] !== []
            || $f['document_status'] !== []
            || $f['selection_result'] !== [];
    }

    /**
     * Rentang tanggal pada ekspresi kolom (boleh `coalesce(...)`), inklusif dari
     * 00:00:00 sampai 23:59:59.
     *
     * @param  array<string, mixed>  $f
     */
    public function dateRange(BaseBuilder $query, string $expression, array $f): BaseBuilder
    {
        if ($f['date_from'] !== null) {
            $query->whereRaw("{$expression} >= ?", [$f['date_from'].' 00:00:00']);
        }

        if ($f['date_to'] !== null) {
            $query->whereRaw("{$expression} <= ?", [$f['date_to'].' 23:59:59']);
        }

        return $query;
    }

    /**
     * Kata kunci: nomor pendaftaran, nama calon, atau nama/email akun wali.
     *
     * @param  array<string, mixed>  $f
     */
    protected function applyKeyword(BaseBuilder $query, array $f, string $as = 'r'): BaseBuilder
    {
        if ($f['keyword'] === '') {
            return $query;
        }

        $like = '%'.$this->escapeLike($f['keyword']).'%';

        return $query->where(function (BaseBuilder $w) use ($like, $as): void {
            $w->where("{$as}.registration_number", 'like', $like)
                ->orWhereIn("{$as}.applicant_id", function (BaseBuilder $q) use ($like): void {
                    $q->select('ap.id')->from('ppdb_applicants as ap')->where('ap.full_name', 'like', $like);
                })
                ->orWhereIn("{$as}.parent_account_id", function (BaseBuilder $q) use ($like): void {
                    $q->select('pa.id')
                        ->from('ppdb_parent_accounts as pa')
                        ->leftJoin('users as pu', 'pu.id', '=', 'pa.user_id')
                        ->where(function (BaseBuilder $n) use ($like): void {
                            $n->where('pu.name', 'like', $like)->orWhere('pu.email', 'like', $like);
                        });
                });
        });
    }

    /**
     * Kata kunci pada tabel non-registrasi.
     *
     * @param  array<string, mixed>  $f
     * @param  list<string>  $expressions
     */
    protected function applyKeywordOn(BaseBuilder $query, array $f, array $expressions): BaseBuilder
    {
        if ($f['keyword'] === '') {
            return $query;
        }

        $like = '%'.$this->escapeLike($f['keyword']).'%';

        return $query->where(function (BaseBuilder $w) use ($like, $expressions): void {
            $w->whereRaw("coalesce({$expressions[0]}, '') like ?", [$like]);

            foreach (array_slice($expressions, 1) as $expression) {
                $w->orWhereRaw("coalesce({$expression}, '') like ?", [$like]);
            }
        });
    }

    // ==================================================================
    // ENTRANS LAPORAN / EKSPOR / PDF
    // ==================================================================

    /**
     * Laporan seragam. `$perPage = null` → seluruh baris (dipakai CSV & PDF).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(string $type, array $filters = [], ?int $page = null, ?int $perPage = null): array
    {
        $f = $this->normalize($filters);
        $spec = $this->spec($type, $f);
        $columns = $spec['columns'];

        if ($perPage === null) {
            $items = $spec['query']->get()->all();
            $pagination = null;
        } else {
            $paginator = $spec['query']->paginate($perPage, ['*'], 'page', max(1, (int) $page));
            $items = $paginator->items();
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ];
        }

        return [
            'type' => $type,
            'title' => $spec['title'],
            'generated_at' => now()->toIso8601String(),
            'filters' => $this->filterPayload($f, $type),
            'columns' => $columns,
            'rows' => array_map(fn (object $raw) => $this->row($columns, $raw), $items),
            'summary' => $spec['summary'],
            'pagination' => $pagination,
        ];
    }

    /**
     * Dataset CSV. Header = key kolom (identik dengan `columns` JSON agar
     * hasil ekspor bisa diolah ulang secara otomatis); baris dialirkan dengan
     * cursor sehingga pemakaian memori tetap datar.
     *
     * @param  array<string, mixed>  $filters
     * @return array{type:string,title:string,filename:string,headers:list<string>,rows:Generator<list<mixed>>}
     */
    public function dataset(string $type, array $filters = []): array
    {
        $f = $this->normalize($filters);
        $spec = $this->spec($type, $f);

        return [
            'type' => $type,
            'title' => $spec['title'],
            'filename' => "ppdb-{$type}-".now()->format('Ymd-His').'.csv',
            'headers' => array_column($spec['columns'], 'key'),
            'rows' => $this->streamValues($spec['columns'], $spec['query']->cursor()),
        ];
    }

    /**
     * PDF laporan lewat view bersama `ppdb.report` (Helvetica core font, tanpa
     * penempelan berkas font → berkas ringan).
     *
     * @param  array<string, mixed>  $filters
     */
    public function pdf(string $type, array $filters = []): PDF
    {
        $f = $this->normalize($filters);
        $spec = $this->spec($type, $f);

        $printColumns = array_values(array_filter($spec['columns'], static fn (array $column) => $column['print'] === true));

        $records = $spec['query']->forPage(1, self::PDF_ROW_LIMIT + 1)->get();
        $truncated = $records->count() > self::PDF_ROW_LIMIT;
        $shown = $records->take(self::PDF_ROW_LIMIT);

        $summary = $spec['summary'];
        $total = (int) ($summary['total_rows'] ?? 0);

        return PdfFacade::loadView('ppdb.report', [
            'title' => $spec['title'],
            'subtitle' => 'Modul PPDB · Sekolah ERP',
            'meta' => $this->printableFilters($f, $type) + [
                'Jumlah data' => $this->number($total).' baris'.($truncated ? ' (menampilkan '.count($shown).' baris pertama)' : ''),
            ],
            'cards' => $this->summaryCards($type, $summary),
            'sections' => $this->pdfSections($type, $summary),
            'columns' => array_map(static fn (array $column) => ['key' => $column['key'], 'label' => $column['label']], $printColumns),
            'printColumns' => $printColumns,
            'rows' => array_map(
                fn (object $raw) => array_values(array_map(
                    fn (array $column) => $this->cell($column, $raw->{$column['key']} ?? null, 'pdf'),
                    $printColumns
                )),
                $shown->all()
            ),
            'emptyMessage' => 'Tidak ada data pada cakupan filter ini.',
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', in_array($type, self::REPORT_TYPES, true) ? 'landscape' : 'portrait');
    }

    /**
     * Spesifikasinya satu jenis laporan.
     *
     * @param  array<string, mixed>  $f  filter ternormalisasi
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    public function spec(string $type, array $f): array
    {
        return match ($type) {
            'registration' => $this->registrationSpec($f),
            'payment' => $this->paymentSpec($f),
            'selection' => $this->selectionSpec($f),
            'document' => $this->documentSpec($f),
            'applicants' => $this->applicantSpec($f),
            'parent_accounts' => $this->parentAccountSpec($f),
            default => throw new InvalidArgumentException("Jenis laporan \"{$type}\" tidak dikenal."),
        };
    }

    // ==================================================================
    // 1. LAPORAN PENDAFTARAN
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function registrationSpec(array $f): array
    {
        $query = $this->registrationScope($f)
            ->selectRaw(<<<'SQL'
                r.id as registration_id, r.registration_number, ap.full_name as applicant_name,
                coalesce(ap.nisn, '-') as applicant_nisn, coalesce(gu.full_name, pu.name, '-') as parent_name,
                pd.name as period_name, coalesce(w.name, '-') as wave_name, pg.name as program_name,
                r.status, r.payment_status, r.document_status, coalesce(r.selection_result, '-') as selection_result,
                r.submitted_at, r.verified_at, r.created_at, coalesce(st.nis, '-') as student_nis,
                r.period_id, r.wave_id, r.program_id, r.applicant_id, ap.gender_id
            SQL)
            ->orderBy('r.created_at')
            ->orderBy('r.id');

        return [
            'title' => 'Laporan Pendaftaran PPDB',
            'columns' => [
                $this->column('registration_id', 'ID Pendaftaran', 'integer', false),
                $this->column('registration_number', 'No. Pendaftaran'),
                $this->column('applicant_name', 'Nama Calon'),
                $this->column('applicant_nisn', 'NISN'),
                $this->column('parent_name', 'Orang Tua / Wali'),
                $this->column('period_name', 'Periode'),
                $this->column('wave_name', 'Gelombang'),
                $this->column('program_name', 'Program'),
                $this->column('status', 'Status Pendaftaran', 'status'),
                $this->column('payment_status', 'Status Bayar', 'status'),
                $this->column('document_status', 'Status Dokumen', 'status'),
                $this->column('selection_result', 'Hasil Seleksi', 'status'),
                $this->column('submitted_at', 'Dikirim', 'datetime'),
                $this->column('verified_at', 'Diverifikasi', 'datetime'),
                $this->column('student_nis', 'NIS Siswa'),
                $this->column('created_at', 'Dibuat', 'datetime', false),
                $this->column('period_id', 'ID Periode', 'integer', false),
                $this->column('wave_id', 'ID Gelombang', 'integer', false),
                $this->column('program_id', 'ID Program', 'integer', false),
                $this->column('applicant_id', 'ID Calon', 'integer', false),
                $this->column('gender_id', 'ID Jenis Kelamin', 'integer', false),
            ],
            'query' => $query,
            'summary' => $this->registrationSummary($f),
        ];
    }

    /**
     * Join + filter laporan pendaftaran tanpa select/order (dipakai baris dan
     * agregat ringkasan).
     *
     * @param  array<string, mixed>  $f
     */
    protected function registrationScope(array $f): BaseBuilder
    {
        return $this->registrationQuery($f)
            ->join('ppdb_periods as pd', 'pd.id', '=', 'r.period_id')
            ->whereNull('pd.deleted_at')
            ->join('ppdb_programs as pg', 'pg.id', '=', 'r.program_id')
            ->join('ppdb_applicants as ap', 'ap.id', '=', 'r.applicant_id')
            ->whereNull('ap.deleted_at')
            ->join('ppdb_parent_accounts as pa', 'pa.id', '=', 'r.parent_account_id')
            ->whereNull('pa.deleted_at')
            ->leftJoin('ppdb_waves as w', 'w.id', '=', 'r.wave_id')
            ->leftJoin('users as pu', 'pu.id', '=', 'pa.user_id')
            ->leftJoin('guardians as gu', 'gu.id', '=', 'pa.guardian_id')
            ->leftJoin('students as st', 'st.id', '=', 'r.student_id');
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function registrationSummary(array $f): array
    {
        $totals = (clone $this->registrationScope($f))
            ->selectRaw('count(*) as total_rows, count(distinct r.applicant_id) as applicants, count(distinct r.parent_account_id) as parents')
            ->selectRaw('sum(case when r.student_id is not null then 1 else 0 end) as converted')
            ->selectRaw('sum(case when r.submitted_at is not null then 1 else 0 end) as submitted')
            ->selectRaw('sum(case when r.verified_at is not null then 1 else 0 end) as verified')
            ->first();

        return [
            'total_rows' => (int) ($totals->total_rows ?? 0),
            'applicants' => (int) ($totals->applicants ?? 0),
            'parents' => (int) ($totals->parents ?? 0),
            'converted' => (int) ($totals->converted ?? 0),
            'submitted' => (int) ($totals->submitted ?? 0),
            'verified' => (int) ($totals->verified ?? 0),
            'by_status' => $this->completeMap($this->groupedRegistrationCount($f, 'r.status'), PpdbRegistration::STATUSES),
            'by_payment_status' => $this->completeMap($this->groupedRegistrationCount($f, 'r.payment_status'), PpdbRegistration::PAYMENT_STATUSES),
            'by_document_status' => $this->completeMap($this->groupedRegistrationCount($f, 'r.document_status'), PpdbRegistration::DOCUMENT_STATUSES),
        ];
    }

    // ==================================================================
    // 2. LAPORAN PEMBAYARAN (level tagihan)
    // ==================================================================

    /**
     * Kolom kanal/metode pembayaran sengaja menghitung pembayaran berstatus
     * pending, paid, atau refunded: transfer manual yang menunggu verifikasi
     * bendahara justru harus terlihat metode mana yang dipakai. Percobaan
     * gagal/kedaluwarsa tidak dihitung karena bukan kanal yang sedang berjalan.
     *
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function paymentSpec(array $f): array
    {
        $query = $this->invoiceScope($f)
            ->selectRaw(<<<'SQL'
                i.id as invoice_id, i.number as invoice_number, i.type as invoice_type,
                r.id as registration_id, r.registration_number, ap.full_name as applicant_name,
                coalesce(gu.full_name, pu.name, '-') as parent_name, pd.name as period_name,
                coalesce(w.name, '-') as wave_name, pg.name as program_name, r.payment_status,
                i.total_amount, i.paid_amount,
                case when i.total_amount > i.paid_amount then i.total_amount - i.paid_amount else 0 end as outstanding_amount,
                i.status, i.due_date, i.issued_at, i.paid_at,
                (select group_concat(distinct p.method) from ppdb_payments p
                    where p.invoice_id = i.id and p.status in ('pending', 'paid', 'refunded')) as channels,
                (select group_concat(distinct pm.name) from ppdb_payments p
                    left join payment_methods pm on pm.id = p.payment_method_id
                    where p.invoice_id = i.id and p.status in ('pending', 'paid', 'refunded')) as payment_methods,
                (select vu.name from ppdb_payments p
                    inner join users vu on vu.id = p.verified_by
                    where p.invoice_id = i.id and p.verified_by is not null
                    order by p.id desc limit 1) as verified_by
            SQL)
            ->orderByRaw('coalesce(i.paid_at, i.issued_at, i.created_at) desc')
            ->orderByDesc('i.id');

        return [
            'title' => 'Laporan Pembayaran PPDB',
            'columns' => [
                $this->column('invoice_id', 'ID Tagihan', 'integer', false),
                $this->column('invoice_number', 'No. Tagihan'),
                $this->column('invoice_type', 'Jenis Tagihan', 'status'),
                $this->column('registration_id', 'ID Pendaftaran', 'integer', false),
                $this->column('registration_number', 'No. Pendaftaran'),
                $this->column('applicant_name', 'Nama Calon'),
                $this->column('parent_name', 'Orang Tua / Wali'),
                $this->column('program_name', 'Program'),
                $this->column('total_amount', 'Total Tagihan', 'money'),
                $this->column('paid_amount', 'Dibayar', 'money'),
                $this->column('outstanding_amount', 'Sisa Bayar', 'money'),
                $this->column('status', 'Status Tagihan', 'status', true, 'invoice_status'),
                $this->column('due_date', 'Tenggat', 'date'),
                $this->column('issued_at', 'Diterbitkan', 'datetime'),
                $this->column('paid_at', 'Tanggal Bayar', 'datetime'),
                $this->column('channels', 'Kanal Bayar'),
                $this->column('payment_methods', 'Metode Bayar'),
                $this->column('verified_by', 'Diverifikasi Oleh'),
                $this->column('payment_status', 'Status Bayar Pendaftaran', 'status', false),
                $this->column('period_name', 'Periode', 'text', false),
                $this->column('wave_name', 'Gelombang', 'text', false),
            ],
            'query' => $query,
            'summary' => $this->paymentSummary($f),
        ];
    }

    /**
     * Tagihan dalam cakupan filter (tanpa select/order agar bisa diagregasi).
     *
     * @param  array<string, mixed>  $f
     */
    public function invoiceScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_invoices as i')
            ->join('ppdb_registrations as r', 'r.id', '=', 'i.registration_id')
            ->whereNull('i.deleted_at')
            ->join('ppdb_periods as pd', 'pd.id', '=', 'r.period_id')
            ->whereNull('pd.deleted_at')
            ->join('ppdb_programs as pg', 'pg.id', '=', 'r.program_id')
            ->join('ppdb_applicants as ap', 'ap.id', '=', 'r.applicant_id')
            ->whereNull('ap.deleted_at')
            ->join('ppdb_parent_accounts as pa', 'pa.id', '=', 'r.parent_account_id')
            ->whereNull('pa.deleted_at')
            ->leftJoin('ppdb_waves as w', 'w.id', '=', 'r.wave_id')
            ->leftJoin('users as pu', 'pu.id', '=', 'pa.user_id')
            ->leftJoin('guardians as gu', 'gu.id', '=', 'pa.guardian_id');

        $this->applyFilters($query, $f, 'r', false);
        $query->when($f['invoice_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('i.status', $f['invoice_status']));

        return $this->dateRange($query, 'coalesce(i.paid_at, i.issued_at, i.created_at)', $f);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function paymentSummary(array $f): array
    {
        $totals = (clone $this->invoiceScope($f))
            ->selectRaw('count(*) as total_rows, sum(i.total_amount) as billed, sum(i.paid_amount) as paid')
            ->selectRaw('sum(case when i.total_amount > i.paid_amount then i.total_amount - i.paid_amount else 0 end) as outstanding')
            ->selectRaw("sum(case when i.status not in ('cancelled', 'refunded') then i.total_amount else 0 end) as net_billed")
            ->selectRaw("sum(case when i.status in ('unpaid', 'pending', 'failed', 'expired') and i.due_date is not null and i.due_date < ? then 1 else 0 end) as overdue", [now()->toDateString()])
            ->first();

        $payments = (clone $this->invoiceScope($f))
            ->join('ppdb_payments as p', 'p.invoice_id', '=', 'i.id')
            ->selectRaw('p.status as bucket, count(*) as total, sum(p.amount) as amount')
            ->groupBy('bucket')
            ->get()
            ->collect();

        return [
            'total_rows' => (int) ($totals->total_rows ?? 0),
            'billed_amount' => round((float) ($totals->billed ?? 0), 2),
            'net_billed_amount' => round((float) ($totals->net_billed ?? 0), 2),
            'paid_amount' => round((float) ($totals->paid ?? 0), 2),
            'outstanding_amount' => round((float) ($totals->outstanding ?? 0), 2),
            'overdue_invoices' => (int) ($totals->overdue ?? 0),
            'collected_amount' => round((float) $this->bucketAmount($payments, 'paid'), 2),
            'awaiting_verification_amount' => round((float) $this->bucketAmount($payments, 'pending'), 2),
            'refunded_amount' => round((float) $this->bucketAmount($payments, 'refunded'), 2),
            'by_status' => $this->completeAmountMap($this->invoiceStatusAmount($f), PpdbInvoice::STATUSES),
            'by_channel' => $this->paymentSplit($f, 'p.method', 'channel'),
            'by_payment_method' => $this->paymentSplit($f, "coalesce(pm.name, 'Tidak diketahui')", null),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, array{count:int, amount:float, paid:float}>
     */
    protected function invoiceStatusAmount(array $f): array
    {
        return (clone $this->invoiceScope($f))
            ->selectRaw('i.status as bucket, count(*) as total, sum(i.total_amount) as amount, sum(i.paid_amount) as paid')
            ->groupBy('bucket')
            ->get()
            ->mapWithKeys(static fn ($row) => [(string) $row->bucket => [
                'count' => (int) $row->total,
                'amount' => round((float) $row->amount, 2),
                'paid' => round((float) $row->paid, 2),
            ]])
            ->all();
    }

    /**
     * Rincian pembayaran lunas per kanal (manual/midtrans) atau per metode
     * pembayaran master.
     *
     * @param  array<string, mixed>  $f
     * @return list<array<string, mixed>>
     */
    protected function paymentSplit(array $f, string $expression, ?string $labelGroup): array
    {
        $query = (clone $this->invoiceScope($f))
            ->join('ppdb_payments as p', 'p.invoice_id', '=', 'i.id')
            ->where('p.status', 'paid');

        if ($labelGroup === null) {
            $query->leftJoin('payment_methods as pm', 'pm.id', '=', 'p.payment_method_id');
        }

        return $query->selectRaw("{$expression} as bucket, count(*) as total, sum(p.amount) as amount")
            ->groupBy('bucket')
            ->orderByDesc('total')
            ->get()
            ->map(static function ($row) use ($labelGroup): array {
                $value = (string) ($row->bucket ?? '');
                $label = $value === '' ? 'Tidak diketahui' : ($labelGroup ? (self::LABELS[$labelGroup][$value] ?? $value) : $value);

                return [
                    'key' => $value,
                    'label' => $label,
                    'payments' => (int) $row->total,
                    'amount' => round((float) $row->amount, 2),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $rows
     */
    protected function bucketAmount($rows, string $bucket): float
    {
        $row = $rows->firstWhere('bucket', $bucket);

        return (float) ($row->amount ?? 0);
    }

    // ==================================================================
    // 3. LAPORAN SELEKSI (pendaftaran × tahap)
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function selectionSpec(array $f): array
    {
        $query = $this->selectionScope($f)
            ->selectRaw(<<<'SQL'
                sp.id as participant_id, r.id as registration_id, r.registration_number,
                ap.full_name as applicant_name, pd.name as period_name, coalesce(w.name, '-') as wave_name,
                pg.name as program_name, s.id as stage_id, s.name as stage_name, s.type as stage_type,
                s.status as stage_status, s.scheduled_at, coalesce(s.location, '-') as location,
                s.passing_score, s.quota as stage_quota, r.status as registration_status,
                sp.status as participant_status, sp.score, coalesce(sp.result, '-') as result,
                sp.attended_at, sp.created_at, coalesce(su.name, '-') as scored_by
            SQL)
            ->orderBy('s.order')
            ->orderBy('s.id')
            ->orderBy('r.registration_number');

        return [
            'title' => 'Laporan Seleksi PPDB',
            'columns' => [
                $this->column('participant_id', 'ID Peserta', 'integer', false),
                $this->column('registration_id', 'ID Pendaftaran', 'integer', false),
                $this->column('registration_number', 'No. Pendaftaran'),
                $this->column('applicant_name', 'Nama Calon'),
                $this->column('program_name', 'Program'),
                $this->column('wave_name', 'Gelombang'),
                $this->column('stage_name', 'Tahap'),
                $this->column('stage_type', 'Jenis Tahap', 'status'),
                $this->column('scheduled_at', 'Jadwal', 'datetime'),
                $this->column('location', 'Lokasi'),
                $this->column('participant_status', 'Status Peserta', 'status'),
                $this->column('attended_at', 'Kehadiran', 'datetime'),
                $this->column('score', 'Nilai', 'number'),
                $this->column('passing_score', 'KKTP', 'number'),
                $this->column('result', 'Hasil Tahap', 'status', true, 'selection_result'),
                $this->column('scored_by', 'Penilai'),
                $this->column('stage_status', 'Status Tahap', 'text', false),
                $this->column('stage_quota', 'Kuota Tahap', 'integer', false),
                $this->column('registration_status', 'Status Pendaftaran', 'status', false),
                $this->column('period_name', 'Periode', 'text', false),
                $this->column('created_at', 'Didaftarkan', 'datetime', false),
            ],
            'query' => $query,
            'summary' => $this->selectionSummary($f),
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public function selectionScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_selection_participants as sp')
            ->join('ppdb_selection_stages as s', 's.id', '=', 'sp.stage_id')
            ->whereNull('s.deleted_at')
            ->join('ppdb_registrations as r', 'r.id', '=', 'sp.registration_id')
            ->join('ppdb_periods as pd', 'pd.id', '=', 'r.period_id')
            ->whereNull('pd.deleted_at')
            ->join('ppdb_programs as pg', 'pg.id', '=', 'r.program_id')
            ->join('ppdb_applicants as ap', 'ap.id', '=', 'r.applicant_id')
            ->whereNull('ap.deleted_at')
            ->leftJoin('ppdb_waves as w', 'w.id', '=', 'r.wave_id')
            ->leftJoin('users as su', 'su.id', '=', 'sp.scored_by');

        $this->applyFilters($query, $f, 'r', false);
        $query->when($f['stage_id'] !== null, fn (BaseBuilder $q) => $q->where('s.id', $f['stage_id']))
            ->when($f['participant_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('sp.status', $f['participant_status']));

        return $this->dateRange($query, 'sp.created_at', $f);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function selectionSummary(array $f): array
    {
        $totals = (clone $this->selectionScope($f))
            ->selectRaw('count(*) as total_rows, count(distinct sp.registration_id) as registrations, count(distinct sp.stage_id) as stages')
            ->selectRaw('count(sp.score) as scored, avg(sp.score) as average_score, max(sp.score) as highest_score, min(sp.score) as lowest_score')
            ->first();

        $byStage = (clone $this->selectionScope($f))
            ->selectRaw('s.id as stage_id, s.name as stage_name, s.type as stage_type, s.passing_score')
            ->selectRaw('count(*) as participants, count(sp.score) as scored, avg(sp.score) as average_score')
            ->selectRaw('max(sp.score) as highest_score, min(sp.score) as lowest_score')
            ->selectRaw("sum(case when sp.status in ('attended', 'scored') then 1 else 0 end) as attended")
            ->selectRaw("sum(case when sp.result = 'passed' then 1 else 0 end) as passed")
            ->selectRaw("sum(case when sp.result = 'failed' then 1 else 0 end) as failed")
            ->groupBy('s.id', 's.name', 's.type', 's.passing_score', 's.order')
            ->orderBy('s.order')
            ->get()
            ->map(static fn ($row) => [
                'stage_id' => (int) $row->stage_id,
                'stage_name' => (string) $row->stage_name,
                'stage_type' => (string) $row->stage_type,
                'participants' => (int) $row->participants,
                'attended' => (int) $row->attended,
                'scored' => (int) $row->scored,
                'average_score' => round((float) $row->average_score, 2),
                'highest_score' => round((float) $row->highest_score, 2),
                'lowest_score' => round((float) $row->lowest_score, 2),
                'passing_score' => round((float) $row->passing_score, 2),
                'passed' => (int) $row->passed,
                'failed' => (int) $row->failed,
            ])
            ->values()
            ->all();

        return [
            'total_rows' => (int) ($totals->total_rows ?? 0),
            'registrations' => (int) ($totals->registrations ?? 0),
            'stages' => (int) ($totals->stages ?? 0),
            'scored' => (int) ($totals->scored ?? 0),
            'average_score' => round((float) ($totals->average_score ?? 0), 2),
            'highest_score' => round((float) ($totals->highest_score ?? 0), 2),
            'lowest_score' => round((float) ($totals->lowest_score ?? 0), 2),
            'by_stage' => $byStage,
            'by_participant_status' => $this->completeMap(
                $this->groupedScopeCount(fn () => $this->selectionScope($f), 'sp.status'),
                PpdbSelectionParticipant::STATUSES
            ),
            'by_result' => $this->completeMap(
                $this->groupedScopeCount(fn () => $this->selectionScope($f), "coalesce(sp.result, 'unrated')"),
                ['passed', 'failed', 'waitlisted', 'unrated']
            ),
        ];
    }

    // ==================================================================
    // 4. LAPORAN DOKUMEN (berkas aktif + persyaratan wajib yang hilang)
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function documentSpec(array $f): array
    {
        $rows = $this->documentUploadRows($f);

        if ($f['include_missing']) {
            $rows = $rows->unionAll($this->documentMissingRows($f));
        }

        $query = DB::query()->fromSub($rows, 'doc');

        $query->when($f['review_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('doc.status', $f['review_status']))
            ->when($f['requirement_id'] !== null, fn (BaseBuilder $q) => $q->where('doc.requirement_id', $f['requirement_id']));

        $this->dateRange($query, 'doc.recorded_at', $f);

        $query->orderBy('doc.registration_number')->orderBy('doc.requirement_name')->orderBy('doc.record_source');

        return [
            'title' => 'Laporan Dokumen Pendaftaran',
            'columns' => [
                $this->column('registration_id', 'ID Pendaftaran', 'integer', false),
                $this->column('registration_number', 'No. Pendaftaran'),
                $this->column('applicant_name', 'Nama Calon'),
                $this->column('program_name', 'Program'),
                $this->column('wave_name', 'Gelombang'),
                $this->column('requirement_name', 'Persyaratan'),
                $this->column('required', 'Wajib', 'boolean'),
                $this->column('record_source', 'Sumber Baris', 'status'),
                $this->column('status', 'Status Tinjau', 'status', true, 'review_status'),
                $this->column('version', 'Versi', 'integer'),
                $this->column('uploaded_at', 'Diunggah', 'datetime'),
                $this->column('reviewed_at', 'Ditinjau', 'datetime'),
                $this->column('reviewer_name', 'Peninjau'),
                $this->column('registration_status', 'Status Pendaftaran', 'status', false),
                $this->column('period_name', 'Periode', 'text', false),
                $this->column('requirement_id', 'ID Persyaratan', 'integer', false),
                $this->column('document_id', 'ID Berkas', 'integer', false),
            ],
            'query' => $query,
            'summary' => $this->documentSummary($f),
        ];
    }

    /**
     * Berkas versi aktif milik registrasi terfilter (join + filter, tanpa
     * select/order).
     *
     * @param  array<string, mixed>  $f
     */
    public function documentUploadScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_applicant_documents as d')
            ->join('ppdb_registrations as r', 'r.id', '=', 'd.registration_id')
            ->whereNull('d.deleted_at')
            ->where('d.is_active', true)
            ->join('ppdb_applicants as ap', 'ap.id', '=', 'd.applicant_id')
            ->whereNull('ap.deleted_at')
            ->join('ppdb_programs as pg', 'pg.id', '=', 'r.program_id')
            ->join('ppdb_periods as pd', 'pd.id', '=', 'r.period_id')
            ->whereNull('pd.deleted_at')
            ->leftJoin('ppdb_waves as w', 'w.id', '=', 'r.wave_id')
            ->leftJoin('ppdb_document_requirements as dr', 'dr.id', '=', 'd.requirement_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'd.reviewed_by');

        $this->applyFilters($query, $f, 'r', false);
        $query->when($f['requirement_id'] !== null, fn (BaseBuilder $q) => $q->where('d.requirement_id', $f['requirement_id']));

        return $query;
    }

    /**
     * Daftar berkas (satu sisi UNION ALL). Urutan kolom HARUS identik dengan
     * sisi "belum ada" — keduanya memakai konstanta kolom yang sama.
     *
     * @param  array<string, mixed>  $f
     */
    protected function documentUploadRows(array $f): BaseBuilder
    {
        return $this->documentUploadScope($f)->selectRaw(<<<'SQL'
            d.id as document_id, r.id as registration_id, r.registration_number, ap.full_name as applicant_name,
            pg.name as program_name, coalesce(w.name, '-') as wave_name, pd.name as period_name,
            coalesce(d.requirement_id, 0) as requirement_id, d.requirement_name as requirement_name,
            coalesce(dr.required, 0) as required,
            d.version as version, d.status as status, d.review_note as review_note,
            d.uploaded_at as uploaded_at, d.reviewed_at as reviewed_at,
            ru.name as reviewer_name, coalesce(d.uploaded_at, d.created_at) as recorded_at,
            r.status as registration_status, 'uploaded' as record_source
        SQL);
    }

    /**
     * Persyaratan WAJIB & AKTIF yang belum punya berkas aktif. Aturan scope-nya
     * sama dengan `PpdbDocumentRequirement::scopeForScope()` (syarat tanpa
     * program/gelombang berlaku untuk semua) tetapi dikerjakan full SQL:
     * join persyaratan + `not exists` berkas aktif.
     *
     * Registrasi `draft`/`cancelled` tidak dihitung belum lengkap: yang pertama
     * belum sempat mengunggah, yang kedua sudah mengundurkan diri.
     *
     * @param  array<string, mixed>  $f
     */
    protected function documentMissingScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_registrations as r')
            ->join('ppdb_document_requirements as dr', function ($join): void {
                $join->on('dr.period_id', '=', 'r.period_id')
                    ->where('dr.active', true)
                    ->where('dr.required', true)
                    ->where(function (BaseBuilder $w): void {
                        $w->whereNull('dr.program_id')->orWhereColumn('dr.program_id', '=', 'r.program_id');
                    })
                    ->where(function (BaseBuilder $w): void {
                        $w->whereNull('dr.wave_id')->orWhereColumn('dr.wave_id', '=', 'r.wave_id');
                    });
            })
            ->join('ppdb_applicants as ap', 'ap.id', '=', 'r.applicant_id')
            ->whereNull('ap.deleted_at')
            ->join('ppdb_programs as pg', 'pg.id', '=', 'r.program_id')
            ->join('ppdb_periods as pd', 'pd.id', '=', 'r.period_id')
            ->whereNull('pd.deleted_at')
            ->leftJoin('ppdb_waves as w', 'w.id', '=', 'r.wave_id')
            ->whereNotIn('r.status', [PpdbRegistration::STATUS_DRAFT, PpdbRegistration::STATUS_CANCELLED])
            ->whereNotExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('ppdb_applicant_documents as d')
                    ->whereNull('d.deleted_at')
                    ->where('d.is_active', true)
                    ->whereColumn('d.registration_id', '=', 'r.id')
                    ->whereColumn('d.requirement_id', '=', 'dr.id');
            });

        $this->applyFilters($query, $f, 'r', false);
        $query->when($f['requirement_id'] !== null, fn (BaseBuilder $q) => $q->where('dr.id', $f['requirement_id']));

        return $query;
    }

    /**
     * Sisi "belum ada" dari UNION ALL; daftar kolomnya identik dengan
     * `documentUploadRows()`.
     *
     * @param  array<string, mixed>  $f
     */
    protected function documentMissingRows(array $f): BaseBuilder
    {
        return $this->documentMissingScope($f)->selectRaw(<<<'SQL'
            null as document_id, r.id as registration_id, r.registration_number, ap.full_name as applicant_name,
            pg.name as program_name, coalesce(w.name, '-') as wave_name, pd.name as period_name,
            dr.id as requirement_id, dr.name as requirement_name,
            1 as required,
            null as version, 'missing' as status, null as review_note,
            null as uploaded_at, null as reviewed_at,
            null as reviewer_name, r.created_at as recorded_at,
            r.status as registration_status, 'missing' as record_source
        SQL);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    protected function documentSummary(array $f): array
    {
        $uploaded = (clone $this->documentUploadScope($f))
            ->selectRaw('count(*) as document_count, count(distinct d.registration_id) as registrations')
            ->selectRaw("sum(case when d.status = 'approved' then 1 else 0 end) as approved")
            ->selectRaw("sum(case when d.status = 'rejected' then 1 else 0 end) as rejected")
            ->selectRaw("sum(case when d.status in ('uploaded', 'under_review') then 1 else 0 end) as awaiting_review")
            ->selectRaw('sum(case when dr.required = 1 then 1 else 0 end) as required_uploaded')
            ->first();

        $byStatus = (clone $this->documentUploadScope($f))
            ->selectRaw('d.status as bucket, count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(static fn ($total, $key) => [(string) $key => (int) $total])
            ->all();

        // Baris "belum ada" hanya ikut dihitung bila filter status tinjau masih
        // mengizinkan nilai `missing`.
        $countMissing = $f['include_missing'] && ($f['review_status'] === [] || in_array('missing', $f['review_status'], true));

        $missing = $countMissing
            ? (clone $this->documentMissingScope($f))
                ->selectRaw('count(*) as pairs, count(distinct r.id) as registrations')
                ->first()
            : null;

        $missingBreakdown = $countMissing
            ? (clone $this->documentMissingScope($f))
                ->selectRaw('dr.id as requirement_id, dr.code as requirement_code, dr.name as requirement_name')
                ->selectRaw('count(distinct r.id) as missing_count')
                ->groupBy('dr.id', 'dr.code', 'dr.name')
                ->orderByDesc('missing_count')
                ->get()
                ->map(static fn ($row) => [
                    'requirement_id' => (int) $row->requirement_id,
                    'requirement_code' => (string) $row->requirement_code,
                    'requirement_name' => (string) $row->requirement_name,
                    'missing_count' => (int) $row->missing_count,
                ])
                ->values()
                ->all()
            : [];

        $missingPairs = (int) ($missing->pairs ?? 0);
        $incomplete = (int) ($missing->registrations ?? 0);
        $registrations = (int) (clone $this->registrationQuery($f))->count();
        $documentCount = (int) ($uploaded->document_count ?? 0);
        $requiredTotal = (int) ($uploaded->required_uploaded ?? 0) + $missingPairs;

        // Penyebut kelengkapan = pendaftaran yang memang wajib menyetor berkas
        // (draft belum sempat mengunggah, cancelled sudah mengundurkan diri),
        // supaya persentasenya tidak menggelembung.
        $subject = (int) $this->documentSubject($f);
        $complete = $countMissing ? max(0, $subject - $incomplete) : 0;

        return [
            'total_rows' => $documentCount + $missingPairs,
            'document_count' => $documentCount,
            'versions_uploaded' => (int) $this->documentVersions($f),
            'registrations' => $registrations,
            'registrations_subject' => $subject,
            'registrations_with_documents' => (int) ($uploaded->registrations ?? 0),
            'approved' => (int) ($uploaded->approved ?? 0),
            'rejected' => (int) ($uploaded->rejected ?? 0),
            'awaiting_review' => (int) ($uploaded->awaiting_review ?? 0),
            'required_total' => $requiredTotal,
            'by_status' => $this->completeMap($byStatus, PpdbApplicantDocument::STATUSES),
            'missing' => [
                'basis' => 'persyaratan wajib & aktif per periode/program/gelombang; pendaftaran draft & cancelled tidak dinilai kelengkapannya',
                'required_documents_missing' => $missingPairs,
                'registrations_incomplete' => $incomplete,
                'registrations_complete' => $complete,
                'completeness_percent' => $subject > 0 ? round($complete / $subject * 100, 2) : 0.0,
                'by_requirement' => $missingBreakdown,
            ],
        ];
    }

    /**
     * Jumlah pendaftaran yang wajib menyetor berkas pada cakupan ini:
     * selain draft dan cancelled. Sama dengan basis `documentMissingScope()`.
     *
     * @param  array<string, mixed>  $f
     */
    protected function documentSubject(array $f): int
    {
        return (int) (clone $this->registrationQuery($f))
            ->whereNotIn('r.status', [PpdbRegistration::STATUS_DRAFT, PpdbRegistration::STATUS_CANCELLED])
            ->count();
    }

    /**
     * Total versi berkas (termasuk versi lama) dalam cakupan — menunjukkan
     * berapa kali calon mengunggah ulang dokumennya.
     *
     * @param  array<string, mixed>  $f
     */
    protected function documentVersions(array $f): int
    {
        $query = DB::table('ppdb_applicant_documents as d')
            ->join('ppdb_registrations as r', 'r.id', '=', 'd.registration_id')
            ->whereNull('d.deleted_at');

        $this->applyFilters($query, $f, 'r', false);

        return (int) (clone $query)->count();
    }

    // ==================================================================
    // 5. DAFTAR DATAR: CALON & AKUN ORANG TUA
    // ==================================================================

    /**
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function applicantSpec(array $f): array
    {
        $query = $this->applicantScope($f)
            ->selectRaw(<<<'SQL'
                a.id as applicant_id, a.full_name as applicant_name, coalesce(a.nickname, '-') as nickname,
                coalesce(gd.name, '-') as gender_name, coalesce(a.birth_place, '-') as birth_place,
                a.birth_date, a.nik, a.nisn, coalesce(a.previous_school, '-') as previous_school,
                coalesce(tpg.name, '-') as target_program_name, coalesce(gu.full_name, pu.name, '-') as parent_name,
                coalesce(pu.email, '-') as parent_email, coalesce(pu.phone, '-') as parent_phone,
                coalesce(a.address, '-') as address, pa.account_status, a.status as applicant_status,
                (select count(*) from ppdb_registrations rr
                    where rr.applicant_id = a.id and rr.deleted_at is null) as registration_count,
                a.created_at
            SQL)
            ->orderBy('a.full_name')
            ->orderBy('a.id');

        return [
            'title' => 'Daftar Calon Peserta Didik',
            'columns' => [
                $this->column('applicant_id', 'ID Calon', 'integer', false),
                $this->column('applicant_name', 'Nama Calon'),
                $this->column('nickname', 'Nama Panggilan', 'text', false),
                $this->column('gender_name', 'Jenis Kelamin'),
                $this->column('birth_place', 'Tempat Lahir'),
                $this->column('birth_date', 'Tanggal Lahir', 'date'),
                $this->column('nik', 'NIK Calon'),
                $this->column('nisn', 'NISN'),
                $this->column('previous_school', 'Asal Sekolah'),
                $this->column('target_program_name', 'Program Dituju'),
                $this->column('parent_name', 'Orang Tua / Wali'),
                $this->column('parent_email', 'Email Orang Tua'),
                $this->column('parent_phone', 'Telepon Orang Tua'),
                $this->column('account_status', 'Status Akun Wali', 'status'),
                $this->column('applicant_status', 'Status Calon', 'status'),
                $this->column('registration_count', 'Jumlah Pendaftaran', 'integer'),
                $this->column('address', 'Alamat', 'text', false),
                $this->column('created_at', 'Dibuat', 'datetime', false),
            ],
            'query' => $query,
            'summary' => [
                'total_rows' => (int) (clone $this->applicantScope($f))->count('a.id'),
                'parents' => $this->scalar(
                    (clone $this->applicantScope($f))->selectRaw('count(distinct a.parent_account_id) as aggregate')
                ),
                'with_registration' => (int) (clone $this->applicantScope($f))
                    ->whereExists(function ($sub): void {
                        $sub->select(DB::raw(1))
                            ->from('ppdb_registrations as rr')
                            ->whereNull('rr.deleted_at')
                            ->whereColumn('rr.applicant_id', '=', 'a.id');
                    })->count('a.id'),
                'note' => 'NIK/NISN calon disertakan karena dipakai panitia memverifikasi berkas; kolom kredensial akun tidak pernah diekspor.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public function applicantScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_applicants as a')
            ->whereNull('a.deleted_at')
            ->join('ppdb_parent_accounts as pa', 'pa.id', '=', 'a.parent_account_id')
            ->whereNull('pa.deleted_at')
            ->leftJoin('users as pu', 'pu.id', '=', 'pa.user_id')
            ->leftJoin('guardians as gu', 'gu.id', '=', 'pa.guardian_id')
            ->leftJoin('genders as gd', 'gd.id', '=', 'a.gender_id')
            ->leftJoin('ppdb_programs as tpg', 'tpg.id', '=', 'a.target_program_id');

        // Calon tidak terikat periode: batasi lewat pendaftarannya bila ada
        // filter cakupan periode/gelombang/program/status.
        if ($this->hasRegistrationScope($f)) {
            $query->whereIn('a.id', $this->registrationQuery($f)->select('r.applicant_id'));
        }

        $this->dateRange($query, 'a.created_at', $f);
        $query->when($f['applicant_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('a.status', $f['applicant_status']))
            ->when($f['account_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('pa.account_status', $f['account_status']));

        return $this->applyKeywordOn($query, $f, ['a.full_name', 'a.nisn', 'a.nik', 'a.previous_school']);
    }

    /**
     * @param  array<string, mixed>  $f
     * @return array{title:string,columns:list<array<string,mixed>>,query:BaseBuilder,summary:array<string,mixed>}
     */
    protected function parentAccountSpec(array $f): array
    {
        $query = $this->parentAccountScope($f)
            ->selectRaw(<<<'SQL'
                pa.id as parent_account_id, u.name as account_name, u.email, u.phone,
                coalesce(g.full_name, u.name, '-') as parent_name, coalesce(g.phone, u.phone, '-') as guardian_phone,
                pa.account_status, pa.verified_at, pa.last_activity_at, pa.created_at,
                (select count(*) from ppdb_applicants aa
                    where aa.parent_account_id = pa.id and aa.deleted_at is null) as applicant_count,
                (select count(*) from ppdb_registrations rr
                    where rr.parent_account_id = pa.id and rr.deleted_at is null) as registration_count
            SQL)
            ->orderBy('pa.id');

        return [
            'title' => 'Daftar Akun Orang Tua / Wali',
            'columns' => [
                $this->column('parent_account_id', 'ID Akun', 'integer', false),
                $this->column('account_name', 'Nama Akun'),
                $this->column('email', 'Email'),
                $this->column('phone', 'Telepon'),
                $this->column('parent_name', 'Nama Wali'),
                $this->column('guardian_phone', 'Telepon Wali', 'text', false),
                $this->column('account_status', 'Status Akun', 'status'),
                $this->column('applicant_count', 'Jumlah Calon', 'integer'),
                $this->column('registration_count', 'Jumlah Pendaftaran', 'integer'),
                $this->column('verified_at', 'Diverifikasi', 'datetime'),
                $this->column('last_activity_at', 'Aktivitas Terakhir', 'datetime'),
                $this->column('created_at', 'Dibuat', 'datetime', false),
            ],
            'query' => $query,
            'summary' => [
                'total_rows' => (int) (clone $this->parentAccountScope($f))->count('pa.id'),
                'active' => (int) (clone $this->parentAccountScope($f))->where('pa.account_status', PpdbParentAccount::STATUS_ACTIVE)->count('pa.id'),
                'applicants' => (int) (clone $this->parentAccountScope($f))->selectRaw('coalesce(sum((select count(*) from ppdb_applicants aa where aa.parent_account_id = pa.id and aa.deleted_at is null)), 0) as applicants')->first()->applicants ?? 0,
                'by_status' => $this->completeMap(
                    (clone $this->parentAccountScope($f))
                        ->selectRaw('pa.account_status as bucket, count(*) as total')
                        ->groupBy('bucket')
                        ->pluck('total', 'bucket')
                        ->mapWithKeys(static fn ($total, $key) => [(string) $key => (int) $total])
                        ->all(),
                    PpdbParentAccount::STATUSES
                ),
                'note' => 'Kolom kredensial (password, remember_token, token reset) tidak disertakan; hanya identitas & kontak yang diperlukan panitia.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $f
     */
    public function parentAccountScope(array $f): BaseBuilder
    {
        $query = DB::table('ppdb_parent_accounts as pa')
            ->whereNull('pa.deleted_at')
            ->leftJoin('users as u', 'u.id', '=', 'pa.user_id')
            ->leftJoin('guardians as g', 'g.id', '=', 'pa.guardian_id');

        if ($this->hasRegistrationScope($f)) {
            $query->whereIn('pa.id', $this->registrationQuery($f)->select('r.parent_account_id'));
        }

        $this->dateRange($query, 'pa.created_at', $f);
        $query->when($f['account_status'] !== [], fn (BaseBuilder $q) => $q->whereIn('pa.account_status', $f['account_status']));

        return $this->applyKeywordOn($query, $f, ['u.name', 'u.email', 'g.full_name']);
    }

    // ==================================================================
    // PEMBANTU AGREGAT
    // ==================================================================

    /**
     * Hitungan per nilai satu kolom/ekspresi pada cakupan registrasi.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, int>
     */
    public function groupedRegistrationCount(array $f, string $expression): array
    {
        return $this->groupedScopeCount(fn () => $this->registrationQuery($f), $expression);
    }

    /**
     * Hitungan per nilai satu ekspresi pada cakupan apa pun. Pembangun query
     * dikirim sebagai closure agar filter tetap identik dengan baris laporannya.
     *
     * @param  callable():BaseBuilder  $scope
     * @return array<string, int>
     */
    public function groupedScopeCount(callable $scope, string $expression): array
    {
        return (clone $scope())
            ->selectRaw("{$expression} as bucket, count(*) as total")
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->mapWithKeys(static fn ($total, $key) => [(string) $key => (int) $total])
            ->all();
    }

    /**
     * Nilai satu kolom agregat `aggregate` dari query yang sudah membawa
     * `selectRaw('… as aggregate')`. Dipakai karena `count('distinct …')` pada
     * query builder akan mengutip seluruh ekspresi sebagai nama kolom.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function scalar($query): int
    {
        return (int) ($query->first()?->aggregate ?? 0);
    }

    /**
     * Pastikan setiap kunci status ada (0 ketika belum terjadi apa pun).
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function completeMap(array $counts, array $keys): array
    {
        $complete = [];

        foreach ($keys as $key) {
            $complete[$key] = (int) ($counts[$key] ?? 0);
        }

        return $complete;
    }

    /**
     * @param  array<string, array{count:int, amount:float, paid:float}>  $counts
     * @param  list<string>  $keys
     * @return array<string, array{count:int, amount:float, paid:float}>
     */
    protected function completeAmountMap(array $counts, array $keys): array
    {
        $complete = [];

        foreach ($keys as $key) {
            $complete[$key] = $counts[$key] ?? ['count' => 0, 'amount' => 0.0, 'paid' => 0.0];
        }

        return $complete;
    }

    // ==================================================================
    // PEMBANTU BARIS & FORMAT
    // ==================================================================

    /**
     * @return array{key:string,label:string,type:string,print:bool,labels:string|null}
     */
    protected function column(string $key, string $label, string $type = 'text', bool $print = true, ?string $labels = null): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, 'print' => $print, 'labels' => $labels];
    }

    /**
     * Baris JSON/CSV: assoc array berurutan sesuai `columns`.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    protected function row(array $columns, object $raw): array
    {
        $row = [];

        foreach ($columns as $column) {
            $row[$column['key']] = $this->cell($column, $raw->{$column['key']} ?? null, 'data');
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $column
     */
    public function cell(array $column, mixed $value, string $context): mixed
    {
        $type = (string) ($column['type'] ?? 'text');
        $empty = $value === null || $value === '';

        if ($context === 'pdf') {
            return $this->printableCell($column, $value, $type, $empty);
        }

        if ($empty) {
            return in_array($type, ['money', 'number', 'integer', 'boolean'], true) ? null : '';
        }

        return match ($type) {
            'money', 'number', 'percent' => round((float) $value, 2),
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'date' => substr((string) $value, 0, 10),
            'datetime' => $this->dateTimeString($value),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $column
     */
    protected function printableCell(array $column, mixed $value, string $type, bool $empty): string
    {
        if ($empty) {
            return '-';
        }

        return match ($type) {
            'money' => number_format((float) $value, 2, ',', '.'),
            'number' => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), '.'),
            'percent' => number_format((float) $value, 1, ',', '.').' %',
            'integer' => number_format((float) $value, 0, ',', '.'),
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL) ? 'Ya' : 'Tidak',
            'date' => date('d/m/Y', (int) strtotime((string) $value)),
            'datetime' => date('d/m/Y H:i', (int) strtotime($this->dateTimeString($value))),
            'status' => $this->labelOfStatus($column, (string) $value),
            default => (string) $value,
        };
    }

    /**
     * Label Indonesia untuk nilai status: peta label eksplisit pada kolom,
     * lalu peta dengan nama sama dengan kunci kolom, lalu `status`.
     *
     * @param  array<string, mixed>  $column
     */
    protected function labelOfStatus(array $column, string $value): string
    {
        $maps = array_unique(array_values(array_filter([
            $column['labels'] ?? null,
            (string) ($column['key'] ?? ''),
            'status',
        ], static fn ($map) => is_string($map) && $map !== '')));

        foreach ($maps as $map) {
            if (isset(self::LABELS[$map][$value])) {
                return self::LABELS[$map][$value];
            }
        }

        return $value;
    }

    protected function dateTimeString(mixed $value): string
    {
        $string = trim((string) $value);
        $normalized = preg_replace('/\.\d+$/', '', str_replace('T', ' ', $string)) ?? $string;
        $timestamp = strtotime($normalized);

        return $timestamp === false ? $string : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<object>  $records
     * @return Generator<list<mixed>>
     */
    protected function streamValues(array $columns, iterable $records): Generator
    {
        foreach ($records as $record) {
            $row = [];

            foreach ($columns as $column) {
                $row[] = $this->cell($column, $record->{$column['key']} ?? null, 'data');
            }

            yield $row;
        }
    }

    /**
     * Filter yang dikembalikan ke klien: nilai ternormalisasi + label entitas
     * yang difilter (UI tidak perlu menerjemahkan id) + penjelasan basis
     * tanggal.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public function filterPayload(array $f, string $type = 'registration'): array
    {
        $f = $this->normalize($f);
        $payload = array_diff_key($f, array_flip(['_normalized']));

        $payload['report_type'] = $type;
        $payload['date_basis_label'] = self::DATE_BASIS[$type] ?? self::DATE_BASIS['registration'];
        $payload['period'] = $this->labelOf('ppdb_periods', $f['period_id'], 'name', 'code');
        $payload['wave'] = $this->labelOf('ppdb_waves', $f['wave_id'], 'name', 'code');
        $payload['program'] = $this->labelOf('ppdb_programs', $f['program_id'], 'name', 'code');
        $payload['stage'] = $this->labelOf('ppdb_selection_stages', $f['stage_id'], 'name', 'name');
        $payload['requirement'] = $this->labelOf('ppdb_document_requirements', $f['requirement_id'], 'name', 'code');

        return $payload;
    }

    /**
     * Blok filter untuk PDF (label Indonesia, hanya filter yang aktif).
     *
     * @param  array<string, mixed>  $f
     * @return array<string, string>
     */
    protected function printableFilters(array $f, string $type): array
    {
        $payload = $this->filterPayload($f, $type);

        $period = $payload['period'] !== null
            ? trim(($payload['period']['code'] ?? '').' '.$payload['period']['name'])
            : 'Semua periode';

        $dates = $f['date_from'] === null && $f['date_to'] === null
            ? null
            : ($f['date_from'] ?? 'awal').' s/d '.($f['date_to'] ?? 'sekarang');

        return array_filter([
            'Periode' => $period,
            'Gelombang' => $payload['wave']['name'] ?? null,
            'Program' => $payload['program']['name'] ?? null,
            'Tahap seleksi' => $payload['stage']['name'] ?? null,
            'Persyaratan dokumen' => $payload['requirement']['name'] ?? null,
            'Status pendaftaran' => $this->labelList('status', $f['status']),
            'Status pembayaran' => $this->labelList('payment_status', $f['payment_status']),
            'Status kelengkapan' => $this->labelList('document_status', $f['document_status']),
            'Status tagihan' => $this->labelList('invoice_status', $f['invoice_status']),
            'Status tinjau berkas' => $this->labelList('review_status', $f['review_status']),
            'Status peserta seleksi' => $this->labelList('participant_status', $f['participant_status']),
            'Rentang tanggal' => $dates,
            'Dasar tanggal' => self::DATE_BASIS[$type] ?? null,
            'Kata kunci' => $f['keyword'] === '' ? null : $f['keyword'],
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  list<string>  $values
     */
    protected function labelList(string $group, array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return implode(', ', array_map(
            static fn (string $value): string => self::LABELS[$group][$value] ?? $value,
            $values
        ));
    }

    /**
     * Label entitas referensi: `['id'=>..,'name'=>..,'code'=>..]`.
     *
     * @return array{id:int,name:string,code:string|null}|null
     */
    protected function labelOf(string $table, ?int $id, string $nameColumn, string $codeColumn): ?array
    {
        if ($id === null) {
            return null;
        }

        $row = DB::table($table)->where('id', $id)->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => (string) ($row->{$nameColumn} ?? ''),
            'code' => isset($row->{$codeColumn}) ? (string) $row->{$codeColumn} : null,
        ];
    }

    // ==================================================================
    // PDF: KARTU RINGKASAN & TABEL TAMBAHAN
    // ==================================================================

    /**
     * @param  array<string, mixed>  $summary
     * @return list<array{label:string,value:string}>
     */
    protected function summaryCards(string $type, array $summary): array
    {
        $map = match ($type) {
            'registration' => [
                'Total pendaftaran' => ['total_rows', 'count'],
                'Calon' => ['applicants', 'count'],
                'Orang tua' => ['parents', 'count'],
                'Terkirim' => ['submitted', 'count'],
                'Terverifikasi' => ['verified', 'count'],
                'Jadi siswa' => ['converted', 'count'],
            ],
            'payment' => [
                'Jumlah tagihan' => ['total_rows', 'count'],
                'Total tagihan' => ['billed_amount', 'money'],
                'Dibayar' => ['paid_amount', 'money'],
                'Belum terbayar' => ['outstanding_amount', 'money'],
                'Lunas terkumpul' => ['collected_amount', 'money'],
                'Menunggu verifikasi' => ['awaiting_verification_amount', 'money'],
                'Terlambat bayar' => ['overdue_invoices', 'count'],
            ],
            'selection' => [
                'Baris peserta' => ['total_rows', 'count'],
                'Pendaftaran' => ['registrations', 'count'],
                'Tahap' => ['stages', 'count'],
                'Nilai terisi' => ['scored', 'count'],
                'Rata-rata nilai' => ['average_score', 'score'],
                'Nilai tertinggi' => ['highest_score', 'score'],
                'Nilai terendah' => ['lowest_score', 'score'],
            ],
            'document' => [
                'Baris laporan' => ['total_rows', 'count'],
                'Berkas aktif' => ['document_count', 'count'],
                'Disetujui' => ['approved', 'count'],
                'Ditolak' => ['rejected', 'count'],
                'Menunggu tinjau' => ['awaiting_review', 'count'],
                'Dokumen wajib kurang' => ['missing.required_documents_missing', 'count'],
                'Pendaftaran belum lengkap' => ['missing.registrations_incomplete', 'count'],
                'Kelengkapan' => ['missing.completeness_percent', 'percent'],
            ],
            'applicants' => [
                'Total calon' => ['total_rows', 'count'],
                'Punya pendaftaran' => ['with_registration', 'count'],
                'Orang tua' => ['parents', 'count'],
            ],
            'parent_accounts' => [
                'Total akun' => ['total_rows', 'count'],
                'Akun aktif' => ['active', 'count'],
                'Jumlah calon' => ['applicants', 'count'],
            ],
            default => [],
        };

        $cards = [];

        foreach ($map as $label => [$path, $format]) {
            $value = $this->arrayGet($summary, $path);

            $cards[] = [
                'label' => $label,
                'value' => match ($format) {
                    'money' => number_format((float) $value, 2, ',', '.'),
                    'score' => number_format((float) $value, 2, ',', '.'),
                    'percent' => number_format((float) $value, 1, ',', '.').' %',
                    default => number_format((float) $value, 0, ',', '.'),
                },
            ];
        }

        return $cards;
    }

    /**
     * Tabel tambahan di bawah tabel utama (rekapitulasi).
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{title:string,columns:list<string>,rows:list<list<string>>}>
     */
    protected function pdfSections(string $type, array $summary): array
    {
        return match ($type) {
            'registration' => [[
                'title' => 'Rekapitulasi per Status Pendaftaran',
                'columns' => ['Status', 'Jumlah'],
                'rows' => $this->statusRows($summary['by_status'] ?? [], 'status'),
            ]],
            'payment' => [[
                'title' => 'Rekapitulasi per Status Tagihan',
                'columns' => ['Status', 'Jumlah Tagihan', 'Total Tagihan', 'Dibayar'],
                'rows' => array_map(
                    static fn (string $status, array $row): array => [
                        self::LABELS['invoice_status'][$status] ?? $status,
                        number_format($row['count'], 0, ',', '.'),
                        number_format($row['amount'], 2, ',', '.'),
                        number_format($row['paid'], 2, ',', '.'),
                    ],
                    array_keys($summary['by_status'] ?? []),
                    array_values($summary['by_status'] ?? [])
                ),
            ], [
                'title' => 'Rekapitulasi per Kanal Pembayaran',
                'columns' => ['Kanal', 'Jumlah Pembayaran', 'Nominal'],
                'rows' => array_map(
                    static fn (array $row): array => [
                        $row['label'],
                        number_format($row['payments'], 0, ',', '.'),
                        number_format($row['amount'], 2, ',', '.'),
                    ],
                    $summary['by_channel'] ?? []
                ),
            ]],
            'selection' => [[
                'title' => 'Rekapitulasi per Tahap Seleksi',
                'columns' => ['Tahap', 'Jenis', 'Peserta', 'Hadir', 'Nilai Terisi', 'Rata-rata', 'Tertinggi', 'Terendah', 'KKTP', 'Lulus', 'Tidak Lulus'],
                'rows' => array_map(
                    static fn (array $row): array => [
                        $row['stage_name'],
                        self::LABELS['stage_type'][$row['stage_type']] ?? $row['stage_type'],
                        number_format($row['participants'], 0, ',', '.'),
                        number_format($row['attended'], 0, ',', '.'),
                        number_format($row['scored'], 0, ',', '.'),
                        number_format($row['average_score'], 2, ',', '.'),
                        number_format($row['highest_score'], 2, ',', '.'),
                        number_format($row['lowest_score'], 2, ',', '.'),
                        number_format($row['passing_score'], 2, ',', '.'),
                        number_format($row['passed'], 0, ',', '.'),
                        number_format($row['failed'], 0, ',', '.'),
                    ],
                    $summary['by_stage'] ?? []
                ),
            ]],
            'document' => [[
                'title' => 'Rincian Dokumen Wajib yang Belum Diunggah',
                'columns' => ['Kode', 'Persyaratan', 'Jumlah Berkas Kurang'],
                'rows' => array_map(
                    static fn (array $row): array => [
                        $row['requirement_code'],
                        $row['requirement_name'],
                        number_format($row['missing_count'], 0, ',', '.'),
                    ],
                    $summary['missing']['by_requirement'] ?? []
                ),
            ]],
            default => [],
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<list<string>>
     */
    protected function statusRows(array $counts, string $group): array
    {
        $rows = [];

        foreach ($counts as $status => $total) {
            $rows[] = [self::LABELS[$group][$status] ?? (string) $status, number_format((int) $total, 0, ',', '.')];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    protected function arrayGet(array $source, string $path): mixed
    {
        $value = $source;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return 0;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    protected function number(int|float $value): string
    {
        return number_format((float) $value, 0, ',', '.');
    }

    // ==================================================================
    // PEMBANTU TIPE DATA
    // ==================================================================

    protected function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || is_array($value) || is_bool($value)) {
            return null;
        }

        return ctype_digit((string) $value) ? (int) $value : null;
    }

    protected function boolValue(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL) === true;
    }

    protected function dateOrNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        if ($string === '') {
            return null;
        }

        $timestamp = strtotime(substr($string, 0, 10));

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    /**
     * @param  array<array-key, mixed>|string|null  $value
     * @param  list<string>  $allowed
     * @return list<string>
     */
    protected function stringList(mixed $value, array $allowed): array
    {
        $items = is_array($value)
            ? $value
            : (is_string($value) && trim($value) !== '' ? explode(',', $value) : []);

        $clean = array_map(static fn ($item): string => trim((string) $item), $items);

        return array_values(array_unique(array_filter(
            $clean,
            static fn (string $item): bool => $item !== '' && in_array($item, $allowed, true)
        )));
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
