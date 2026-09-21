<?php

namespace App\Http\Controllers\Api;

use App\Models\LibraryBibliography;
use App\Models\LibraryItem;
use App\Models\LibraryMember;
use App\Services\Library\Documents\LibraryDocumentService;
use App\Services\Library\Membership\MemberCardService;
use Barryvdh\DomPDF\PDF;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Dokumen PDF modul Library: kartu anggota, label eksemplar, dan laporan.
 */
class LibraryDocumentController extends BaseApiController
{
    public function __construct(
        protected LibraryDocumentService $documents,
        protected MemberCardService $cards,
    ) {
    }

    /**
     * Kartu anggota (PDF). QR memuat token kartu yang dipakai saat sirkulasi.
     */
    public function memberCard(LibraryMember $member): Response
    {
        $card = $this->cards->activeCard($member);

        return $this->pdfResponse(
            $this->documents->memberCard($member, $card),
            "kartu-anggota-{$member->member_number}.pdf"
        );
    }

    /**
     * Label eksemplar (PDF, barcode Code 128 + QR).
     *
     * Dipanggil dengan `item_ids[]` (hasil multi-pilih) atau `bibliography_id`
     * untuk mencetak seluruh eksemplar satu judul.
     */
    public function labels(Request $request): Response
    {
        $validated = $request->validate([
            'item_ids' => ['nullable', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'exists:library_items,id'],
            'bibliography_id' => ['nullable', 'integer', 'exists:library_bibliographies,id'],
        ]);

        $query = LibraryItem::query()->with(['bibliography:id,title', 'library:id,name']);

        if (! empty($validated['item_ids'])) {
            $query->whereIn('id', $validated['item_ids']);
        } elseif (! empty($validated['bibliography_id'])) {
            $query->where('bibliography_id', $validated['bibliography_id']);
        } else {
            abort(422, 'Pilih minimal satu eksemplar atau satu judul.');
        }

        $items = $query->orderBy('item_code')->get();

        return $this->pdfResponse(
            $this->documents->itemLabels($items),
            'label-eksemplar-'.now()->format('Ymd-His').'.pdf'
        );
    }

    /**
     * Laporan PDF per jenis (koleksi, sirkulasi, anggota, denda, pengadaan,
     * inventaris, reservasi).
     */
    public function report(Request $request, string $type): Response
    {
        // Jenis laporan dibatasi daftar putih agar tidak menjadi 500.
        abort_unless(in_array($type, [
            'collection', 'circulation', 'member', 'fine',
            'acquisition', 'inventory', 'reservation',
        ], true), 404, 'Jenis laporan tidak ditemukan.');

        return $this->pdfResponse(
            $this->documents->report($type, $request->all()),
            "laporan-{$type}-".now()->format('Ymd-His').'.pdf'
        );
    }

    protected function pdfResponse(PDF $pdf, string $filename): Response
    {
        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
        ]);
    }
}