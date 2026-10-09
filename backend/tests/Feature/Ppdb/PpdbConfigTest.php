<?php

namespace Tests\Feature\Ppdb;

use App\Models\EducationLevel;
use App\Models\PpdbApplicant;
use App\Models\PpdbApplicantDocument;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbFormField;
use App\Models\PpdbInvoice;
use App\Models\PpdbInvoiceItem;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbQuestion;
use App\Models\PpdbQuestionOption;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationAnswer;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbWave;
use App\Models\Program;
use App\Models\User;
use App\Services\Ppdb\PpdbPeriodService;
use App\Services\Ppdb\PpdbScopeService;
use Database\Seeders\PpdbConfigSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Konfigurasi & form builder PPDB (iris A1).
 *
 * Satu test untuk satu aturan: periode (kode & slug otomatis, urutan tanggal,
 * transisi penerbitan, proteksi hapus), gelombang & program dalam periode,
 * form builder (termasuk meta sumber kebenaran & reorder), pertanyaan dengan
 * pilihan yang tersinkron, persyaratan dokumen, biaya dengan total per periode,
 * penolakan scope lintas periode, otorisasi permission, dan seeder konfigurasi
 * yang dibaca ulang lewat PpdbScopeService.
 */
class PpdbConfigTest extends TestCase
{
    use RefreshDatabase;

    /** Pengguna yang sedang berperan, untuk memeriksa kolom created_by. */
    private ?int $actingUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // =================================================================
    // PERIODE
    // =================================================================

    public function test_periode_dibuat_dengan_kode_dan_slug_otomatis(): void
    {
        $this->actingAsRole('admin-sekolah');

        $payload = [
            'name' => 'PPDB 2026/2027',
            'public_title' => 'Penerimaan Peserta Didik Baru 2026/2027',
            'registration_start' => '2026-01-05',
            'registration_end' => '2026-06-30',
            'selection_announcement_date' => '2026-07-10',
            'faq' => [['question' => 'Kapan pendaftaran dibuka?', 'answer' => 'Awal Januari.']],
        ];

        $created = $this->postJson('/api/v1/ppdb/periods', $payload)->assertCreated();

        // Kode & slug tidak dikirim — dibangkitkan dari tahun masuk & nama.
        $this->assertSame('PPDB-2026', $created->json('data.code'));
        $this->assertSame('ppdb-20262027', $created->json('data.slug'));
        $this->assertSame('draft', $created->json('data.status'));
        $this->assertSame(['scheduled', 'open', 'archived'], $created->json('data.allowed_transitions'));
        $this->assertSame(0, $created->json('data.waves_count'));
        $this->assertFalse($created->json('data.is_registration_open'));
        $this->assertSame(
            [['question' => 'Kapan pendaftaran dibuka?', 'answer' => 'Awal Januari.']],
            $created->json('data.faq'),
        );
        $this->assertDatabaseHas('ppdb_periods', [
            'id' => $created->json('data.id'),
            'created_by' => $this->actingUserId,
        ]);

        // Periode kedua dengan nama sama: kode & slug diberi akhiran, bukan ditolak.
        $second = $this->postJson('/api/v1/ppdb/periods', $payload)->assertCreated();

        $this->assertSame('PPDB-2026-2', $second->json('data.code'));
        $this->assertSame('ppdb-20262027-2', $second->json('data.slug'));

        // Slug yang dikirim eksplisit & sudah dipakai ditolak — alamat halaman
        // publik tidak pernah diganti diam-diam; hanya slug otomatis yang diberi akhiran.
        $this->postJson('/api/v1/ppdb/periods', $payload + ['slug' => 'ppdb-tahun-ini'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'ppdb-tahun-ini');
        $this->postJson('/api/v1/ppdb/periods', $payload + ['slug' => 'ppdb-tahun-ini'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_periode_memvalidasi_urutan_tanggal_dan_status_buka(): void
    {
        $this->actingAsRole('admin-sekolah');

        $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'Jadwal Kacau',
            'registration_start' => '2026-06-30',
            'registration_end' => '2026-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['registration_end']);

        $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'Daftar Ulang Kacau',
            're_registration_start' => '2026-08-10',
            're_registration_end' => '2026-08-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['re_registration_end']);

        // Periode baru tidak boleh langsung dibuka: form-nya masih kosong.
        $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'Langsung Dibuka',
            'status' => PpdbPeriod::STATUS_OPEN,
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);

        // Slug harus ramah URL.
        $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'Slug Salah',
            'slug' => 'PPDB Tahun Ini!',
        ])->assertStatus(422)->assertJsonValidationErrors(['slug']);
    }

    public function test_penerbitan_periode_mengikuti_transitions(): void
    {
        $this->actingAsRole('admin-sekolah');

        // archived bersifat final.
        $final = $this->period(['status' => PpdbPeriod::STATUS_ARCHIVED]);
        $this->postJson("/api/v1/ppdb/periods/{$final->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        // Draft tanpa program aktif tidak boleh dibuka.
        $draft = $this->period();
        $this->postJson("/api/v1/ppdb/periods/{$draft->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['programs']);

        $program = $this->program($draft, ['status' => PpdbProgram::STATUS_INACTIVE]);
        $this->postJson("/api/v1/ppdb/periods/{$draft->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['programs']);

        $program->update(['status' => PpdbProgram::STATUS_ACTIVE]);

        $published = $this->postJson("/api/v1/ppdb/periods/{$draft->id}/publish")->assertOk();
        $published->assertJsonPath('data.status', PpdbPeriod::STATUS_OPEN)
            ->assertJsonPath('message', 'Periode berhasil diterbitkan dan pendaftaran dibuka');

        // Setelah terbit hanya boleh ditutup; lewat PUT pun transisinya diuji.
        $this->assertSame(['closed'], $published->json('data.allowed_transitions'));
        $this->putJson("/api/v1/ppdb/periods/{$draft->id}", ['status' => PpdbPeriod::STATUS_ARCHIVED])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->putJson("/api/v1/ppdb/periods/{$draft->id}", ['status' => PpdbPeriod::STATUS_CLOSED])
            ->assertOk()
            ->assertJsonPath('data.status', PpdbPeriod::STATUS_CLOSED);

        // Index: filter status + jumlah gelombang/program/pendaftaran.
        $index = $this->getJson('/api/v1/ppdb/periods?status=open')->assertOk();
        $this->assertSame([], $index->json('data'));

        $all = $this->getJson('/api/v1/ppdb/periods?all=1&search='.urlencode($draft->name))->assertOk();
        $this->assertSame(0, $all->json('data.0.waves_count'));
        $this->assertSame(1, $all->json('data.0.programs_count'));
        $this->assertSame(0, $all->json('data.0.registrations_count'));
        $this->assertSame($draft->code, $all->json('data.0.code'));
    }

    public function test_periode_yang_masih_dipakai_pendaftaran_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $registration = $this->registration($period);

        $this->deleteJson("/api/v1/ppdb/periods/{$period->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period']);
        $this->assertFalse(PpdbPeriod::withTrashed()->find($period->id)->trashed());

        // Pendaftaran ditarik (soft delete) -> konfigurasi boleh dihapus.
        $registration->delete();

        $this->deleteJson("/api/v1/ppdb/periods/{$period->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Periode PPDB berhasil dihapus');

        $this->assertSoftDeleted('ppdb_periods', ['id' => $period->id]);
        $this->assertDatabaseMissing('ppdb_periods', ['id' => $period->id, 'deleted_at' => null]);

        // Baris terhapus lembut tetap menguasai kode: periode baru tidak menabraknya.
        $this->postJson('/api/v1/ppdb/periods', [
            'name' => 'PPDB 2026/2027 Pengganti',
            'registration_start' => '2026-01-05',
            'registration_end' => '2026-06-30',
        ])->assertCreated()->assertJsonPath('data.code', 'PPDB-2026-2');
    }

    // =================================================================
    // GELOMBANG & PROGRAM
    // =================================================================

    public function test_gelombang_crud_dengan_kode_unik_dalam_periode(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $other = $this->period();

        $created = $this->postJson('/api/v1/ppdb/waves', [
            'period_id' => $period->id,
            'code' => 'GW1',
            'name' => 'Gelombang 1',
            'registration_start' => '2026-01-05',
            'registration_end' => '2026-02-28',
            'quota' => 60,
            'priority' => 1,
        ])->assertCreated();

        $created->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.registrations_count', 0);
        $id = $created->json('data.id');

        // Kode sama dalam periode yang sama ditolak, di periode lain boleh.
        $this->postJson('/api/v1/ppdb/waves', ['period_id' => $period->id, 'code' => 'GW1', 'name' => 'Bentrok'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/ppdb/waves', ['period_id' => $other->id, 'code' => 'GW1', 'name' => 'Gelombang 1'])
            ->assertCreated();

        // Periode & tanggal gelombang wajib sah.
        $this->postJson('/api/v1/ppdb/waves', ['period_id' => 999999, 'code' => 'GX', 'name' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['period_id']);
        $this->postJson('/api/v1/ppdb/waves', [
            'period_id' => $period->id, 'code' => 'GX', 'name' => 'X',
            'registration_start' => '2026-05-01', 'registration_end' => '2026-04-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['registration_end']);

        $this->getJson("/api/v1/ppdb/waves?period_id={$period->id}&all=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'GW1');

        $this->putJson("/api/v1/ppdb/waves/{$id}", ['quota' => 45, 'priority' => 2])
            ->assertOk()
            ->assertJsonPath('data.quota', 45)
            ->assertJsonPath('data.priority', 2);

        // Gelombang yang sudah dipakai pendaftaran tidak dihapus (FK nullOnDelete).
        $wave = PpdbWave::find($id);
        $this->registration($period, ['wave_id' => $wave->id]);
        $this->deleteJson("/api/v1/ppdb/waves/{$id}")->assertStatus(422)->assertJsonValidationErrors(['wave']);
        $this->assertDatabaseHas('ppdb_waves', ['id' => $id]);

        // Setelah pendaftar pergi, gelombang boleh dihapus.
        PpdbRegistration::query()->forceDelete();
        $this->deleteJson("/api/v1/ppdb/waves/{$id}")->assertOk();
        $this->assertDatabaseMissing('ppdb_waves', ['id' => $id]);
    }

    /**
     * Penawaran program: menautkan master (tidak menyalin), unik kode per
     * periode, dan terlindungi dari penghapusan.
     */
    public function test_program_crud_dengan_tautan_master_dan_proteksi_hapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $level = EducationLevel::create([
            'name' => 'SMP Uji', 'jenjang' => 'SMP', 'level_number' => 2,
        ]);
        $master = Program::create(['code' => 'IPAUJ', 'name' => 'Ilmu Pengetahuan Alam Uji']);

        // Tautan master harus benar-benar ada.
        $this->postJson('/api/v1/ppdb/programs', [
            'period_id' => $period->id, 'code' => 'SMP-REG', 'name' => 'SMP Reguler',
            'program_id' => 999999, 'education_level_id' => 999999,
        ])->assertStatus(422)->assertJsonValidationErrors(['program_id', 'education_level_id']);

        $created = $this->postJson('/api/v1/ppdb/programs', [
            'period_id' => $period->id, 'code' => 'SMP-REG', 'name' => 'SMP Reguler',
            'program_id' => $master->id, 'education_level_id' => $level->id,
            'quota' => 60, 'sort_order' => 1,
        ])->assertCreated();

        $id = $created->json('data.id');
        $created->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.program.code', 'IPAUJ')
            ->assertJsonPath('data.education_level.jenjang', 'SMP');

        $this->postJson('/api/v1/ppdb/programs', ['period_id' => $period->id, 'code' => 'SMP-REG', 'name' => 'Bentrok'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->putJson("/api/v1/ppdb/programs/{$id}", ['quota' => 30, 'sort_order' => 5])
            ->assertOk()
            ->assertJsonPath('data.quota', 30)
            ->assertJsonPath('data.sort_order', 5);

        $this->getJson("/api/v1/ppdb/programs?period_id={$period->id}&status=active&all=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.sort_order', 5);

        $program = PpdbProgram::find($id);
        $this->registration($period, ['program_id' => $program->id]);
        $this->deleteJson("/api/v1/ppdb/programs/{$id}")->assertStatus(422)->assertJsonValidationErrors(['program']);

        PpdbRegistration::query()->forceDelete();
        $this->deleteJson("/api/v1/ppdb/programs/{$id}")->assertOk();
        $this->assertDatabaseMissing('ppdb_programs', ['id' => $id]);
    }

    // =================================================================
    // FORM BUILDER
    // =================================================================

    public function test_field_form_crud_dengan_meta_sumber_kebenaran(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();

        $created = $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id,
            'field_key' => 'asal_sekolah',
            'label' => 'Nama Sekolah Asal',
            'type' => 'select',
            'section' => 'sekolah_asal',
            'required' => true,
            'order' => 1,
            'options' => ['SMP 1', 'SMP 2'],
            'validation' => ['max:150'],
        ])->assertCreated();

        // Pilihan diseragamkan lewat PpdbScopeService meski dikirim sebagai teks.
        $this->assertSame(
            [['label' => 'SMP 1', 'value' => 'SMP 1'], ['label' => 'SMP 2', 'value' => 'SMP 2']],
            $created->json('data.options'),
        );
        $created->assertJsonPath('data.validation.0', 'max:150')
            ->assertJsonPath('data.section_label', 'Sekolah Asal')
            ->assertJsonPath('data.active', true);

        // Kunci field wajib snake_case & unik dalam periode.
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'AsalSekolah', 'label' => 'X', 'type' => 'text', 'section' => 'data_calon',
        ])->assertStatus(422)->assertJsonValidationErrors(['field_key']);

        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'asal_sekolah', 'label' => 'X', 'type' => 'text', 'section' => 'data_calon',
        ])->assertStatus(422)->assertJsonValidationErrors(['field_key']);

        // Tipe & section dikenali dari konstanta model, bukan tebakan frontend.
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'salah', 'label' => 'X', 'type' => 'slider', 'section' => 'data_calon',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'salah', 'label' => 'X', 'type' => 'text', 'section' => 'ruang_oud',
        ])->assertStatus(422)->assertJsonValidationErrors(['section']);

        $index = $this->getJson("/api/v1/ppdb/form-fields?period_id={$period->id}&all=1")->assertOk();
        $this->assertSame(PpdbFormField::SECTIONS, $index->json('meta.sections'));
        $this->assertSame(PpdbFormField::TYPES, $index->json('meta.types'));
        $this->assertSame('Sekolah Asal', $index->json('meta.section_labels.sekolah_asal'));
        $this->assertSame('asal_sekolah', $index->json('data.0.field_key'));

        $id = $created->json('data.id');
        $this->getJson("/api/v1/ppdb/form-fields/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        $this->putJson("/api/v1/ppdb/form-fields/{$id}", ['label' => 'Sekolah Asal Lama', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.label', 'Sekolah Asal Lama')
            ->assertJsonPath('data.active', false);

        $this->assertDatabaseHas('ppdb_form_fields', ['id' => $id]);
    }

    public function test_kunci_field_tidak_dapat_diubah_setelah_ada_jawaban(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $id = $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'nama_lengkap', 'label' => 'Nama Lengkap',
            'type' => 'text', 'section' => 'data_calon', 'required' => true,
        ])->assertCreated()->json('data.id');

        // Sebelum ada jawaban, kunci masih boleh dikoreksi.
        $this->putJson("/api/v1/ppdb/form-fields/{$id}", ['field_key' => 'nama_siswa'])
            ->assertOk()
            ->assertJsonPath('data.field_key', 'nama_siswa');

        $registration = $this->registration($period);
        PpdbRegistrationAnswer::create(['registration_id' => $registration->id, 'field_key' => 'nama_siswa', 'value' => 'Budi']);

        $this->putJson("/api/v1/ppdb/form-fields/{$id}", ['field_key' => 'nama_baru'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['field_key']);

        // Mengubah label tanpa menyentuh kunci tetap boleh.
        $this->putJson("/api/v1/ppdb/form-fields/{$id}", ['label' => 'Nama Lengkap Siswa'])
            ->assertOk()
            ->assertJsonPath('data.label', 'Nama Lengkap Siswa');
    }

    public function test_reorder_field_menyimpan_urutan_dan_menolak_lintas_periode(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $other = $this->period();

        $ids = [];

        foreach (['satu', 'dua', 'tiga'] as $order => $key) {
            $ids[$key] = $this->postJson('/api/v1/ppdb/form-fields', [
                'period_id' => $period->id, 'field_key' => $key, 'label' => strtoupper($key),
                'type' => 'text', 'section' => 'data_calon', 'order' => $order + 1,
            ])->assertCreated()->json('data.id');
        }

        $this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'period_id' => $period->id,
            'items' => [
                ['id' => $ids['tiga'], 'order' => 1],
                ['id' => $ids['satu'], 'order' => 2],
                ['id' => $ids['dua'], 'order' => 3],
            ],
        ])->assertOk()->assertJsonPath('data.0.field_key', 'tiga');

        $this->assertSame(
            ['tiga' => 1, 'satu' => 2, 'dua' => 3],
            PpdbFormField::query()->where('period_id', $period->id)->orderBy('order')->pluck('order', 'field_key')->all(),
        );

        // Bentuk singkat dari frontend: daftar id saja, urut sesuai posisinya.
        $this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'period_id' => $period->id,
            'field_ids' => [$ids['dua'], $ids['tiga'], $ids['satu']],
        ])->assertOk();

        $this->assertSame(
            ['dua' => 0, 'tiga' => 1, 'satu' => 2],
            PpdbFormField::query()->where('period_id', $period->id)->orderBy('order')->pluck('order', 'field_key')->all(),
        );

        $foreign = $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $other->id, 'field_key' => 'satu', 'label' => 'Lain Periode',
            'type' => 'text', 'section' => 'data_calon',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'items' => [
                ['id' => $ids['dua'], 'order' => 1],
                ['id' => $foreign, 'order' => 2],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);

        $this->postJson('/api/v1/ppdb/form-fields/reorder', [
            'period_id' => $period->id,
            'items' => [['id' => 999999, 'order' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.id']);

        $this->postJson('/api/v1/ppdb/form-fields/reorder', ['period_id' => $period->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    // =================================================================
    // PERTANYAAN
    // =================================================================

    public function test_pertanyaan_dan_pilihannya_tersinkron(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $program = $this->program($period);

        $created = $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $period->id,
            'program_id' => $program->id,
            'code' => 'TAHFIDZ_JUZ',
            'question' => 'Berapa juz yang sudah dihafal?',
            'type' => 'single_choice',
            'required' => true,
            'order' => 1,
            'options' => [
                ['label' => '1 - 5 juz'],
                ['label' => '6 - 10 juz', 'value' => '6_10'],
                ['label' => '11 - 15 juz'],
            ],
        ])->assertCreated();

        $id = $created->json('data.id');
        $created->assertJsonPath('data.scope', 'program')
            ->assertJsonPath('data.program.code', $program->code)
            ->assertJsonCount(3, 'data.options')
            ->assertJsonPath('data.options.1.value', '6_10')
            ->assertJsonPath('data.options.0.value', '1 - 5 juz');

        // Kirim ulang sebagian: satu diubah, satu baru, sisanya dihapus.
        $keep = $created->json('data.options.0.id');
        $this->putJson("/api/v1/ppdb/questions/{$id}", [
            'question' => 'Berapa juz yang hafal?',
            'options' => [
                ['id' => $keep, 'label' => '1 - 5 juz (review)'],
                ['label' => '21 - 25 juz'],
            ],
        ])->assertOk()->assertJsonCount(2, 'data.options');

        $this->assertSame(
            ['1 - 5 juz (review)', '21 - 25 juz'],
            PpdbQuestionOption::query()->where('question_id', $id)->orderBy('order')->pluck('label')->all(),
        );

        // Index ikut membawa pilihan (portal tidak menyusun ulang pertanyaan).
        $this->getJson("/api/v1/ppdb/questions?period_id={$period->id}&program_id={$program->id}&all=1")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonCount(2, 'data.0.options');

        // Kode unik dalam periode.
        $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $period->id, 'code' => 'TAHFIDZ_JUZ', 'question' => 'Bentrok', 'type' => 'text',
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);

        // Pilihan dikosongkan = hapus semua.
        $this->putJson("/api/v1/ppdb/questions/{$id}", ['options' => []])->assertOk()->assertJsonCount(0, 'data.options');
        $this->assertSame(0, PpdbQuestionOption::query()->where('question_id', $id)->count());

        $this->deleteJson("/api/v1/ppdb/questions/{$id}")->assertOk();
        $this->assertDatabaseMissing('ppdb_questions', ['id' => $id]);
    }

    public function test_pertanyaan_yang_sudah_dijawab_tidak_dapat_dihapus(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $id = $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $period->id, 'code' => 'SUMBER_INFOS', 'question' => 'Dari mana tahu sekolah ini?',
            'type' => 'text',
        ])->assertCreated()->json('data.id');

        $registration = $this->registration($period);
        PpdbRegistrationAnswer::create(['registration_id' => $registration->id, 'question_id' => $id, 'value' => 'Teman']);

        // ppdb_registration_answers.question_id cascadeOnDelete -> riwayat hilang.
        $this->deleteJson("/api/v1/ppdb/questions/{$id}")->assertStatus(422)->assertJsonValidationErrors(['question']);

        PpdbRegistrationAnswer::query()->where('question_id', $id)->delete();
        $this->deleteJson("/api/v1/ppdb/questions/{$id}")->assertOk();
    }

    // =================================================================
    // SCOPE LINTAS PERIODE
    // =================================================================

    public function test_scope_lintas_periode_ditolak(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $other = $this->period();
        $foreignProgram = $this->program($other);
        $foreignWave = $this->wave($other);

        $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $period->id, 'code' => 'LINTAS', 'question' => 'Lintas periode?', 'type' => 'text',
            'program_id' => $foreignProgram->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['program_id']);

        $this->postJson('/api/v1/ppdb/questions', [
            'period_id' => $period->id, 'code' => 'LINTAS', 'question' => 'Lintas periode?', 'type' => 'text',
            'wave_id' => $foreignWave->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['wave_id']);

        $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id, 'code' => 'LINTAS', 'name' => 'Lintas periode?',
            'program_id' => $foreignProgram->id, 'file_types' => 'pdf', 'max_size_kb' => 1024,
        ])->assertStatus(422)->assertJsonValidationErrors(['program_id']);

        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id, 'name' => 'Lintas periode?', 'type' => 'registration',
            'amount' => 50000, 'wave_id' => $foreignWave->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['wave_id']);

        // Satu-satunya gelombang yang ditolak adalah gelombang periode lain:
        // kombinasi program + gelombang periode yang sama tetap sah.
        $program = $this->program($period);
        $wave = $this->wave($period);

        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id, 'name' => 'Biaya Kombinasi', 'type' => 'selection',
            'amount' => 25000, 'program_id' => $program->id, 'wave_id' => $wave->id,
        ])->assertCreated()->assertJsonPath('data.scope', 'program_wave');
    }

    // =================================================================
    // PERSYARATAN DOKUMEN
    // =================================================================

    public function test_persyaratan_dokumen_memvalidasi_ekstensi_dan_ukuran(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $program = $this->program($period);

        $created = $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id,
            'program_id' => $program->id,
            'code' => 'SERTIFIKAT_TAHFIDZ',
            'name' => 'Sertifikat Tahfidz',
            'required' => false,
            'order' => 1,
            'file_types' => 'PDF, JPG , jpegx',
            'min_size_kb' => 50,
            'max_size_kb' => 2048,
        ])->assertCreated();

        $id = $created->json('data.id');
        $created->assertJsonPath('data.program_id', $program->id)
            ->assertJsonPath('data.scope', 'program')
            ->assertJsonPath('data.file_types', 'pdf,jpg,jpegx')
            ->assertJsonPath('data.allowed_extensions', ['pdf', 'jpg', 'jpegx'])
            ->assertJsonPath('data.documents_count', 0);

        // Ekstensi 2-5 karakter alfanumerik; ukuran masuk akal.
        $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id, 'code' => 'BURUK', 'name' => 'Berkas Salah', 'file_types' => 'pdf,j',
        ])->assertStatus(422)->assertJsonValidationErrors(['file_types']);

        $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id, 'code' => 'BURUK', 'name' => 'Berkas Salah', 'file_types' => 'pdf,file-with-dash',
        ])->assertStatus(422)->assertJsonValidationErrors(['file_types']);

        $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id, 'code' => 'BURUK', 'name' => 'Berkas Salah', 'max_size_kb' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_size_kb']);

        $this->postJson('/api/v1/ppdb/document-requirements', [
            'period_id' => $period->id, 'code' => 'BURUK', 'name' => 'Berkas Salah', 'min_size_kb' => 4096, 'max_size_kb' => 1024,
        ])->assertStatus(422)->assertJsonValidationErrors(['min_size_kb']);

        // Update: batas bawah tetap diuji terhadap batas atas yang tersimpan.
        $this->putJson("/api/v1/ppdb/document-requirements/{$id}", ['min_size_kb' => 3000])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['min_size_kb']);

        $this->putJson("/api/v1/ppdb/document-requirements/{$id}", ['max_size_kb' => 4096])
            ->assertOk()
            ->assertJsonPath('data.max_size_kb', 4096);

        // Berkas calon menautkan requirement_id (nullOnDelete) -> jangan hapus.
        $registration = $this->registration($period);
        PpdbApplicantDocument::create([
            'registration_id' => $registration->id,
            'applicant_id' => $registration->applicant_id,
            'requirement_id' => $id,
            'requirement_name' => 'Sertifikat Tahfidz',
            'file_path' => 'ppdb/2026/sertifikat.pdf',
            'original_filename' => 'sertifikat.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 102400,
        ]);

        $this->deleteJson("/api/v1/ppdb/document-requirements/{$id}")->assertStatus(422);
        $this->assertDatabaseHas('ppdb_document_requirements', ['id' => $id]);

        PpdbApplicantDocument::query()->delete();
        $this->deleteJson("/api/v1/ppdb/document-requirements/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Persyaratan dokumen berhasil dihapus');
        $this->assertDatabaseMissing('ppdb_document_requirements', ['id' => $id]);
    }

    // =================================================================
    // BIAYA
    // =================================================================

    public function test_biaya_crud_dengan_total_berjalan_di_meta(): void
    {
        $this->actingAsRole('admin-sekolah');

        $period = $this->period();
        $other = $this->period();
        $wave = $this->wave($period);

        $registrationFee = $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id,
            'name' => 'Biaya Pendaftaran',
            'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
            'amount' => 150000,
            'payment_deadline' => '2026-06-30',
        ])->assertCreated();

        $registrationFee->assertJsonPath('data.scope', 'global')
            ->assertJsonPath('data.payment_deadline', '2026-06-30');
        // Nominal utuh dikirim sebagai angka JSON (150000), jadi dibandingkan sebagai float.
        $this->assertSame(150000.0, (float) $registrationFee->json('data.amount'));

        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id,
            'wave_id' => $wave->id,
            'name' => 'Biaya Gelombang 3',
            'type' => PpdbRegistrationFee::TYPE_TEST,
            'amount' => 75000.50,
        ])->assertCreated();

        // Biaya non-aktif tidak ikut dihitung sebagai tarif berjalan.
        $inactive = $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id, 'name' => 'Biaya Lama', 'type' => 'other', 'amount' => 999999, 'active' => false,
        ])->assertCreated();

        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $other->id, 'name' => 'Biaya Periode Lain', 'type' => 'registration', 'amount' => 100000,
        ])->assertCreated();

        $list = $this->getJson("/api/v1/ppdb/fees?period_id={$period->id}&all=1")->assertOk();
        $this->assertSame(225000.5, $list->json('meta.total_amount'));
        $this->assertSame([$period->id => 225000.5], $list->json('meta.by_period'));
        $this->assertSame(
            ['registration' => 150000.0, 'test' => 75000.5],
            array_map('floatval', $list->json('meta.by_type')),
        );
        $this->assertCount(3, $list->json('data'));

        $this->assertSame(
            100000.0,
            (float) $this->getJson("/api/v1/ppdb/fees?period_id={$other->id}")->assertOk()->json('meta.total_amount'),
        );
        $this->assertSame(
            75000.5,
            $this->getJson("/api/v1/ppdb/fees?period_id={$period->id}&type=test")->assertOk()->json('meta.total_amount'),
        );

        // Nominal & jenis.
        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id, 'name' => 'Minus', 'type' => 'other', 'amount' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/ppdb/fees', [
            'period_id' => $period->id, 'name' => 'Jenis Salah', 'type' => 'donasi', 'amount' => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);

        $feeId = $registrationFee->json('data.id');
        $this->putJson("/api/v1/ppdb/fees/{$feeId}", ['amount' => 200000, 'required' => false])
            ->assertOk()
            ->assertJsonPath('data.required', false);
        $this->assertSame(200000.0, (float) PpdbRegistrationFee::query()->findOrFail($feeId)->amount);

        // Rincian tagihan menautkan fee_id -> biaya tertagih tidak dihapus.
        $invoice = $this->invoice($this->registration($period));
        PpdbInvoiceItem::create(['invoice_id' => $invoice->id, 'fee_id' => $feeId, 'name' => 'Biaya Pendaftaran', 'amount' => 200000]);

        $this->deleteJson("/api/v1/ppdb/fees/{$feeId}")->assertStatus(422)->assertJsonValidationErrors(['fee']);

        $this->deleteJson("/api/v1/ppdb/fees/{$inactive->json('data.id')}")->assertOk();
    }

    // =================================================================
    // OTORISASI
    // =================================================================

    public function test_konfigurasi_hanya_untuk_pemegang_permission_manage(): void
    {
        $period = $this->period();

        // Guru tidak punya permission modul PPDB sama sekali.
        $this->actingAsRole('guru');
        $this->postJson('/api/v1/ppdb/periods', ['name' => 'Tanpa Izin'])->assertForbidden();
        $this->getJson('/api/v1/ppdb/periods')->assertForbidden();

        // Bendahara hanya memegang ppdb.view + pembayaran.
        $this->actingAsRole('bendahara');
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'diam', 'label' => 'X', 'type' => 'text', 'section' => 'data_calon',
        ])->assertForbidden();

        // Panitia memegang *.manage tetapi bukan ppdb.delete untuk modul lain.
        $this->actingAsRole('panitia-ppdb');
        $this->postJson('/api/v1/ppdb/form-fields', [
            'period_id' => $period->id, 'field_key' => 'diisi_panitia', 'label' => 'Oleh Panitia',
            'type' => 'text', 'section' => 'data_calon',
        ])->assertCreated();
    }

    // =================================================================
    // SEEDER
    // =================================================================

    public function test_seeder_konfigurasi_dihasilkan_lewat_scope_service(): void
    {
        $this->seed(PpdbConfigSeeder::class);

        $period = PpdbPeriod::query()->firstWhere('code', 'PPDB-'.now()->format('Y'));
        $this->assertNotNull($period);
        // Periode dibuka memakai aturan transisi yang sama dengan API.
        $this->assertSame(PpdbPeriod::STATUS_OPEN, $period->status);
        $this->assertSame(3, PpdbWave::query()->where('period_id', $period->id)->count());
        $this->assertSame(4, PpdbProgram::query()->where('period_id', $period->id)->count());
        $this->assertSame(8, PpdbQuestion::query()->where('period_id', $period->id)->count());
        $this->assertSame(7, PpdbDocumentRequirement::query()->where('period_id', $period->id)->count());
        $this->assertSame(3, PpdbRegistrationFee::query()->where('period_id', $period->id)->count());
        $this->assertGreaterThanOrEqual(30, PpdbFormField::query()->where('period_id', $period->id)->count());

        $scopes = app(PpdbScopeService::class);
        $tahfidz = PpdbProgram::query()->firstWhere('code', 'SMP-TAH');
        $reguler = PpdbProgram::query()->firstWhere('code', 'SMP-REG');
        $boarding = PpdbProgram::query()->firstWhere('code', 'SMP-BRD');
        $wave3 = PpdbWave::query()->firstWhere('code', 'GW3');

        $schemaTahfidz = $scopes->schemaFor($period->id, $tahfidz->id, null);
        $schemaReguler = $scopes->schemaFor($period->id, $reguler->id, null);

        // Formulir dinamis: section, field, & pilihan datang dari data.
        $fields = collect($schemaTahfidz['fields']);
        $this->assertNotEmpty($fields);
        $this->assertContains('gender', $fields->pluck('key')->all());
        $this->assertSame('Data Calon', $fields->firstWhere('key', 'full_name')['section_label']);
        $this->assertSame(
            [['label' => 'Laki-laki', 'value' => 'L'], ['label' => 'Perempuan', 'value' => 'P']],
            $fields->firstWhere('key', 'gender')['options'],
        );

        // Dokumen & pertanyaan khusus program hanya muncul untuk programnya.
        $this->assertContains('SERTIFIKAT_TAHFIDZ', collect($schemaTahfidz['documents'])->pluck('code')->all());
        $this->assertNotContains('SERTIFIKAT_TAHFIDZ', collect($schemaReguler['documents'])->pluck('code')->all());
        $this->assertContains('KK', collect($schemaReguler['documents'])->pluck('code')->all());
        $this->assertContains('TAHFIDZ_JUZ', collect($schemaTahfidz['questions'])->pluck('code')->all());
        $this->assertNotContains('TAHFIDZ_JUZ', collect($schemaReguler['questions'])->pluck('code')->all());
        $this->assertContains(
            'BOARDING_ASRAMA',
            collect($scopes->questions($period->id, $boarding->id))->pluck('code')->all(),
        );
        $this->assertCount(7, collect($schemaTahfidz['questions'])->firstWhere('code', 'TAHFIDZ_JUZ')['options']);

        // Biaya: gelombang 3 memakai tarif tambahan.
        $this->assertSame(225000.0, $schemaReguler['fee_total']);
        $this->assertSame(
            325000.0,
            $scopes->schemaFor($period->id, $reguler->id, $wave3->id)['fee_total'],
        );

        // Idempoten: dijalankan ulang tidak menghasilkan baris ganda.
        $before = [
            'fields' => PpdbFormField::query()->count(),
            'questions' => PpdbQuestion::query()->count(),
            'options' => PpdbQuestionOption::query()->count(),
            'documents' => PpdbDocumentRequirement::query()->count(),
            'fees' => PpdbRegistrationFee::query()->count(),
            'periods' => PpdbPeriod::withTrashed()->count(),
        ];
        $this->seed(PpdbConfigSeeder::class);
        $this->assertSame($before, [
            'fields' => PpdbFormField::query()->count(),
            'questions' => PpdbQuestion::query()->count(),
            'options' => PpdbQuestionOption::query()->count(),
            'documents' => PpdbDocumentRequirement::query()->count(),
            'fees' => PpdbRegistrationFee::query()->count(),
            'periods' => PpdbPeriod::withTrashed()->count(),
        ]);
    }

    // =================================================================
    // HELPER
    // =================================================================

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        $this->actingUserId = $user->id;

        return $user;
    }

    /** Periode fixture lewat service yang sama dengan API. */
    private function period(array $overrides = []): PpdbPeriod
    {
        static $sequence = 0;
        $sequence++;

        return app(PpdbPeriodService::class)->create(array_merge([
            'name' => "Periode Uji {$sequence}",
            'registration_start' => '2026-01-05',
            'registration_end' => '2026-06-30',
        ], $overrides));
    }

    private function program(PpdbPeriod $period, array $overrides = []): PpdbProgram
    {
        static $sequence = 0;
        $sequence++;

        return PpdbProgram::create(array_merge([
            'period_id' => $period->id,
            'code' => 'PRG-'.strtoupper((string) $sequence),
            'name' => "Program Uji {$sequence}",
            'quota' => 30,
            'status' => PpdbProgram::STATUS_ACTIVE,
        ], $overrides));
    }

    private function wave(PpdbPeriod $period, array $overrides = []): PpdbWave
    {
        static $sequence = 0;
        $sequence++;

        return PpdbWave::create(array_merge([
            'period_id' => $period->id,
            'code' => 'GW-'.strtoupper((string) $sequence),
            'name' => "Gelombang Uji {$sequence}",
            'quota' => 40,
            'priority' => 1,
            'status' => PpdbWave::STATUS_ACTIVE,
        ], $overrides));
    }

    private function registration(PpdbPeriod $period, array $overrides = []): PpdbRegistration
    {
        static $sequence = 0;
        $sequence++;

        $account = PpdbParentAccount::create(['user_id' => User::factory()->create()->id]);
        $applicant = PpdbApplicant::create([
            'parent_account_id' => $account->id,
            'full_name' => "Calon Uji {$sequence}",
        ]);

        return PpdbRegistration::create(array_merge([
            'registration_number' => 'REG-TEST-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'period_id' => $period->id,
            'program_id' => $this->program($period)->id,
            'applicant_id' => $applicant->id,
            'parent_account_id' => $account->id,
        ], $overrides));
    }

    private function invoice(PpdbRegistration $registration): PpdbInvoice
    {
        static $sequence = 0;
        $sequence++;

        return PpdbInvoice::create([
            'registration_id' => $registration->id,
            'number' => 'INV-TEST-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'type' => 'registration',
            'amount' => 150000,
            'total_amount' => 150000,
        ]);
    }
}
