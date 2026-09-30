<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $letter->number }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 16px; text-align: center; margin: 0; }
        h2 { font-size: 13px; text-align: center; margin: 2px 0 10px; }
        .school { text-align: center; margin-bottom: 8px; }
        .school .name { font-size: 15px; font-weight: bold; }
        .school .addr { font-size: 9px; color: #4b5563; }
        hr { border: none; border-top: 2px solid #111827; margin: 6px 0 14px; }
        .meta { width: 100%; margin-bottom: 12px; }
        .meta td { padding: 2px 4px; vertical-align: top; }
        .label { width: 150px; color: #4b5563; }
        .box { border: 1px solid #d1d5db; padding: 8px 10px; border-radius: 4px; margin: 8px 0; }
        .sign { width: 100%; margin-top: 28px; }
        .sign td { width: 50%; text-align: center; vertical-align: top; }
        .space { height: 64px; }
        .code { margin-top: 18px; font-size: 9px; color: #6b7280; text-align: center; }
        .badge { display: inline-block; border: 1px solid #111827; padding: 1px 6px; font-size: 9px; }
    </style>
</head>
<body>
    <div class="school">
        <div class="name">{{ $schoolName }}</div>
        @if($schoolAddress)<div class="addr">{{ $schoolAddress }}</div>@endif
        <div class="addr">Unit Kesehatan Sekolah (UKS)</div>
    </div>
    <hr>
    <h1>{{ $letter->letter_type->label() }}</h1>
    <h2>Nomor: {{ $letter->number }}</h2>

    <p>Yang bertanda tangan di bawah ini, petugas Usaha Kesehatan Sekolah, menerangkan bahwa:</p>

    <table class="meta">
        <tr><td class="label">Nama siswa</td><td>: {{ $letter->student->full_name ?? '-' }}</td></tr>
        <tr><td class="label">NIS / NISN</td><td>: {{ $letter->student->nis ?? '-' }} / {{ $letter->student->nisn ?? '-' }}</td></tr>
        <tr><td class="label">Berlaku</td><td>: {{ $letter->date_from->translatedFormat('d F Y') }} s.d. {{ $letter->date_to->translatedFormat('d F Y') }}</td></tr>
        @if($letter->visit)
            <tr><td class="label">Kunjungan UKS</td><td>: {{ $letter->visit->code }}</td></tr>
        @endif
    </table>

    @if($letter->diagnosis_note)
        <div class="box">
            <strong>Keterangan</strong><br>{{ $letter->diagnosis_note }}
        </div>
    @endif

    @if($letter->recommendation)
        <div class="box">
            <strong>Anjuran</strong><br>{{ $letter->recommendation }}
        </div>
    @endif

    <p>Demikian surat keterangan ini dibuat untuk digunakan sebagaimana mestinya.</p>

    <table class="sign">
        <tr>
            <td></td>
            <td>
                {{ $letter->created_at?->translatedFormat('d F Y') }}<br>
                Petugas UKS
                <div class="space"></div>
                <strong>{{ $letter->issuedBy->name ?? '____________________' }}</strong>
            </td>
        </tr>
    </table>

    <div class="code">
        <span class="badge">Kode verifikasi: {{ $letter->verification_code }}</span><br>
        Keaslian surat ini dapat diperiksa di halaman verifikasi dokumen sekolah dengan kode di atas.
    </div>
</body>
</html>
