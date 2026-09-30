<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $table['title'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #111827; }
        .header { border-bottom: 2px solid #166534; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 14px; margin: 0; color: #166534; }
        .header p { margin: 2px 0 0; font-size: 9px; color: #4b5563; }
        .meta { width: 100%; margin-bottom: 8px; }
        .meta td { vertical-align: top; font-size: 9px; }
        .summary { margin-bottom: 10px; }
        .summary span { display: inline-block; border: 1px solid #d1d5db; border-radius: 6px; padding: 3px 6px; margin: 0 4px 4px 0; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 3px 4px; text-align: left; font-size: 8px; }
        table.data td { border: 1px solid #e5e7eb; padding: 3px 4px; font-size: 8px; }
        table.data tr:nth-child(even) td { background: #fafafa; }
        .footer { margin-top: 12px; font-size: 8px; color: #4b5563; }
        .qr { text-align: right; }
        .qr img { width: 90px; height: 90px; }
        .signature { margin-top: 14px; width: 100%; }
        .signature td { font-size: 9px; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $school['name'] }}</h1>
        @if (! empty($school['address']))
            <p>{{ $school['address'] }}</p>
        @endif
        <p><strong>{{ $table['title'] }}</strong> — periode {{ $table['window']['from'] }} s.d. {{ $table['window']['to'] }}</p>
    </div>

    <table class="meta">
        <tr>
            <td>
                <div class="summary">
                    @foreach (array_slice($table['summary'], 0, 8) as $key => $value)
                        @if (is_scalar($value) && $value !== null)
                            <span>{{ str_replace('_', ' ', $key) }}: <strong>{{ $value }}</strong></span>
                        @endif
                    @endforeach
                    <span>jumlah baris: <strong>{{ count($table['rows']) }}</strong></span>
                </div>
            </td>
            <td class="qr" width="120">
                @if ($qrDataUri)
                    <img src="{{ $qrDataUri }}" alt="QR verifikasi">
                    <div style="font-size:7px">Kode: {{ $verificationCode }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($table['headers'] as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($table['rows'] as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell === null ? '—' : $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($table['headers']) }}">Tidak ada data pada periode ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($verificationUrl)
        <div class="footer">
            Dokumen ini sah tanpa tanda tangan basah. Verifikasi keaslian di
            <strong>{{ $verificationUrl }}</strong> dengan kode <strong>{{ $verificationCode }}</strong>.
        </div>
    @endif

    <table class="signature">
        <tr>
            <td>Disusun oleh,<br><br><br>_____________________</td>
            <td>Diperiksa,<br><br><br>_____________________</td>
            <td>Kepala Sekolah,<br><br><br>_____________________</td>
        </tr>
    </table>
</body>
</html>
