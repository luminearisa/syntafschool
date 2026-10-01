<?php

/**
 * Pagar kasar untuk token mobile berperan wali / siswa (`ctx:guardian|student`).
 *
 * Hanya path di bawah ini (relatif terhadap `api/v1/`) yang boleh dipanggil.
 * Ini BUKAN otorisasi data: kepemilikan (anak siapa) tetap ditegakkan modul
 * masing-masing (`assertChild`, provider orang tua, dll). Tambah path baru
 * hanya setelah memastikan controller-nya memverifikasi kepemilikan.
 *
 * Peran pegawai tidak dibatasi path — cukup permission role seperti biasa.
 */
return [
    'allow' => [
        'guardian' => [
            'auth/*',
            'mobile/*',
            'finance/portal/*',
            'boarding/portal/*',
            'clinic/portal/*',
            'bk/portal/*',
            'activities/me',
            'activities/portal/*',
            'tahfiz/dashboards/wali',
            'tahfiz/notifications',
            'tahfiz/notifications/*',
            'lms/auth/me',
            'lms/dashboard/parent',
            'lms/parent/*',
            'library/opac',
            'library/opac/*',
        ],
        'student' => [
            'auth/*',
            'mobile/*',
            'clinic/portal/*',
            'bk/portal/*',
            'activities/me',
            'activities/my-programs',
            'activities/registrations/mine',
            'activities/my-attendance/*',
            'tahfiz/dashboards/peserta',
            'tahfiz/notifications',
            'tahfiz/notifications/*',
            'lms/auth/me',
            'lms/dashboard/student',
            'lms/my/*',
            'library/opac',
            'library/opac/*',
            'library/members/me/card',
        ],
    ],
];
