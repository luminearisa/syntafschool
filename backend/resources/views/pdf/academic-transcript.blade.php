<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Transkrip {{ $transcript->student?->full_name }}</title>
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
        .muted { color: #64748b; }
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

    <h2 class="title">TRANSKRIP AKADEMIK</h2>

    @php
        $data = $transcript->data ?? [];
        $scopeLabel = data_get($data, 'scope.label', '-');
    @endphp

    <table class="meta">
        <tr>
            <td><strong>Nama</strong></td><td>: {{ $transcript->student?->full_name }}</td>
            <td><strong>NIS</strong></td><td>: {{ $transcript->student?->nis ?? '-' }}</td>
        </tr>
        <tr>
            <td><strong>Cakupan</strong></td><td>: {{ $scopeLabel }}</td>
            <td><strong>Rata-rata</strong></td><td>: {{ data_get($data, 'overall_average') === null ? '-' : number_format((float) data_get($data, 'overall_average'), 2, ',', '.') }}</td>
        </tr>
    </table>

    <table class="scores">
        <thead><tr><th style="width:32px">No</th><th>Mata Pelajaran</th><th>Rincian Nilai</th><th class="right" style="width:90px">Rata-rata</th></tr></thead>
        <tbody>
            @forelse(data_get($data, 'subjects', []) as $index => $subject)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $subject['name'] ?? '-' }}</td>
                    <td class="muted">
                        @forelse($subject['scores'] ?? [] as $score)
                            {{ $score['semester'] ?? '' }} {{ $score['academic_year'] ?? '' }}:
                            <strong>{{ $score['score'] === null ? '-' : number_format((float) $score['score'], 2, ',', '.') }}</strong>@if(!$loop->last); @endif
                        @empty
                            -
                        @endforelse
                    </td>
                    <td class="right">{{ ($subject['average'] ?? null) === null ? '-' : number_format((float) $subject['average'], 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center" class="muted">Belum ada data nilai.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="muted" style="margin-top:12px">Transkrip disusun dari {{ data_get($data, 'report_card_count', 0) }} rapor yang telah diterbitkan. Dibuat pada {{ $transcript->generated_at?->translatedFormat('d F Y H:i') ?? '-' }}.</p>

    <table class="signatures"><tr>
        <td>Kepala Sekolah<div class="space"></div>(________________________)</td>
        <td>Wali Kelas<div class="space"></div>(________________________)</td>
    </tr></table>
</body>
</html>
