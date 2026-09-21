<?php

namespace App\Services\Library\Documents;

use App\Models\LibraryItem;
use App\Models\LibraryMember;
use App\Models\LibraryMemberCard;
use App\Services\Library\Reports\LibraryReportService;
use Barryvdh\DomPDF\PDF;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;

/**
 * Dokumen PDF modul Library (kartu anggota, label eksemplar, laporan).
 *
 * Memakai `barryvdh/laravel-dompdf` (tanpa biner eksternal). Gambar QR dan
 * barcode disematkan sebagai data-URI sehingga PDF mandiri (tidak bergantung
 * berkas lain) dan tetap dapat dipindai.
 */
class LibraryDocumentService
{
    public function __construct(
        protected CodeImageService $codes,
        protected LibraryReportService $reports,
    ) {
    }

    /**
     * Kartu anggota siap cetak (ukuran mendekati CR80).
     */
    public function memberCard(LibraryMember $member, ?LibraryMemberCard $card = null): PDF
    {
        $member->loadMissing(['library', 'membershipType', 'student', 'employee', 'guardian']);

        // QR memuat token kartu — token inilah yang dipindai saat sirkulasi.
        $qrPayload = $card?->qr_token ?? $member->member_number;

        return PdfFacade::loadView('library.member-card', [
            'library' => $member->library,
            'member' => $member,
            'card' => $card,
            'membershipTypeName' => $member->membershipType?->name,
            'photoDataUri' => $this->photoDataUri($member->photo_path),
            'qr' => $this->codes->qrDataUri($qrPayload, 320),
            'barcode' => $this->codes->barcodeDataUri($card?->barcode ?? $member->member_number),
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Label eksemplar (barcode Code 128 + QR berisi kode eksemplar).
     *
     * @param  iterable<LibraryItem>  $items
     */
    public function itemLabels(iterable $items): PDF
    {
        $labels = [];
        $libraryName = null;

        foreach ($items as $item) {
            $item->loadMissing(['bibliography', 'library']);
            $libraryName ??= $item->library?->name;

            $barcodeValue = $item->barcode ?? $item->item_code;

            $labels[] = [
                'title' => (string) ($item->bibliography?->title ?? 'Tanpa judul'),
                'item_code' => $item->item_code,
                'accession_number' => $item->accession_number,
                'barcode' => $this->codes->barcodeDataUri($barcodeValue),
                // QR berisi kode eksemplar agar dapat dipindai di layar sirkulasi.
                'qr' => $this->codes->qrDataUri((string) $barcodeValue, 220),
            ];
        }

        return PdfFacade::loadView('library.labels', [
            'labels' => $labels,
            'libraryName' => $libraryName ?? 'Perpustakaan',
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Laporan PDF dari data laporan modul (§3).
     *
     * @param  array<string, mixed>  $filters
     */
    public function report(string $type, array $filters = []): PDF
    {
        [$title, $meta, $sections] = $this->reportPayload($type, $filters);

        return PdfFacade::loadView('library.report', [
            'title' => $title,
            'subtitle' => 'Perpustakaan · dibuat '.now()->format('d/m/Y H:i'),
            'meta' => $meta,
            'sections' => $sections,
            'generatedAt' => now()->format('d/m/Y H:i'),
        ])->setPaper('a4', 'portrait');
    }

    /**
     * Susun judul, ringkasan, dan tabel untuk tiap jenis laporan.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0:string,1:array<string,string>,2:list<array<string,mixed>>}
     */
    protected function reportPayload(string $type, array $filters): array
    {
        $period = 'Periode: '.($filters['from'] ?? 'awal').' s/d '.($filters['to'] ?? 'sekarang');

        return match ($type) {
            'collection' => (function () use ($filters, $period): array {
                $data = $this->reports->collection($filters);

                return [
                    'Laporan Koleksi',
                    [
                        'Total judul' => (string) $data['totals']['titles'],
                        'Judul aktif' => (string) $data['totals']['titles_active'],
                        'Total eksemplar' => (string) $data['totals']['copies'],
                        'Eksemplar tersedia' => (string) $data['totals']['copies_available'],
                        $period => '',
                    ],
                    [
                        $this->section('Per Kategori', ['Kategori', 'Jumlah'], $data['by_category']),
                        $this->section('Per Jenis Koleksi', ['Jenis', 'Jumlah'], $data['by_collection_type']),
                        $this->section('Per Lokasi', ['Lokasi', 'Jumlah'], $data['by_location']),
                        $this->section('Per Tahun Terbit', ['Tahun', 'Jumlah'], array_map(
                            fn (array $row) => ['label' => (string) $row['year'], 'total' => $row['total']],
                            $data['by_year'],
                        )),
                        [
                            'title' => 'Per Status Eksemplar',
                            'columns' => ['Status', 'Jumlah'],
                            'rows' => collect($data['by_status'])->map(fn ($total, $status) => [$status, (string) $total])->values()->all(),
                        ],
                    ],
                ];
            })(),
            'circulation' => (function () use ($filters, $period): array {
                $data = $this->reports->circulation($filters);

                return [
                    'Laporan Sirkulasi',
                    [
                        'Peminjaman' => (string) $data['borrowed'],
                        'Pengembalian' => (string) $data['returned'],
                        'Masih berjalan' => (string) $data['active'],
                        'Terlambat' => (string) $data['overdue'],
                        'Perpanjangan' => (string) $data['renewals'],
                        $period => '',
                    ],
                    [
                        [
                            'title' => 'Peminjaman & Pengembalian per Bulan',
                            'columns' => ['Bulan', 'Dipinjam', 'Dikembalikan'],
                            'rows' => array_map(
                                fn (array $row) => [$row['month'], (string) $row['borrowed'], (string) $row['returned']],
                                $data['by_month'],
                            ),
                        ],
                        $this->section('Koleksi Paling Sering Dipinjam', ['Judul', 'Peminjaman'], array_map(
                            fn (array $row) => ['label' => (string) ($row['title'] ?? '—'), 'total' => $row['loans']],
                            $data['popular'],
                        )),
                        [
                            'title' => 'Anggota Paling Aktif',
                            'columns' => ['No. Anggota', 'Nama', 'Peminjaman'],
                            'rows' => array_map(
                                fn (array $row) => [(string) ($row['member_number'] ?? '—'), (string) ($row['name'] ?? '—'), (string) $row['loans']],
                                $data['active_members'],
                            ),
                        ],
                    ],
                ];
            })(),
            'member' => (function () use ($filters, $period): array {
                $data = $this->reports->member($filters);

                return [
                    'Laporan Anggota',
                    [
                        'Total anggota' => (string) $data['total'],
                        'Aktif' => (string) $data['active'],
                        'Kedaluwarsa' => (string) $data['expired'],
                        'Menunggu' => (string) $data['pending'],
                        'Ditangguhkan' => (string) $data['suspended'],
                        $period => '',
                    ],
                    [
                        $this->section('Per Jenis Keanggotaan', ['Jenis', 'Jumlah'], $data['by_type']),
                        [
                            'title' => 'Per Jenis Tautan',
                            'columns' => ['Tautan', 'Jumlah'],
                            'rows' => collect($data['by_linked_type'])->map(fn ($total, $key) => [$key, (string) $total])->values()->all(),
                        ],
                    ],
                ];
            })(),
            'fine' => (function () use ($filters, $period): array {
                $data = $this->reports->fine($filters);

                return [
                    'Laporan Denda',
                    [
                        'Jumlah denda' => (string) $data['assessed'],
                        'Nominal denda' => number_format($data['assessed_amount'], 2),
                        'Sudah dibayar' => number_format($data['paid_amount'], 2),
                        'Dibebaskan' => number_format($data['waived_amount'], 2),
                        'Belum dibayar' => number_format($data['outstanding_amount'], 2),
                        $period => '',
                    ],
                    [[
                        'title' => 'Rincian per Jenis Denda',
                        'columns' => ['Jenis', 'Jumlah', 'Nominal'],
                        'rows' => array_map(
                            fn (array $row) => [$row['type'], (string) $row['total'], number_format($row['amount'], 2)],
                            $data['by_type'],
                        ),
                    ]],
                ];
            })(),
            'acquisition' => (function () use ($filters, $period): array {
                $data = $this->reports->acquisition($filters);

                return [
                    'Laporan Pengadaan',
                    [
                        'Jumlah permintaan' => (string) $data['total_requests'],
                        'Total nilai' => number_format($data['total_amount'], 2),
                        'Eksemplar diterima' => (string) $data['received_items'],
                        $period => '',
                    ],
                    [
                        [
                            'title' => 'Per Status',
                            'columns' => ['Status', 'Jumlah'],
                            'rows' => collect($data['by_status'])->map(fn ($total, $status) => [$status, (string) $total])->values()->all(),
                        ],
                        [
                            'title' => 'Per Pemasok',
                            'columns' => ['Pemasok', 'Permintaan', 'Nilai'],
                            'rows' => array_map(
                                fn (array $row) => [$row['label'], (string) $row['total'], number_format($row['amount'], 2)],
                                $data['by_supplier'],
                            ),
                        ],
                    ],
                ];
            })(),
            'inventory' => (function () use ($filters, $period): array {
                $data = $this->reports->inventory($filters);

                return [
                    'Laporan Inventaris / Stock Opname',
                    [
                        'Sesi selesai' => (string) $data['sessions']['totals']['sessions'],
                        'Diharapkan' => (string) $data['sessions']['totals']['expected'],
                        'Ditemukan' => (string) $data['sessions']['totals']['found'],
                        'Hilang' => (string) $data['sessions']['totals']['missing'],
                        'Tak terduga' => (string) $data['sessions']['totals']['unexpected'],
                        $period => '',
                    ],
                    [
                        [
                            'title' => 'Sesi Stock Opname',
                            'columns' => ['Kode', 'Nama', 'Mulai', 'Ekspektasi', 'Temuan', 'Hilang', 'Tak terduga'],
                            'rows' => array_map(fn (array $row) => [
                                $row['code'], $row['name'], (string) ($row['started_at'] ?? '—'),
                                (string) $row['expected'], (string) $row['found'],
                                (string) $row['missing'], (string) $row['unexpected'],
                            ], $data['sessions']['sessions']),
                        ],
                        [
                            'title' => 'Kondisi Koleksi Saat Ini',
                            'columns' => ['Kondisi', 'Jumlah'],
                            'rows' => collect($data['current'])->map(fn ($total, $key) => [$key, (string) $total])->values()->all(),
                        ],
                    ],
                ];
            })(),
            'reservation' => (function () use ($filters, $period): array {
                $libraryId = isset($filters['library_id']) && $filters['library_id'] !== '' ? (int) $filters['library_id'] : null;
                $data = $this->reports->reservation($libraryId);

                return [
                    'Laporan Reservasi',
                    [
                        'Menunggu' => (string) $data['summary']['waiting'],
                        'Siap diambil' => (string) $data['summary']['available'],
                        'Aktif' => (string) $data['summary']['open'],
                        'Kedaluwarsa' => (string) $data['summary']['expired'],
                        $period => '',
                    ],
                    [[
                        'title' => 'Antrean Reservasi',
                        'columns' => ['Kode', 'Judul', 'Anggota', 'Status', 'Antrean', 'Tenggat'],
                        'rows' => array_map(fn (array $row) => [
                            (string) ($row['reservation_code'] ?? '—'),
                            (string) ($row['title'] ?? '—'),
                            (string) ($row['member'] ?? '—'),
                            (string) ($row['status'] ?? '—'),
                            (string) ($row['queue_position'] ?? '—'),
                            (string) ($row['expires_at'] ?? '—'),
                        ], $data['queue']),
                    ]],
                ];
            })(),
            default => throw new \InvalidArgumentException("Jenis laporan \"{$type}\" tidak dikenal."),
        };
    }

    /**
     * @param  list<array{label:string,total:int}>  $rows
     * @return array<string, mixed>
     */
    protected function section(string $title, array $columns, array $rows): array
    {
        return [
            'title' => $title,
            'columns' => $columns,
            'rows' => array_map(fn (array $row) => [$row['label'], (string) $row['total']], $rows),
        ];
    }

    /**
     * Foto anggota sebagai data-URI (bila berkasnya benar-benar ada).
     */
    protected function photoDataUri(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $full = public_path('storage/'.ltrim($path, '/'));

        if (! is_file($full)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($full));
    }
}