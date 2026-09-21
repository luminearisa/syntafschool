<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryLocation;
use App\Models\LibraryNumberingConfig;
use App\Models\LibrarySetting;
use App\Models\User;
use App\Services\Library\NumberingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Modul Library / Perpustakaan — Step 1 (Foundation).
 *
 * Satu test untuk satu aturan: alur status, kode yang tidak boleh berubah
 * sembarangan, konfigurasi key-value, hierarki lokasi, jam operasional
 * (termasuk beberapa interval), penutupan, penomoran, kelengkapan setup,
 * batas akses per peran, dan jejak audit.
 */
class LibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * Perpustakaan siap pakai (jam operasional + satu lokasi) agar dapat diaktifkan.
     */
    private function library(array $overrides = []): Library
    {
        $this->actingAsRole('admin-sekolah');

        $service = app(\App\Services\Library\LibraryService::class);

        // Dibuat lewat service agar konfigurasi & penomoran bawaan ikut dibuat —
        // sama seperti jalur API.
        $library = $service->create(array_merge([
            'code' => 'LIB-TEST',
            'name' => 'Perpustakaan Uji',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ], $overrides));

        $service->syncOperatingHours($library, [
            ['day_of_week' => 1, 'is_open' => true, 'open_time' => '08:00', 'close_time' => '16:00'],
        ]);

        LibraryLocation::create([
            'library_id' => $library->id,
            'code' => 'L1',
            'name' => 'Lantai 1',
            'type' => LibraryLocation::TYPE_FLOOR,
        ]);

        return $library;
    }

    // =================================================================
    // PERPUSTAKAAN
    // =================================================================

    public function test_perpustakaan_dapat_dibuat_beserta_konfigurasi_bawaan(): void
    {
        $this->actingAsRole('admin-sekolah');

        $response = $this->postJson('/api/v1/library/libraries', [
            'code' => 'LIB-SMA',
            'name' => 'Perpustakaan SMA',
            'timezone' => 'Asia/Jakarta',
        ])->assertCreated();

        $id = $response->json('data.id');

        $this->assertSame('draft', $response->json('data.status'));
        // Konfigurasi & penomoran bawaan langsung tersedia (idempoten).
        $this->assertSame(
            count(LibrarySetting::DEFAULTS),
            LibrarySetting::query()->where('library_id', $id)->count()
        );
        $this->assertSame(
            count(LibraryNumberingConfig::KEYS),
            LibraryNumberingConfig::query()->where('library_id', $id)->count()
        );
    }

    public function test_kode_perpustakaan_unik(): void
    {
        $this->library();

        $this->postJson('/api/v1/library/libraries', [
            'code' => 'LIB-TEST',
            'name' => 'Duplikat',
            'timezone' => 'Asia/Jakarta',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_kode_hanya_boleh_diubah_selama_draf(): void
    {
        $library = $this->library();

        // Masih draf → boleh.
        $this->putJson("/api/v1/library/libraries/{$library->id}", [
            'code' => 'LIB-TEST-2',
            'name' => $library->name,
            'timezone' => 'Asia/Jakarta',
        ])->assertOk();

        $library->refresh()->update(['status' => Library::STATUS_ACTIVE]);

        // Sudah aktif → kode terkunci.
        $this->putJson("/api/v1/library/libraries/{$library->id}", [
            'code' => 'LIB-TEST-3',
            'name' => $library->name,
            'timezone' => 'Asia/Jakarta',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_aktivasi_menuntut_konfigurasi_minimum(): void
    {
        $this->actingAsRole('admin-sekolah');

        $library = Library::create([
            'code' => 'LIB-KOSONG',
            'name' => 'Belum Dikonfigurasi',
            'timezone' => 'Asia/Jakarta',
        ]);

        // Tanpa jam operasional & lokasi → tidak boleh diaktifkan.
        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ACTIVE,
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(Library::STATUS_DRAFT, $library->fresh()->status);
    }

    public function test_perpindahan_status_mengikuti_peta_transisi(): void
    {
        $library = $this->library();

        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ACTIVE,
            'reason' => 'Siap digunakan',
        ])->assertOk();

        $this->assertSame(Library::STATUS_ACTIVE, $library->fresh()->status);

        // active → inactive → active
        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_INACTIVE,
        ])->assertOk();

        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ACTIVE,
        ])->assertOk();

        // active → archived (final)
        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ARCHIVED,
        ])->assertOk();

        // arsip tidak dapat diaktifkan kembali
        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ACTIVE,
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_status_tidak_dapat_diubah_ke_status_yang_sama(): void
    {
        $library = $this->library();

        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_DRAFT,
        ])->assertStatus(422)->assertJsonValidationErrors('status');
    }

    // =================================================================
    // OTORISASI
    // =================================================================

    public function test_peran_tanpa_permission_library_ditolak(): void
    {
        $library = $this->library();

        $this->actingAsRole('guru');

        $this->getJson('/api/v1/library/libraries')->assertForbidden();
        $this->getJson("/api/v1/library/libraries/{$library->id}")->assertForbidden();
    }

    public function test_library_manager_tidak_dapat_menghapus(): void
    {
        $library = $this->library();

        $this->actingAsRole('library-manager');

        // Boleh melihat & mengubah konfigurasi.
        $this->getJson("/api/v1/library/libraries/{$library->id}/settings")->assertOk();

        // Tidak boleh menghapus.
        $this->deleteJson("/api/v1/library/libraries/{$library->id}")->assertForbidden();
    }

    public function test_pustakawan_tidak_dapat_mengubah_konfigurasi_dan_penomoran(): void
    {
        $library = $this->library();

        $this->actingAsRole('pustakawan');

        $this->getJson("/api/v1/library/libraries/{$library->id}/settings")->assertForbidden();
        $this->getJson("/api/v1/library/libraries/{$library->id}/numbering")->assertForbidden();
    }

    // =================================================================
    // KONFIGURASI
    // =================================================================

    public function test_konfigurasi_dikelompokkan_dan_di_cast_sesuai_tipe(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson("/api/v1/library/libraries/{$library->id}/settings")->assertOk();

        $loan = $response->json('data.settings.loan');
        $this->assertIsArray($loan);

        $maxItems = collect($loan)->firstWhere('key', 'loan.max_items');
        $this->assertSame(3, $maxItems['value']);
        $this->assertSame('integer', $maxItems['type']);
    }

    public function test_konfigurasi_dapat_diperbarui_dan_menolak_kunci_tak_dikenal(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}/settings", [
            'settings' => ['catalog.rfid_enabled' => true, 'loan.max_items' => 5],
        ])->assertOk();

        $this->assertTrue(
            app(\App\Services\Library\LibrarySettingsService::class)->get($library, 'catalog.rfid_enabled')
        );

        $this->putJson("/api/v1/library/libraries/{$library->id}/settings", [
            'settings' => ['tidak_ada.key' => 'x'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.tidak_ada.key');

        // Tipe dihormati: flag boolean tidak menerima teks.
        $this->putJson("/api/v1/library/libraries/{$library->id}/settings", [
            'settings' => ['catalog.rfid_enabled' => 'bukan-boolean'],
        ])->assertStatus(422)->assertJsonValidationErrors('settings.catalog.rfid_enabled');
    }

    // =================================================================
    // LOKASI
    // =================================================================

    public function test_lokasi_hierarkis_dapat_dibuat(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $lantai = LibraryLocation::query()->where('library_id', $library->id)->first();

        $response = $this->postJson("/api/v1/library/libraries/{$library->id}/locations", [
            'parent_id' => $lantai->id,
            'code' => 'RB',
            'name' => 'Ruang Baca',
            'type' => LibraryLocation::TYPE_READING_AREA,
        ])->assertCreated();

        $this->assertSame($lantai->id, $response->json('data.parent_id'));

        // Pohon: satu akar berisi anak.
        $tree = $this->getJson("/api/v1/library/libraries/{$library->id}/locations?tree=1")->assertOk();
        $this->assertCount(1, $tree->json('data'));
        $this->assertCount(1, $tree->json('data.0.children'));
    }

    public function test_lokasi_tidak_boleh_pindah_ke_bawah_keturunannya(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $parent = LibraryLocation::query()->where('library_id', $library->id)->first();

        $child = LibraryLocation::create([
            'library_id' => $library->id,
            'parent_id' => $parent->id,
            'code' => 'RB',
            'name' => 'Ruang Baca',
            'type' => LibraryLocation::TYPE_READING_AREA,
        ]);

        $this->postJson("/api/v1/library/libraries/{$library->id}/locations/{$parent->id}/move", [
            'parent_id' => $child->id,
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_lokasi_dengan_anak_tidak_dapat_dihapus(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $parent = LibraryLocation::query()->where('library_id', $library->id)->first();

        LibraryLocation::create([
            'library_id' => $library->id,
            'parent_id' => $parent->id,
            'code' => 'RB',
            'name' => 'Ruang Baca',
            'type' => LibraryLocation::TYPE_READING_AREA,
        ]);

        $this->deleteJson("/api/v1/library/libraries/{$library->id}/locations/{$parent->id}")
            ->assertStatus(422);
    }

    public function test_lokasi_dari_perpustakaan_lain_tidak_dapat_diakses(): void
    {
        $libraryA = $this->library();

        $this->actingAsRole('admin-sekolah');

        $locationA = LibraryLocation::query()->where('library_id', $libraryA->id)->first();

        $libraryB = Library::create([
            'code' => 'LIB-B',
            'name' => 'Perpustakaan B',
            'timezone' => 'Asia/Jakarta',
        ]);

        // ID enumeration: lokasi milik A tidak dapat diakses lewat URL B.
        $this->putJson("/api/v1/library/libraries/{$libraryB->id}/locations/{$locationA->id}", [
            'code' => 'X',
            'name' => 'X',
            'type' => LibraryLocation::TYPE_ROOM,
        ])->assertNotFound();
    }

    // =================================================================
    // JAM OPERASIONAL
    // =================================================================

    public function test_jam_operasional_beberapa_interval_dalam_sehari(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}/operating-hours", [
            'hours' => [
                ['day_of_week' => 1, 'is_open' => true, 'open_time' => '08:00', 'close_time' => '12:00'],
                ['day_of_week' => 1, 'is_open' => true, 'open_time' => '13:00', 'close_time' => '16:00'],
                ['day_of_week' => 0, 'is_open' => false],
            ],
        ])->assertOk();

        // Bulk replace: baris lama (Senin 08:00–16:00) tergantikan.
        $this->assertSame(
            3,
            \App\Models\LibraryOperatingHour::query()->where('library_id', $library->id)->count()
        );

        $closed = \App\Models\LibraryOperatingHour::query()
            ->where('library_id', $library->id)
            ->where('day_of_week', 0)
            ->first();

        $this->assertFalse($closed->is_open);
        $this->assertNull($closed->open_time);
    }

    public function test_interval_jam_yang_tumpang_tindih_ditolak(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}/operating-hours", [
            'hours' => [
                ['day_of_week' => 1, 'is_open' => true, 'open_time' => '08:00', 'close_time' => '12:00'],
                ['day_of_week' => 1, 'is_open' => true, 'open_time' => '11:00', 'close_time' => '14:00'],
            ],
        ])->assertStatus(422);
    }

    public function test_jam_tutup_harus_setelah_jam_buka(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}/operating-hours", [
            'hours' => [
                ['day_of_week' => 1, 'is_open' => true, 'open_time' => '16:00', 'close_time' => '08:00'],
            ],
        ])->assertStatus(422);
    }

    // =================================================================
    // PENUTUPAN
    // =================================================================

    public function test_penutupan_dapat_dibuat_dan_divalidasi(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->postJson("/api/v1/library/libraries/{$library->id}/closures", [
            'date' => '2026-12-25',
            'reason' => 'Libur Natal',
            'type' => 'national_holiday',
        ])->assertCreated();

        // Jam selesai mendahului jam mulai → ditolak.
        $this->postJson("/api/v1/library/libraries/{$library->id}/closures", [
            'date' => '2026-12-26',
            'start_time' => '12:00',
            'end_time' => '09:00',
            'reason' => 'Maintenance',
            'type' => 'maintenance',
        ])->assertStatus(422)->assertJsonValidationErrors('end_time');
    }

    // =================================================================
    // PENOMORAN
    // =================================================================

    public function test_penomoran_menghasilkan_nomor_berurutan_dan_berpadding(): void
    {
        $library = $this->library();

        $service = app(NumberingService::class);

        $first = $service->next($library, 'member');
        $second = $service->next($library, 'member');

        $this->assertSame('LIB-MBR-'.date('Y').'-001', $first);
        $this->assertSame('LIB-MBR-'.date('Y').'-002', $second);
    }

    public function test_penomoran_reset_tahunan(): void
    {
        $library = $this->library();

        $config = LibraryNumberingConfig::query()
            ->where('library_id', $library->id)
            ->where('key', 'member')
            ->first();

        $config->update([
            'reset_period' => LibraryNumberingConfig::RESET_YEAR,
            'last_number' => 9,
            'last_reset_at' => now()->subYear(),
        ]);

        $number = app(NumberingService::class)->next($library, 'member');

        $this->assertSame('LIB-MBR-'.date('Y').'-001', $number);
    }

    public function test_format_penomoran_wajib_memuat_seq_dan_token_dikenal(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}/numbering", [
            'configs' => [[
                'key' => 'member',
                'format' => 'LIB-MBR-{TAHUN}',
                'padding' => 3,
                'reset_period' => 'never',
                'start_number' => 1,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('configs.0.format');
    }

    public function test_konfigurasi_penomoran_dapat_diperbarui(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $response = $this->putJson("/api/v1/library/libraries/{$library->id}/numbering", [
            'configs' => [[
                'key' => 'item',
                'format' => 'LIB-{LIBRARY_CODE}-{YY}-{SEQ}',
                'prefix' => 'X',
                'padding' => 4,
                'reset_period' => 'month',
                'start_number' => 1,
            ]],
        ])->assertOk();

        $items = collect($response->json('data'))->firstWhere('key', 'item');
        $this->assertSame('X', $items['prefix']);
        $this->assertSame(4, $items['padding']);
        $this->assertStringStartsWith('XLIB-LIB-TEST-', $items['preview']);
    }

    // =================================================================
    // SETUP, DASHBOARD, DELETE
    // =================================================================

    public function test_kelengkapan_setup_dihitung(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson("/api/v1/library/libraries/{$library->id}/setup-status")->assertOk();

        // Informasi dasar, jam operasional, dan lokasi lengkap; penomoran sudah
        // terisi bawaan sehingga tidak ada peringatan.
        $this->assertSame('complete', $response->json('data.status'));
        $this->assertSame(100, $response->json('data.percent'));
    }

    public function test_kelengkapan_setup_menandai_yang_belum_lengkap(): void
    {
        $this->actingAsRole('admin-sekolah');

        $library = Library::create([
            'code' => 'LIB-BARU',
            'name' => 'Baru',
            'timezone' => 'Asia/Jakarta',
        ]);

        $response = $this->getJson("/api/v1/library/libraries/{$library->id}/setup-status")->assertOk();

        $this->assertSame('incomplete', $response->json('data.status'));
        $this->assertLessThan(100, $response->json('data.percent'));
    }

    public function test_dashboard_tidak_menampilkan_statistik_palsu(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson("/api/v1/library/libraries/{$library->id}/dashboard")->assertOk();

        // Fase 3 & 4: seluruh angka punya sumber data (integer, bukan null).
        $this->assertIsInt($response->json('data.statistics.books'));
        $this->assertIsInt($response->json('data.statistics.items'));
        $this->assertIsInt($response->json('data.statistics.members'));
        $this->assertIsInt($response->json('data.statistics.active_loans'));
        $this->assertIsInt($response->json('data.statistics.overdue_loans'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.location.count'));
    }

    public function test_perpustakaan_tanpa_transaksi_dapat_dihapus(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->deleteJson("/api/v1/library/libraries/{$library->id}")->assertOk();

        // Soft delete: data historis tetap tersedia.
        $this->assertSoftDeleted('libraries', ['id' => $library->id]);
    }

    // =================================================================
    // AUDIT
    // =================================================================

    public function test_perubahan_penting_tercatat_di_jejak_audit(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}", [
            'code' => $library->code,
            'name' => 'Nama Baru',
            'timezone' => 'Asia/Jakarta',
        ])->assertOk();

        $this->postJson("/api/v1/library/libraries/{$library->id}/transition", [
            'status' => Library::STATUS_ACTIVE,
        ])->assertOk();

        $events = Activity::query()->where('log_name', 'library')->pluck('event')->all();

        $this->assertContains('LIBRARY_CREATED', $events);
        $this->assertContains('LIBRARY_UPDATED', $events);
        $this->assertContains('LIBRARY_STATUS_CHANGED', $events);
        $this->assertContains('LIBRARY_OPERATING_HOURS_UPDATED', $events);
        $this->assertContains('LIBRARY_LOCATION_CREATED', $events);
    }

    public function test_jejak_audit_menyimpan_nilai_lama_dan_baru(): void
    {
        $library = $this->library();

        $this->actingAsRole('admin-sekolah');

        $this->putJson("/api/v1/library/libraries/{$library->id}", [
            'code' => $library->code,
            'name' => 'Nama Sesudah',
            'timezone' => 'Asia/Jakarta',
        ])->assertOk();

        $activity = Activity::query()
            ->where('log_name', 'library')
            ->where('event', 'LIBRARY_UPDATED')
            ->latest('id')
            ->first();

        $changes = $activity->attribute_changes;

        $this->assertSame('Nama Sesudah', $changes['attributes']['name']);
        $this->assertSame($library->name, $changes['old']['name']);
    }

    public function test_jejak_audit_dapat_dibaca_lewat_api(): void
    {
        $this->library();

        $this->actingAsRole('admin-sekolah');

        $response = $this->getJson('/api/v1/library/audit-logs')->assertOk();

        $this->assertGreaterThan(0, count($response->json('data')));
    }
}