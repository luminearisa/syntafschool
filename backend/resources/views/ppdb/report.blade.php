<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 11mm; }
        /* Helvetica = font core dompdf: tidak ada berkas font yang ditempel
           sehingga PDF laporan tetap ringan. */
        body { font-family: Helvetica, Arial, sans-serif; color: #0f172a; font-size: 10.5px; }
        h1 { font-size: 15px; margin: 0 0 1mm; }
        h2 { font-size: 11.5px; margin: 5mm 0 1.5mm; border-bottom: 1px solid #e2e8f0; padding-bottom: 1mm; }
        .subtitle { font-size: 9.5px; color: #64748b; margin-bottom: 3.5mm; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 3.5mm; }
        .meta td { padding: 1mm 2mm; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 9.5px; }
        .meta .label { color: #64748b; width: 32mm; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 2mm 0; margin-bottom: 4mm; }
        .cards td { width: 25%; border: 1px solid #e2e8f0; background: #f8fafc; padding: 2mm; text-align: center; }
        .cards .card-label { font-size: 8.5px; text-transform: uppercase; color: #64748b; }
        .cards .card-value { font-size: 13px; font-weight: bold; color: #0f172a; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th {
            text-align: left; font-size: 8.5px; text-transform: uppercase; color: #64748b;
            background: #f8fafc; border-bottom: 1px solid #cbd5e1; padding: 1.5mm 2mm;
        }
        table.data td { font-size: 9.5px; padding: 1.3mm 2mm; border-bottom: 1px solid #f1f5f9; }
        table.data tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .numeric { text-align: right; }
        .empty { font-size: 10px; color: #94a3b8; padding: 2mm 0; }
        .footer { margin-top: 5mm; font-size: 8.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 1.5mm; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="subtitle">{{ $subtitle }}</div>

    @if (! empty($meta))
        <table class="meta">
            @foreach (array_chunk(array_keys($meta), 2) as $pair)
                <tr>
                    @foreach ($pair as $key)
                        <td class="label">{{ $key }}</td>
                        <td>{{ $meta[$key] }}</td>
                    @endforeach
                    @if (count($pair) === 1)
                        <td class="label"></td><td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    @if (! empty($cards))
        <table class="cards">
            @foreach (array_chunk($cards, 4) as $line)
                <tr>
                    @foreach ($line as $card)
                        <td>
                            <div class="card-label">{{ $card['label'] }}</div>
                            <div class="card-value">{{ $card['value'] }}</div>
                        </td>
                    @endforeach
                    @for ($fill = count($line); $fill < 4; $fill++)
                        <td style="border: none; background: transparent;"></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Detail Data</h2>

    @if (empty($rows))
        <div class="empty">{{ $emptyMessage }}</div>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $column['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $index => $cell)
                            <td class="{{ in_array($printColumns[$index]['type'] ?? 'text', ['money', 'number', 'integer', 'percent'], true) ? 'numeric' : '' }}">
                                {{ $cell }}
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>

        @if (empty($section['rows']))
            <div class="empty">Tidak ada rekapitulasi pada cakupan ini.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($section['columns'] as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="footer">
        Dibuat otomatis oleh modul PPDB &middot; Sekolah ERP &middot; {{ $generatedAt }}
    </div>
</body>
</html>
