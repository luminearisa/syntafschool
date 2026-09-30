<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Formulir Observasi {{ $visit['code'] }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #111827; }
        .header { border-bottom: 2px solid #166534; padding-bottom: 6px; margin-bottom: 10px; }
        .header h1 { font-size: 14px; margin: 0; color: #166534; }
        .header p { margin: 2px 0 0; font-size: 9px; color: #4b5563; }
        .meta { width: 100%; margin-bottom: 8px; }
        .meta td { vertical-align: top; font-size: 9px; padding: 2px 4px; }
        .meta .label { color: #4b5563; width: 90px; }
        .meta .fill { border-bottom: 1px dotted #9ca3af; }
        table.form { width: 100%; border-collapse: collapse; }
        table.form th { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 3px 4px; text-align: left; font-size: 8px; }
        table.form td { border: 1px solid #e5e7eb; padding: 3px 4px; font-size: 8px; vertical-align: top; }
        .section-row td { background: #f3f4f6; font-weight: bold; font-size: 8.5px; }
        .options span { display: inline-block; margin-right: 8px; }
        .box { border: 1px solid #6b7280; width: 22px; height: 14px; display: inline-block; }
        .note { margin-top: 10px; font-size: 8px; color: #4b5563; }
        .signature { margin-top: 16px; width: 100%; }
        .signature td { font-size: 9px; text-align: center; padding-top: 26px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $school['name'] }}</h1>
        <p>
            Formulir Observasi Supervisi (Mode Luring) &middot; {{ $visit['code'] }}
            @if (! empty($school['address'])) &middot; {{ $school['address'] }} @endif
        </p>
    </div>

    <table class="meta">
        <tr>
            <td class="label">Yang disupervisi</td>
            <td class="fill">{{ $visit['supervisee'] ?? '—' }}</td>
            <td class="label">Supervisor</td>
            <td class="fill">{{ $visit['supervisor'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Instrumen</td>
            <td class="fill">{{ $visit['instrument'] ?? '—' }} (v{{ $visit['instrument_version'] ?? '—' }})</td>
            <td class="label">Tanggal</td>
            <td class="fill">{{ $visit['observed_at'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Teknik</td>
            <td class="fill">{{ $visit['activity_kind'] ?? '—' }}</td>
            <td class="label">Kelas / topik</td>
            <td class="fill">{{ $visit['lesson_topic'] ?? '—' }}</td>
        </tr>
    </table>

    <table class="form">
        <thead>
            <tr>
                <th style="width: 22px;">No</th>
                <th>Indikator</th>
                <th style="width: 210px;">Pilihan penilaian</th>
                <th style="width: 46px;">Nilai</th>
                <th style="width: 120px;">Catatan / bukti</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @if ($row['is_section'])
                    <tr class="section-row"><td colspan="5">{{ $row['label'] }}</td></tr>
                @else
                    <tr>
                        <td>{{ $row['no'] }}</td>
                        <td>
                            {{ $row['label'] }}
                            @if (! empty($row['guidance']))
                                <div style="color:#4b5563;">Panduan: {{ $row['guidance'] }}</div>
                            @endif
                        </td>
                        <td class="options">
                            @foreach ($row['options'] as $option)
                                <span><strong>{{ $option['code'] }}</strong> {{ $option['label'] }} ({{ $option['score'] }})</span>
                            @endforeach
                            @if ($row['allow_na'])
                                <span><strong>NA</strong> tidak relevan</span>
                            @endif
                        </td>
                        <td><span class="box"></span> {{ $row['hint'] }}</td>
                        <td></td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    <p class="note">
        <strong>Cara memakai formulir ini:</strong> tulis <em>huruf pilihan</em> (A/B/C/…) pada kolom <em>Nilai</em>,
        atau tulis angkanya bila instrumen meminta angka. Untuk indikator yang tidak relevan, tulis <strong>NA</strong>.
        Setelah kembali ke jaringan, buka kegiatan ini di aplikasi &rarr; <em>Mode Luring</em> &rarr; masukkan nilai
        sesuai formulir, lalu simpan. Aplikasi akan mengirimkan jawaban secara idempoten — tidak ada nilai yang
        terhitung dua kali bila jaringan terputus di tengah jalan.
        @if (! empty($visit['min_comment_percent']))
            Catatan wajib diisi untuk nilai di bawah {{ $visit['min_comment_percent'] }}%.
        @endif
    </p>

    <table class="signature">
        <tr>
            <td>Supervisor<br><br>(..................................)</td>
            <td>Yang disupervisi<br><br>(..................................)</td>
            <td>Kepala Sekolah<br><br>(..................................)</td>
        </tr>
    </table>
</body>
</html>
