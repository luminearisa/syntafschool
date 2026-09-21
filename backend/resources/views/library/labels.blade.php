<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Label Eksemplar</title>
    <style>
        @page { margin: 8mm; }
        /* Helvetica (font core) agar PDF ringan; DejaVu Sans sebagai fallback. */
        body { font-family: Helvetica, Arial, "DejaVu Sans", sans-serif; color: #0f172a; font-size: 9px; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td {
            width: 33%; border: 1px dashed #cbd5e1; padding: 2mm; vertical-align: top;
        }
        .library { font-size: 8px; color: #64748b; }
        .title { font-size: 10px; font-weight: bold; margin: 1mm 0; }
        .code { font-size: 9px; }
        .barcode { width: 45mm; height: 12mm; }
        .qr { width: 18mm; height: 18mm; }
        .footer { margin-top: 3mm; font-size: 8px; color: #64748b; }
    </style>
</head>
<body>
    <table class="grid">
        @foreach (array_chunk($labels, 3) as $row)
            <tr>
                @foreach ($row as $label)
                    <td>
                        <div class="library">{{ $libraryName }}</div>
                        <div class="title">{{ \Illuminate\Support\Str::limit($label['title'], 60) }}</div>
                        <div class="code">
                            Kode: {{ $label['item_code'] }}<br>
                            Inventaris: {{ $label['accession_number'] ?? '—' }}
                        </div>
                        @if ($label['barcode'])
                            <img class="barcode" src="{{ $label['barcode'] }}" alt="Barcode">
                        @endif
                        <img class="qr" src="{{ $label['qr'] }}" alt="QR">
                    </td>
                @endforeach
                @for ($i = count($row); $i < 3; $i++)
                    <td></td>
                @endfor
            </tr>
        @endforeach
    </table>

    <div class="footer">
        Dicetak {{ $generatedAt }} · total {{ count($labels) }} label
    </div>
</body>
</html>