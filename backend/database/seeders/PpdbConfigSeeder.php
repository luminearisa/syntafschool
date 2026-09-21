<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\EducationLevel;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbFormField;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbQuestion;
use App\Models\PpdbQuestionOption;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbWave;
use App\Services\Ppdb\PpdbPeriodService;
use App\Services\Ppdb\PpdbScopeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Konfigurasi dasar modul PPDB (iris A1).
 *
 * Isi seeder ini adalah titik awal, bukan satu-satunya sumber kebenaran: seluruh
 * barisnya bisa diubah panitia lewat endpoint ADMIN — KONFIGURASI. Karena itu
 * penulisannya `firstOrCreate` (berdasarkan kunci unik tabel) supaya dijalankan
 * berulang kali tetap aman dan tidak menimpa hasil kerja panitia.
 *
 * Yang dibuat: 1 periode (dibuka lewat aturan transisi yang sama dengan API),
 * 3 gelombang, 4 program, field formulir per section, pertanyaan tambahan
 * (global & per program), persyaratan dokumen (global & khusus tahfidz), dan
 * biaya (global + tambahan satu gelombang).
 */
class PpdbConfigSeeder extends Seeder
{
    public function __construct(
        protected PpdbPeriodService $periods,
        protected PpdbScopeService $scopes,
    ) {
    }

    public function run(): void
    {
        $period = $this->period();
        $waves = $this->waves($period);
        $programs = $this->programs($period);

        $this->formFields($period);
        $this->questions($period, $programs);
        $this->documentRequirements($period, $programs);
        $this->fees($period, $waves);

        // Periode dibuka memakai aturan transisi yang sama dengan API publikasi.
        if (in_array($period->fresh()?->status, [PpdbPeriod::STATUS_DRAFT, PpdbPeriod::STATUS_SCHEDULED], true)) {
            $this->periods->publish($period->fresh());
        }
    }

    /** Periode berjalan dengan jendela pendaftaran yang mencakup hari ini. */
    private function period(): PpdbPeriod
    {
        $year = (int) now()->format('Y');
        $code = 'PPDB-'.$year;

        $existing = PpdbPeriod::query()->firstWhere('code', $code);

        if ($existing !== null) {
            return $existing;
        }

        $announcement = Carbon::today()->addMonths(3);

        // Label periode diambil dari tahun ajaran yang benar-benar ditautkan,
        // bukan dari tahun kalender: kalau tidak, halaman publik menulis
        // "Tahun Ajaran 2026/2027" di atas baris yang menunjuk 2025/2026.
        $schoolYear = AcademicYear::query()
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first();

        $label = $schoolYear?->name ?: ($year.'/'.($year + 1));

        // Kode & slug dibangkitkan/dijamin unik oleh service, sama seperti API.
        return $this->periods->create([
            'academic_year_id' => $schoolYear?->id,
            'name' => "PPDB {$label}",
            'public_title' => "Penerimaan Peserta Didik Baru Tahun Ajaran {$label}",
            'public_description' => 'Pendaftaran peserta didik baru dibuka untuk jenjang SMP dan SMA '
                .'dengan pilihan program reguler, boarding school, dan tahfidz.',
            'contact_name' => 'Panitia PPDB',
            'contact_phone' => '0800-1234-5678',
            'contact_email' => 'ppdb@sekolah.id',
            'contact_address' => 'Jalan Pendidikan No. 1',
            'faq' => [
                ['question' => 'Kapan pendaftaran dibuka?', 'answer' => 'Sesuai jadwal pada halaman ini, gelombang 3 dibuka terakhir.'],
                ['question' => 'Apakah sertifikat tahfidz wajib?', 'answer' => 'Wajib hanya untuk program SMP Tahfidz, dan menjadi nilai tambah.'],
                ['question' => 'Bagaimana membayar biaya pendaftaran?', 'answer' => 'Melalui portal orang tua setelah data formulir lengkap.'],
            ],
            'registration_start' => Carbon::today()->subMonth()->toDateString(),
            'registration_end' => Carbon::today()->addMonths(2)->toDateString(),
            'selection_announcement_date' => $announcement->toDateString(),
            're_registration_start' => $announcement->copy()->addDay()->toDateString(),
            're_registration_end' => $announcement->copy()->addDays(14)->toDateString(),
            'description' => 'Konfigurasi dasar hasil PpdbConfigSeeder.',
            'status' => PpdbPeriod::STATUS_SCHEDULED,
        ]);
    }

    /**
     * Tiga gelombang dengan jendela waktu dibagi rata dari jendela periode.
     *
     * @return Collection<string, PpdbWave>
     */
    private function waves(PpdbPeriod $period): Collection
    {
        $start = Carbon::parse($period->registration_start ?? Carbon::today()->subMonth()->toDateString());
        $end = Carbon::parse($period->registration_end ?? Carbon::today()->addMonths(2)->toDateString());
        $chunk = max(1, (int) ceil($start->copy()->diffInDays($end) / 3));

        $definitions = [
            ['GW1', 'Gelombang 1', 60, 'Kuota terbesar, dibuka lebih dahulu.'],
            ['GW2', 'Gelombang 2', 40, 'Kuota sisa setelah gelombang pertama.'],
            ['GW3', 'Gelombang 3', 24, 'Gelombang terakhir dengan biaya tambahan.'],
        ];

        $waves = new Collection;

        foreach ($definitions as $index => [$code, $name, $quota, $description]) {
            $from = $start->copy()->addDays($index * $chunk);
            $to = $start->copy()->addDays(($index + 1) * $chunk - 1);
            $to = $to->greaterThan($end) ? $end->copy() : $to;

            $waves[$code] = PpdbWave::query()->firstOrCreate(
                ['period_id' => $period->id, 'code' => $code],
                [
                    'name' => $name,
                    'registration_start' => $from->toDateString(),
                    'registration_end' => $to->toDateString(),
                    'quota' => $quota,
                    'priority' => $index + 1,
                    'status' => PpdbWave::STATUS_ACTIVE,
                    'description' => $description,
                ],
            );
        }

        return $waves;
    }

    /**
     * Empat penawaran program, ditautkan ke tingkat pendidikan master bila ada.
     *
     * @return Collection<string, PpdbProgram>
     */
    private function programs(PpdbPeriod $period): Collection
    {
        $definitions = [
            ['SMP-REG', 'SMP Reguler', 'SMP', 60, 'Program SMP dengan pembelajaran pagi sampai siang.'],
            ['SMP-BRD', 'SMP Boarding', 'SMP', 24, 'Program asrama penuh untuk SMP.'],
            ['SMP-TAH', 'SMP Tahfidz', 'SMP', 24, 'Program penghafal Al-Qur\'an dengan target juz.'],
            ['SMA-REG', 'SMA Reguler', 'SMA', 60, 'Program SMA dengan peminatan kelas 11.'],
        ];

        $programs = new Collection;

        foreach ($definitions as $index => [$code, $name, $jenjang, $quota, $description]) {
            $programs[$code] = PpdbProgram::query()->firstOrCreate(
                ['period_id' => $period->id, 'code' => $code],
                [
                    'name' => $name,
                    'education_level_id' => EducationLevel::query()
                        ->where('jenjang', $jenjang)
                        ->orderBy('level_number')
                        ->value('id'),
                    'quota' => $quota,
                    'description' => $description,
                    'status' => PpdbProgram::STATUS_ACTIVE,
                    'sort_order' => $index + 1,
                ],
            );
        }

        return $programs;
    }

    /**
     * Field formulir per section; urutan dinaikkan per section.
     *
     * `options` dibakukan lewat PpdbScopeService supaya bentuk tersimpannya sama
     * dengan hasil form builder berikutnya.
     */
    private function formFields(PpdbPeriod $period): void
    {
        $definitions = [
            // ===== Data calon =====
            $this->field('data_calon', 'full_name', 'Nama Lengkap', 'text', required: true, validation: ['max:150']),
            $this->field('data_calon', 'nickname', 'Nama Panggilan', 'text', validation: ['max:50']),
            $this->field('data_calon', 'nisn', 'NISN', 'text', validation: ['digits_between:4,10']),
            $this->field('data_calon', 'nik', 'NIK', 'text', validation: ['digits:16']),
            $this->field('data_calon', 'birth_place', 'Tempat Lahir', 'text', required: true),
            $this->field('data_calon', 'birth_date', 'Tanggal Lahir', 'date', required: true),
            $this->field('data_calon', 'gender', 'Jenis Kelamin', 'select', required: true, options: [
                ['label' => 'Laki-laki', 'value' => 'L'],
                ['label' => 'Perempuan', 'value' => 'P'],
            ]),
            $this->field('data_calon', 'religion', 'Agama', 'select', required: true, options: [
                'Islam', 'Kristen', 'Katolik', 'Hindu', 'Buddha', 'Konghucu',
            ]),
            $this->field('data_calon', 'child_order', 'Anak Ke-', 'number', validation: ['numeric', 'min:1', 'max:30']),

            // ===== Data orang tua =====
            $this->field('data_orang_tua', 'father_name', 'Nama Ayah Kandung', 'text', required: true, validation: ['max:150']),
            $this->field('data_orang_tua', 'father_occupation', 'Pekerjaan Ayah', 'text', required: true),
            $this->field('data_orang_tua', 'father_phone', 'Nomor HP Ayah', 'phone', required: true),
            $this->field('data_orang_tua', 'mother_name', 'Nama Ibu Kandung', 'text', required: true, validation: ['max:150']),
            $this->field('data_orang_tua', 'mother_occupation', 'Pekerjaan Ibu', 'text'),
            $this->field('data_orang_tua', 'mother_phone', 'Nomor HP Ibu', 'phone'),
            $this->field('data_orang_tua', 'parents_income', 'Penghasilan Orang Tua per Bulan', 'select', options: [
                'Kurang dari 1 juta', '1 - 3 juta', '3 - 5 juta', 'Lebih dari 5 juta',
            ]),

            // ===== Alamat =====
            $this->field('alamat', 'address', 'Alamat Lengkap', 'textarea', required: true),
            $this->field('alamat', 'rt', 'RT', 'text', validation: ['max:5']),
            $this->field('alamat', 'rw', 'RW', 'text', validation: ['max:5']),
            $this->field('alamat', 'village', 'Kelurahan/Desa', 'text'),
            $this->field('alamat', 'district', 'Kecamatan', 'text'),
            $this->field('alamat', 'city', 'Kota/Kabupaten', 'text', required: true),
            $this->field('alamat', 'province', 'Provinsi', 'text'),
            $this->field('alamat', 'postal_code', 'Kode Pos', 'text', validation: ['digits:5']),

            // ===== Sekolah asal =====
            $this->field('sekolah_asal', 'origin_school', 'Nama Sekolah Asal', 'text', required: true, validation: ['max:150']),
            $this->field('sekolah_asal', 'npsn', 'NPSN', 'text', validation: ['digits:8']),
            $this->field('sekolah_asal', 'graduation_year', 'Tahun Lulus', 'number', validation: ['numeric', 'between:1990,2100']),
            $this->field('sekolah_asal', 'diploma_number', 'Nomor Ijazah / SKL', 'text'),
            $this->field('sekolah_asal', 'average_score', 'Nilai Rata-rata Rapor', 'number', validation: ['numeric', 'between:0,100']),

            // ===== Kesehatan =====
            $this->field('kesehatan', 'blood_type', 'Golongan Darah', 'select', options: ['A', 'B', 'AB', 'O', 'Tidak tahu']),
            $this->field('kesehatan', 'height_cm', 'Tinggi Badan (cm)', 'number', validation: ['numeric', 'between:50,250']),
            $this->field('kesehatan', 'weight_kg', 'Berat Badan (kg)', 'number', validation: ['numeric', 'between:10,300']),
            $this->field('kesehatan', 'disease_history', 'Riwayat Penyakit', 'textarea'),
            $this->field('kesehatan', 'allergy', 'Alergi', 'textarea'),

            // ===== Tambahan =====
            $this->field('tambahan', 'kps_number', 'Nomor KPS / PKH', 'text', validation: ['max:20']),
            $this->field('tambahan', 'interests', 'Minat Ekstrakurikuler', 'multi_select', options: [
                'Pramuka', 'Futsal', 'Kaligrafi', 'Robotika', 'Memasak', 'Bulu Tangkis',
            ]),
            $this->field('tambahan', 'scholarship', 'Mengajukan Beasiswa', 'checkbox'),
            $this->field('tambahan', 'data_agreement', 'Pernyataan Kebenaran Data', 'checkbox', required: true, description: 'Centang untuk menyatakan seluruh data benar.'),
        ];

        $orders = [];

        foreach ($definitions as $definition) {
            $section = (string) $definition['section'];
            $orders[$section] = ($orders[$section] ?? 0) + 1;

            PpdbFormField::query()->firstOrCreate(
                ['period_id' => $period->id, 'field_key' => $definition['field_key']],
                $definition + ['order' => $orders[$section], 'active' => true],
            );
        }
    }

    /**
     * @param  list<string>|list<array{label?: string, value?: string}>|null  $options
     * @return array<string, mixed>
     */
    private function field(
        string $section,
        string $key,
        string $label,
        string $type,
        bool $required = false,
        ?array $options = null,
        array $validation = [],
        ?string $description = null,
    ): array {
        return [
            'section' => $section,
            'field_key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'description' => $description,
            'options' => $options === null ? null : $this->scopes->normalizeOptions($options),
            'validation' => $validation === [] ? null : $validation,
        ];
    }

    /**
     * Pertanyaan tambahan: global untuk periode, atau khusus program tertentu.
     *
     * @param  Collection<string, PpdbProgram>  $programs
     */
    private function questions(PpdbPeriod $period, Collection $programs): void
    {
        $definitions = [
            [
                'code' => 'TANYA_SUMBER',
                'question' => 'Dari mana Anda mengetahui sekolah ini?',
                'type' => 'single_choice',
                'options' => ['Media sosial', 'Internet', 'Teman atau keluarga', 'Spanduk/baliho', 'Undangan dari sekolah'],
            ],
            [
                'code' => 'TANYA_MOTIVASI',
                'question' => 'Apa motivasi calon peserta didik mendaftar di sekolah ini?',
                'type' => 'textarea',
            ],
            [
                'code' => 'TANYA_JARAK',
                'question' => 'Berapa jarak tempuh rumah ke sekolah?',
                'type' => 'dropdown',
                'options' => ['Kurang dari 2 km', '2 - 5 km', '5 - 10 km', 'Lebih dari 10 km'],
            ],
            [
                'code' => 'TAHFIDZ_ASAL',
                'question' => 'Sekolah atau pondok pesantren asal tempat menghafal?',
                'type' => 'text',
                'program' => 'SMP-TAH',
            ],
            [
                'code' => 'TAHFIDZ_JUZ',
                'question' => 'Berapa juz yang sudah dihafal saat ini?',
                'type' => 'single_choice',
                'required' => true,
                'program' => 'SMP-TAH',
                'options' => ['Belum ada', '1 - 5 juz', '6 - 10 juz', '11 - 15 juz', '16 - 20 juz', '21 - 25 juz', '26 - 30 juz'],
            ],
            [
                'code' => 'TAHFIDZ_TARGET',
                'question' => 'Target hafalan selama belajar di sini?',
                'type' => 'dropdown',
                'program' => 'SMP-TAH',
                'options' => ['5 juz', '10 juz', '20 juz', '30 juz'],
            ],
            [
                'code' => 'BOARDING_ASRAMA',
                'question' => 'Bersedia tinggal di asrama selama masa studi?',
                'type' => 'yes_no',
                'required' => true,
                'program' => 'SMP-BRD',
            ],
            [
                'code' => 'BOARDING_KEBUTUHAN',
                'question' => 'Kebutuhan khusus selama di asrama (kesehatan, alergi, pola makan)?',
                'type' => 'textarea',
                'program' => 'SMP-BRD',
            ],
        ];

        foreach ($definitions as $index => $definition) {
            $question = PpdbQuestion::query()->firstOrCreate(
                ['period_id' => $period->id, 'code' => $definition['code']],
                [
                    'program_id' => isset($definition['program']) ? $programs->get($definition['program'])?->id : null,
                    'question' => $definition['question'],
                    'type' => $definition['type'],
                    'required' => $definition['required'] ?? false,
                    'active' => true,
                    'order' => $index + 1,
                ],
            );

            foreach ($definition['options'] ?? [] as $order => $option) {
                PpdbQuestionOption::query()->firstOrCreate(
                    ['question_id' => $question->id, 'label' => $option],
                    ['value' => $option, 'order' => $order + 1],
                );
            }
        }
    }

    /**
     * Persyaratan dokumen: wajib semua program, plus sertifikat tahfidz yang
     * hanya muncul untuk program tahfidz.
     *
     * @param  Collection<string, PpdbProgram>  $programs
     */
    private function documentRequirements(PpdbPeriod $period, Collection $programs): void
    {
        $definitions = [
            ['code' => 'KK', 'name' => 'Kartu Keluarga', 'required' => true, 'types' => 'pdf,jpg,jpeg', 'min' => 100, 'max' => 2048, 'description' => 'Ukuran maksimal 2 MB, terbaca jelas.'],
            ['code' => 'AKTA', 'name' => 'Akta Kelahiran', 'required' => true, 'types' => 'pdf,jpg,jpeg', 'min' => 50, 'max' => 2048, 'description' => 'Salinan akta dari catatan sipil.'],
            ['code' => 'KTP_AYAH', 'name' => 'KTP Ayah', 'required' => true, 'types' => 'pdf,jpg,jpeg,png', 'min' => 50, 'max' => 1024, 'description' => null],
            ['code' => 'KTP_IBU', 'name' => 'KTP Ibu', 'required' => true, 'types' => 'pdf,jpg,jpeg,png', 'min' => 50, 'max' => 1024, 'description' => null],
            ['code' => 'RAPOR', 'name' => 'Rapor 5 Semester Terakhir', 'required' => true, 'types' => 'pdf', 'min' => 100, 'max' => 5120, 'description' => 'Gabungkan halaman rapor menjadi satu berkas PDF.'],
            ['code' => 'SERTIFIKAT_TAHFIDZ', 'name' => 'Sertifikat Tahfidz', 'required' => false, 'types' => 'pdf,jpg,jpeg', 'min' => 50, 'max' => 2048, 'description' => 'Dilampirkan hanya bila memiliki sertifikat hafalan.', 'program' => 'SMP-TAH'],
            ['code' => 'SURAT_SEHAT', 'name' => 'Surat Keterangan Sehat', 'required' => false, 'types' => 'pdf,jpg,jpeg', 'min' => 50, 'max' => 1024, 'description' => 'Opsional, membantu tim kesehatan sekolah.'],
        ];

        foreach ($definitions as $index => $definition) {
            PpdbDocumentRequirement::query()->firstOrCreate(
                ['period_id' => $period->id, 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'required' => $definition['required'],
                    'active' => true,
                    'order' => $index + 1,
                    'file_types' => $definition['types'],
                    'max_size_kb' => $definition['max'],
                    'min_size_kb' => $definition['min'],
                    'program_id' => $programs->get($definition['program'] ?? '')?->id,
                ],
            );
        }
    }

    /**
     * Biaya global + tambahan khusus gelombang 3 (biaya boleh berbeda per
     * gelombang, mengikuti `PpdbScopeService::fees()`).
     *
     * @param  Collection<string, PpdbWave>  $waves
     */
    private function fees(PpdbPeriod $period, Collection $waves): void
    {
        $definitions = [
            [
                'name' => 'Biaya Pendaftaran',
                'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
                'amount' => 150000,
                'wave_id' => null,
                'payment_deadline' => $period->registration_end?->toDateString(),
            ],
            [
                'name' => 'Biaya Pendaftaran Gelombang 3',
                'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
                'amount' => 100000,
                'wave_id' => $waves->get('GW3')?->id,
                'payment_deadline' => $waves->get('GW3')?->registration_end?->toDateString(),
            ],
            [
                'name' => 'Biaya Seleksi Kompetensi Tahfidz',
                'type' => PpdbRegistrationFee::TYPE_TEST,
                'amount' => 75000,
                'wave_id' => null,
                'payment_deadline' => $period->selection_announcement_date?->toDateString(),
            ],
        ];

        foreach ($definitions as $definition) {
            PpdbRegistrationFee::query()->firstOrCreate(
                [
                    'period_id' => $period->id,
                    'name' => $definition['name'],
                    'wave_id' => $definition['wave_id'],
                ],
                $definition + [
                    'required' => true,
                    'active' => true,
                ],
            );
        }
    }
}
