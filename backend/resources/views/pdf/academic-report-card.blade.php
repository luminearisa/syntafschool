<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rapor {{ $reportCard->student?->full_name }}</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; }
        h1, h2, p { margin: 0; }
        .header { text-align: center; border-bottom: 2px solid #0f766e; padding-bottom: 12px; margin-bottom: 16px; }
        .header h1 { font-size: 18px; color: #0f766e; margin-bottom: 4px; }
        .header p { color: #475569; }
        .title { text-align: center; margin: 14px 0; font-size: 15px; }
        .meta { width: 100%; margin-bottom: 14px; }
        .meta td { padding: 3px 8px 3px 0; vertical-align: top; }
        table.scores { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .scores th, .scores td { border: 1px solid #cbd5e1; padding: 7px; }
        .scores th { background: #f1f5f9; text-align: left; }
        .right { text-align: right; }
        .section { margin-top: 18px; }
        .section h2 { font-size: 12px; margin-bottom: 6px; color: #0f766e; }
        .note { border: 1px solid #cbd5e1; padding: 8px; min-height: 30px; white-space: pre-wrap; }
        .signatures { width: 100%; margin-top: 45px; text-align: center; }
        .signatures td { width: 50%; vertical-align: top; }
        .space { height: 58px; }
    </style>
</head>
<body>
    <header class="header">
        <h1>{{ $school?->name ?? 'Satuan Pendidikan' }}</h1>
        @if($school?->address)<p>{{ $school->address }}</p>@endif
        @if($school?->npsn)<p>NPSN: {{ $school->npsn }}</p>@endif
    </header>

    <h2 class="title">LAPORAN HASIL BELAJAR — {{ strtoupper($reportCard->semester?->name ?? '') }}</h2>
    <table class="meta">
        <tr>
            <td><strong>Nama</strong></td><td>: {{ $reportCard->student?->full_name }}</td>
            <td><strong>Kelas</strong></td><td>: {{ $reportCard->schoolClass?->name }}</td>
        </tr>
        <tr>
            <td><strong>NIS</strong></td><td>: {{ $reportCard->student?->nis ?? '-' }}</td>
            <td><strong>Tahun Ajaran</strong></td><td>: {{ $reportCard->academicYear?->name }}</td>
        </tr>
    </table>

    <table class="scores">
        <thead><tr><th style="width:32px">No</th><th>Mata Pelajaran</th><th class="right" style="width:90px">Nilai</th><th style="width:80px">Predikat</th><th style="width:55px">Huruf</th></tr></thead>
        <tbody>
            @foreach($reportCard->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->subject?->name ?? '-' }}</td>
                    <td class="right">{{ $item->score === null ? '-' : number_format((float) $item->score, 2, ',', '.') }}</td>
                    <td>{{ $item->predicate ?? '-' }}</td>
                    <td>{{ $item->grade ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><th colspan="2">Rata-rata</th><th class="right">{{ $reportCard->average_score === null ? '-' : number_format((float) $reportCard->average_score, 2, ',', '.') }}</th><th colspan="2">Peringkat {{ $reportCard->rank ?? '-' }}</th></tr></tfoot>
    </table>

    <div class="section">
        <h2>Ringkasan Kehadiran</h2>
        <table class="meta">
            <tr><td>Hadir</td><td>: {{ data_get($reportCard->attendance_summary, 'present', 0) }}</td><td>Terlambat</td><td>: {{ data_get($reportCard->attendance_summary, 'late', 0) }}</td></tr>
            <tr><td>Sakit</td><td>: {{ data_get($reportCard->attendance_summary, 'sick', 0) }}</td><td>Izin</td><td>: {{ data_get($reportCard->attendance_summary, 'permitted', 0) }}</td></tr>
            <tr><td>Alpa</td><td>: {{ data_get($reportCard->attendance_summary, 'absent', 0) }}</td><td>Persentase hadir</td><td>: {{ number_format((float) data_get($reportCard->attendance_summary, 'percentage', 0), 2, ',', '.') }}%</td></tr>
        </table>
    </div>

    @if($reportCard->notes)
        <div class="section"><h2>Catatan Sekolah</h2><div class="note">{{ $reportCard->notes }}</div></div>
    @endif
    @if($reportCard->homeroom_note)
        <div class="section"><h2>Catatan Wali Kelas</h2><div class="note">{{ $reportCard->homeroom_note }}</div></div>
    @endif

    <table class="signatures"><tr>
        <td>Orang Tua/Wali<div class="space"></div>(________________________)</td>
        <td>Wali Kelas<div class="space"></div>(________________________)</td>
    </tr></table>
</body>
</html>
