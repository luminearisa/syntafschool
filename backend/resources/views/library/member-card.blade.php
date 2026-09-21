<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kartu Anggota — {{ $member->member_number }}</title>
    <style>
        @page { margin: 12mm; }
        /* Helvetica = font core dompdf (tanpa penyematan berkas font) sehingga PDF
           tetap ringan; DejaVu Sans hanya fallback bila ada glyph non-Latin. */
        body { font-family: Helvetica, Arial, "DejaVu Sans", sans-serif; color: #0f172a; font-size: 12px; }
        .sheet { width: 100%; }
        /* Ukuran mendekati kartu CR80 (85,6 × 54 mm) */
        .card {
            width: 86mm; height: 54mm; border: 1px solid #cbd5e1; border-radius: 3mm;
            padding: 4mm; margin-bottom: 8mm;
        }
        .card-header { border-bottom: 1px solid #e2e8f0; padding-bottom: 2mm; margin-bottom: 3mm; }
        .library-name { font-size: 13px; font-weight: bold; }
        .library-code { font-size: 9px; color: #64748b; }
        .photo { width: 18mm; height: 22mm; border: 1px solid #cbd5e1; text-align: center; color: #94a3b8; font-size: 8px; }
        .name { font-size: 14px; font-weight: bold; margin-bottom: 1mm; }
        .meta { font-size: 10px; color: #334155; line-height: 1.5; }
        .label { color: #64748b; }
        .qr { width: 26mm; height: 26mm; }
        .barcode { width: 40mm; height: 12mm; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .center { text-align: center; }
        .note { font-size: 9px; color: #64748b; }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="card">
            <div class="card-header">
                <div class="library-name">{{ $library?->name ?? 'Perpustakaan' }}</div>
                <div class="library-code">{{ $library?->code }}{{ $library?->address ? ' · '.$library->address : '' }}</div>
            </div>

            <table>
                <tr>
                    <td style="width: 20mm;">
                        @if ($photoDataUri)
                            <img class="photo" src="{{ $photoDataUri }}" alt="">
                        @else
                            <div class="photo">FOTO</div>
                        @endif
                    </td>
                    <td>
                        <div class="name">{{ $member->display_name ?? $member->name }}</div>
                        <div class="meta">
                            <span class="label">No. Anggota:</span> {{ $member->member_number }}<br>
                            <span class="label">Jenis:</span> {{ $membershipTypeName ?? '—' }}<br>
                            <span class="label">Berlaku s/d:</span>
                            {{ $card?->valid_until?->format('d/m/Y') ?? $member->valid_until?->format('d/m/Y') ?? '—' }}<br>
                            <span class="label">Status:</span> {{ $member->status }}
                        </div>
                    </td>
                    <td class="center" style="width: 28mm;">
                        <img class="qr" src="{{ $qr }}" alt="QR">
                    </td>
                </tr>
            </table>

            @if ($barcode)
                <div style="margin-top: 2mm;">
                    <img class="barcode" src="{{ $barcode }}" alt="Barcode">
                </div>
            @endif
        </div>

        <div class="note">
            Kartu ini milik perpustakaan. QR memuat token kartu dan digunakan untuk peminjaman/pengembalian.
        </div>
    </div>
</body>
</html>