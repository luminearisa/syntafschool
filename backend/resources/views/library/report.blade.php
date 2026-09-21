<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 12mm; }
        /* Helvetica (font core) agar PDF ringan; DejaVu Sans sebagai fallback. */
        body { font-family: Helvetica, Arial, "DejaVu Sans", sans-serif; color: #0f172a; font-size: 11px; }
        h1 { font-size: 16px; margin: 0 0 1mm; }
        h2 { font-size: 12px; margin: 6mm 0 2mm; border-bottom: 1px solid #e2e8f0; padding-bottom: 1mm; }
        .subtitle { font-size: 10px; color: #64748b; margin-bottom: 4mm; }
        .meta { width: 100%; border-collapse: collapse; margin-bottom: 4mm; }
        .meta td { padding: 1mm 2mm; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 10px; }
        .meta .label { color: #64748b; width: 35mm; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th {
            text-align: left; font-size: 9px; text-transform: uppercase; color: #64748b;
            border-bottom: 1px solid #cbd5e1; padding: 1.5mm 2mm;
        }
        table.data td { font-size: 10px; padding: 1.5mm 2mm; border-bottom: 1px solid #f1f5f9; }
        .footer { margin-top: 6mm; font-size: 9px; color: #64748b; }
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

    @foreach ($sections as $section)
        <h2>{{ $section['title'] }}</h2>

        @if (empty($section['rows']))
            <div style="font-size: 10px; color: #94a3b8;">Tidak ada data pada periode ini.</div>
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

    <div class="footer">Dibuat otomatis oleh modul Library · {{ $generatedAt }}</div>
</body>
</html>