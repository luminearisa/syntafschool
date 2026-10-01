<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate_number }}</title>
    <style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; margin: 0; padding: 48px; color: #14342a; }
        .frame { border: 6px double #1d7a4f; padding: 40px; text-align: center; }
        .kicker { letter-spacing: 4px; text-transform: uppercase; font-size: 12px; color: #4b7c63; }
        h1 { font-size: 30px; margin: 12px 0 4px; }
        .name { font-size: 26px; font-weight: bold; margin: 24px 0 8px; }
        .desc { font-size: 14px; color: #3d5a4c; line-height: 1.6; }
        .meta { margin-top: 32px; font-size: 12px; color: #4b7c63; }
        .code { margin-top: 20px; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="frame">
        <p class="kicker">Syntaf School</p>
        <h1>Sertifikat Aktivitas Siswa</h1>
        <p class="name">{{ $student_name }}</p>
        <p class="desc">{{ $description }}</p>
        <p class="meta">
            Diterbitkan {{ $issued_date }} &middot; {{ $title }}
        </p>
        <p class="code">
            No. {{ $certificate_number }} &middot; Kode verifikasi: {{ $verification_code }}
        </p>
    </div>
</body>
</html>
