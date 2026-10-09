<?php

namespace Tests\Feature\Ppdb;

use App\Models\Guardian;
use App\Models\PpdbApplicant;
use App\Models\PpdbDocumentRequirement;
use App\Models\PpdbParentAccount;
use App\Models\PpdbPeriod;
use App\Models\PpdbProgram;
use App\Models\PpdbRegistration;
use App\Models\PpdbRegistrationFee;
use App\Models\PpdbSelectionStage;
use App\Models\PpdbWave;
use App\Models\User;
use App\Notifications\PpdbActivityNotification;
use App\Services\Ppdb\PpdbParentAccountService;
use App\Services\Ppdb\PpdbPortalAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Halaman publik PPDB, pendaftaran akun orang tua mandiri, pengelolaan akun oleh
 * panitia, dan kotak masuk notifikasi portal (irisan publik + akun + notifikasi).
 *
 * Yang dijaga di sini:
 * 1. Halaman publik jalan TANPA login dan tidak pernah mengeluarkan data
 *    pribadi. Pemeriksaannya dua lapis: kunci yang haram muncul (dipindai
 *    rekursif) dan nilai fixture rahasia yang haram ada di seluruh body respons.
 * 2. Gerbang akun portal (`PpdbPortalAccess`): akun pending/terblokir boleh
 *    membaca kotak masuknya, tetapi tidak boleh melakukan aksi tulis.
 * 3. "1 orang tua → N anak" terlihat oleh sekolah di daftar akun admin.
 */
class PpdbPublicPortalTest extends TestCase
{
    use RefreshDatabase;

    /** Kunci data pribadi yang tidak boleh muncul di respons publik mana pun. */
    private const PRIVATE_KEYS = [
        'applicant', 'applicant_id', 'applicant_name', 'applicants',
        'guardian', 'guardian_id', 'full_name', 'nik', 'nisn',
        'notifiable_id', 'notifiable_type',
        'parent', 'parent_account', 'parent_account_id', 'parent_name',
        'password', 'temporary_password',
        'registration', 'registration_id', 'registration_number', 'registrations',
        'student_id', 'user_id',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    // =================================================================
    // HALAMAN PUBLIK
    // =================================================================

    public function test_daftar_periode_publik_tanpa_login_dan_mengabaikan_draft(): void
    {
        $period = $this->period();
        $program = $this->program($period);
        $this->wave($period);
        $draft = $this->period(['status' => PpdbPeriod::STATUS_DRAFT, 'name' => 'Rahasia Draft']);
        $archived = $this->period(['status' => PpdbPeriod::STATUS_ARCHIVED, 'name' => 'Arsip Lama']);

        $secret = $this->application($period, $program);

        // Permintaan tanpa header Authorization sama sekali.
        $index = $this->getJson('/api/v1/ppdb/public')->assertOk();
        $this->assertGuest();

        $ids = array_column((array) $index->json('data'), 'id');
        $this->assertSame([$period->id], $ids, 'Hanya periode yang boleh dilihat publik (open/closed) yang tampil.');
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($archived->id, $ids);

        $index->assertJsonPath('data.0.slug', $period->slug)
            ->assertJsonPath('data.0.public_title', $period->public_title)
            ->assertJsonPath('data.0.status', PpdbPeriod::STATUS_OPEN)
            // Agregat kuota: berapa orang, bukan siapa.
            ->assertJsonPath('data.0.quota.total_quota', 30)
            ->assertJsonPath('data.0.quota.registered', 1)
            ->assertJsonPath('data.0.quota.remaining', 29)
            ->assertJsonPath('data.0.programs.0.registered', 1)
            ->assertJsonPath('data.0.waves.0.name', $period->waves()->first()->name);

        // Filter daftar publik tetap berada di dalam gerbang yang sama.
        $this->getJson('/api/v1/ppdb/public?only_open=1&status=open')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/ppdb/public?status=draft')->assertStatus(422);

        $this->assertNoPrivateData($index->getContent(), $this->secretValues($secret));
    }

    public function test_halaman_publik_membawa_penawaran_biaya_syarat_dan_jadwal(): void
    {
        $period = $this->period(['contact_phone' => '021-5550001', 'contact_email' => 'panitia-alamat@sekolah.test']);
        $program = $this->program($period);
        $wave = $this->wave($period);
        $fee = $this->fee($period);
        $requirement = $this->requirement($period);
        $stage = $this->stage($period);

        $secret = $this->application($period, $program, $wave);

        $show = $this->getJson('/api/v1/ppdb/public/'.$period->slug)->assertOk();
        $this->assertGuest();

        $show->assertJsonPath('data.public_title', $period->public_title)
            ->assertJsonPath('data.public_description', $period->public_description)
            ->assertJsonPath('data.status', PpdbPeriod::STATUS_OPEN)
            ->assertJsonPath('data.registration_open', true)
            ->assertJsonPath('data.registration_start', now()->subDay()->toDateString())
            // Kontak yang boleh tampil hanya kontak resmi periode.
            ->assertJsonPath('data.contact.name', 'Sekretariat PPDB')
            ->assertJsonPath('data.contact.phone', '021-5550001')
            ->assertJsonPath('data.contact.email', 'panitia-alamat@sekolah.test')
            ->assertJsonPath('data.programs.0.name', $program->name)
            ->assertJsonPath('data.programs.0.quota', 30)
            ->assertJsonPath('data.programs.0.remaining', 29)
            ->assertJsonPath('data.programs.0.is_full', false)
            ->assertJsonPath('data.waves.0.name', $wave->name)
            ->assertJsonPath('data.fees.0.name', $fee->name)
            ->assertJsonPath('data.documents.0.name', $requirement->name)
            ->assertJsonPath('data.documents.0.allowed_extensions.0', 'pdf')
            ->assertJsonPath('data.selection_stages.0.name', 'Tes Akademik Uji')
            ->assertJsonPath('data.selection_stages.0.type_label', 'Tes akademik')
            ->assertJsonPath('data.faq.0.question', 'Kapan pendaftaran dibuka?');

        // Tarif & syarat ikut menetas ke tiap program (harga publik, bukan tagihan orang).
        $this->assertSame($requirement->name, $show->json('data.programs.0.documents.0.name'));
        $this->assertSame($fee->name, $show->json('data.programs.0.fees.0.name'));
        $this->assertSame(150000.0, (float) $show->json('data.fee_total'));
        $this->assertSame(150000.0, (float) $show->json('data.programs.0.fee_total'));
        $this->assertSame(1, $show->json('data.quota.registered'));
        $this->assertNotEmpty($show->json('data.deadlines'));

        $this->assertNoPrivateData($show->getContent(), $this->secretValues($secret));
    }

    public function test_periode_draft_scheduled_dan_slug_asing_balas_404(): void
    {
        $draft = $this->period(['status' => PpdbPeriod::STATUS_DRAFT]);
        $scheduled = $this->period(['status' => PpdbPeriod::STATUS_SCHEDULED]);

        $this->getJson('/api/v1/ppdb/public/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/v1/ppdb/public/'.$scheduled->slug)->assertNotFound();
        $miss = $this->getJson('/api/v1/ppdb/public/tidak-ada-slugnya')->assertNotFound();

        // 404 publik tidak boleh membocorkan kelas model internal ke klien.
        $miss->assertJsonMissingPath('data');
        $this->assertStringNotContainsStringIgnoringCase('App\\Models', (string) $miss->json('message'));
        $this->assertStringNotContainsStringIgnoringCase('query results', (string) $miss->json('message'));
        $this->assertStringNotContainsStringIgnoringCase(
            'App\\Models',
            (string) $this->getJson('/api/v1/ppdb/bukan-endpoint-modul-ini')->json('message'),
        );

        // Periode closed tetap terbaca publik (sejarah singkat) tanpa data pribadi.
        $closed = $this->period(['status' => PpdbPeriod::STATUS_CLOSED]);
        $this->getJson('/api/v1/ppdb/public/'.$closed->slug)
            ->assertOk()
            ->assertJsonPath('data.status', PpdbPeriod::STATUS_CLOSED)
            ->assertJsonPath('data.registration_open', false)
            ->assertJsonPath('data.quota.registered', 0);
    }

    // =================================================================
    // PENDAFTARAN AKUN MANDIRI
    // =================================================================

    /**
     * Jembatan browser ↔ API. Portal orang tua pernah mati total: setiap POST dari
     * SPA dibalas 419 "CSRF token mismatch" sementara seluruh suite tetap hijau,
     * karena (a) tes lain tidak mengirim header `Origin` sehingga mode stateful
     * Sanctum tidak pernah tersentuh, dan (b) middleware CSRF melewat
     * pemeriksaannya saat berjalan di bawah PHPUnit.
     *
     * Di sini env dipindah sebentar ke 'production' dan Origin domain stateful
     * dikirim, jadi jalur browser yang sungguhan ikut teruji.
     */
    public function test_mutasi_dari_origin_spa_stateful_tidak_ditolak_csrf(): void
    {
        $app = $this->app;
        $environment = (string) $app['env'];
        $app['env'] = 'production';

        try {
            $response = $this->postJson('/api/v1/ppdb/auth/register', [
                'name' => 'Wali Dari Browser',
                'email' => 'wali.dari.browser@ppdb-uji.test',
                'phone' => '0812-3456-7891',
                'password' => 'rahasia123',
                'password_confirmation' => 'rahasia123',
                'address' => 'Jl. Kenangan No. 2, Bandung',
            ], [
                'Origin' => 'http://localhost:5173',
                'Referer' => 'http://localhost:5173/ppdb/register',
            ]);
        } finally {
            $app['env'] = $environment;
        }

        $this->assertNotSame(
            419,
            $response->getStatusCode(),
            'Permintaan dari domain stateful tidak boleh menuntut token CSRF: aplikasi ini berbicara ke API dengan Bearer token, bukan sesi cookie.',
        );
        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'token_type']]);

        // Token dari permintaan "browser" itu benar-benar dipakai ke portal.
        $this->getJson('/api/v1/ppdb/portal/profile', [
            'Origin' => 'http://localhost:5173',
            'Authorization' => 'Bearer '.$response->json('data.token'),
        ])->assertOk()->assertJsonPath('data.user.email', 'wali.dari.browser@ppdb-uji.test');
    }

    public function test_pendaftaran_mandiri_membuat_user_guardian_dan_satu_akun(): void
    {
        $payload = [
            'name' => 'Wali Baru Satu',
            'email' => 'wali.baru1@ppdb-uji.test',
            'phone' => '0812-3456-7890',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
            'address' => 'Jl. Kenangan No. 1, Bandung',
        ];

        $created = $this->postJson('/api/v1/ppdb/auth/register', $payload)->assertCreated();

        $user = User::query()->firstWhere('email', $payload['email']);
        $this->assertNotNull($user, 'Identitas orang tua dibuat sebagai users existing.');

        // SATU akun untuk semua anak — bukan akun per anak.
        $accounts = PpdbParentAccount::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $accounts);
        $account = $accounts->first();
        $this->assertNotNull($account->guardian_id);
        $this->assertDatabaseHas('guardians', [
            'id' => $account->guardian_id,
            'full_name' => $payload['name'],
            'phone' => $payload['phone'],
            'email' => $payload['email'],
            'address' => $payload['address'],
        ]);

        // Status mengikuti aturan `PpdbParentAccountService::register()` (dok
        // block-nya menyatakan pending_verification, kodenya masih menyetel
        // active — lihat NEEDS_DECISION di laporan). Yang diuji di sini adalah
        // invariannya: status resmi, `verified_at` hanya untuk akun aktif, dan
        // gerbang transaksi konsisten dengan status.
        $status = (string) $account->account_status;
        $this->assertContains($status, PpdbParentAccount::STATUSES);
        $this->assertDatabaseHas('ppdb_parent_accounts', [
            'id' => $account->id,
            'account_status' => $status,
        ]);
        $this->assertSame($status === PpdbParentAccount::STATUS_ACTIVE, $account->verified_at !== null);
        $this->assertSame($status === PpdbParentAccount::STATUS_ACTIVE, $account->canTransact());

        $label = collect(PpdbParentAccountService::statusOptions())
            ->firstWhere('value', $status)['label'] ?? $status;

        $created->assertJsonPath('data.user.email', $payload['email'])
            ->assertJsonPath('data.parent_account.parent_account_id', $account->id)
            ->assertJsonPath('data.parent_account.account_status', $status)
            ->assertJsonPath('data.parent_account.applicant_count', 0)
            ->assertJsonPath('data.children_count', 0)
            ->assertJsonPath('data.one_account_for_all_children', true)
            ->assertJsonPath('data.can_transact', $status === PpdbParentAccount::STATUS_ACTIVE)
            ->assertJsonPath('data.account_status_label', $label);
        $this->assertStringContainsString('semua anak', (string) $created->json('data.account_note'));
        $this->assertNotEmpty($created->json('data.next_steps'));

        // Token dibuat sama seperti AuthController@login dan langsung dipakai portal.
        $token = (string) $created->json('data.token');
        $this->assertNotSame('', $token);
        $this->getJson('/api/v1/ppdb/portal/notifications', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('meta.unread_count', 1);

        // Kabar akun dibuat lewat service notifikasi existing (kanal database).
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
            'type' => PpdbActivityNotification::class,
            'data->type' => PpdbActivityNotification::TYPE_ACCOUNT_CREATED,
        ]);
    }

    public function test_pendaftaran_mandiri_menolak_email_ganda_kredensial_lemah(): void
    {
        $payload = [
            'name' => 'Wali Baru Dua',
            'email' => 'wali.baru2@ppdb-uji.test',
            'phone' => '0812-3456-7890',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ];

        $this->postJson('/api/v1/ppdb/auth/register', $payload)->assertCreated();

        // Email sudah dipakai (unique:users,email) dan akun tidak bertambah.
        $this->postJson('/api/v1/ppdb/auth/register', array_merge($payload, ['name' => 'Wali Lain']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
        $this->assertSame(1, PpdbParentAccount::query()->count());

        // Konfirmasi tidak sama, password terlalu pendek, format email & telepon.
        $this->postJson('/api/v1/ppdb/auth/register', array_merge($payload, [
            'email' => 'wali.baru3@ppdb-uji.test', 'password_confirmation' => 'lainnya123',
        ]))->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/ppdb/auth/register', array_merge($payload, [
            'email' => 'wali.baru4@ppdb-uji.test', 'password' => '123', 'password_confirmation' => '123',
        ]))->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->postJson('/api/v1/ppdb/auth/register', array_merge($payload, [
            'email' => 'bukan-email', 'phone' => 'abc-12345',
        ]))->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);
    }

    public function test_pendaftaran_mandiri_di_throttle_10_per_menit(): void
    {
        $payload = [
            'name' => 'Wali Beruntun',
            'email' => 'wali.beruntun@ppdb-uji.test',
            'phone' => '0812-9999-8888',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ];

        $first = $this->postJson('/api/v1/ppdb/auth/register', $payload)->assertCreated();
        $first->assertHeader('X-RateLimit-Limit', 10);

        $last = null;

        // Permintaan ke-2..11 (payload sama → 422 validasi) tetap menghabiskan kuota.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $last = $this->postJson('/api/v1/ppdb/auth/register', $payload);
        }

        $this->assertContains($last?->getStatusCode(), [403, 429], 'Throttle harus menghentikan percobaan berlebihan.');
        $this->assertSame(429, $last?->getStatusCode());
        $this->assertSame(1, PpdbParentAccount::query()->count());
    }

    public function test_akun_pending_boleh_membaca_tetapi_tidak_dapat_bertransaksi(): void
    {
        $account = $this->account(PpdbParentAccount::STATUS_PENDING);
        $this->assertFalse($account->canTransact());

        Sanctum::actingAs($account->user, ['*']);

        $this->getJson('/api/v1/ppdb/portal/notifications')->assertOk();
        $this->getJson('/api/v1/ppdb/portal/notifications/unread-count')->assertOk();

        // Aksi tulis pada irisan ini: 403 dengan pesan yang menjelaskan.
        $denied = $this->postJson('/api/v1/ppdb/portal/notifications/read-all')->assertForbidden();
        $this->assertStringContainsString('belum aktif', (string) $denied->json('message'));

        // Endpoint tulis portal pada irisan lain memakai gerbang yang sama:
        // requireAccount() menolak sebelum mereka menyentuh data.
        $thrown = null;

        try {
            app(PpdbPortalAccess::class)->requireAccount($account->user);
        } catch (HttpException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'requireAccount() wajib menolak akun pending.');
        $this->assertSame(403, $thrown->getStatusCode());
        $this->assertStringContainsString('belum aktif', $thrown->getMessage());
    }

    // =================================================================
    // ADMIN — AKUN ORANG TUA
    // =================================================================

    public function test_daftar_akun_admin_menampilkan_jumlah_anak_dan_pendaftaran(): void
    {
        $period = $this->period();
        $program = $this->program($period);
        $wave = $this->wave($period);

        // Satu orang tua, dua anak, tiga pendaftaran (anak kedua mendaftar 2x).
        $account = $this->account();
        $first = $this->child($account, 'Anak Sulung 9X7Q');
        $second = $this->child($account, 'Anak Kedua 4T2M');
        $this->registration($account, $first, $period, $program, $wave);
        $this->registration($account, $second, $period, $program, $wave);
        $this->registration($account, $second, $period, $program, $wave);

        // Akun lain tanpa anak, agar hitungan terbukti per akun.
        $pending = $this->account(PpdbParentAccount::STATUS_PENDING);

        $this->actingAsRole('panitia-ppdb');

        $index = $this->getJson('/api/v1/ppdb/parent-accounts?search='.urlencode($account->user->email))->assertOk();

        $index->assertJsonPath('data.0.parent_account_id', $account->id)
            ->assertJsonPath('data.0.name', $account->user->name)
            ->assertJsonPath('data.0.email', $account->user->email)
            ->assertJsonPath('data.0.phone', $account->user->phone)
            ->assertJsonPath('data.0.account_status', PpdbParentAccount::STATUS_ACTIVE)
            ->assertJsonPath('data.0.account_status_label', 'Aktif')
            ->assertJsonPath('data.0.applicant_count', 2)
            ->assertJsonPath('data.0.registration_count', 3)
            ->assertJsonPath('data.0.one_account_for_all_children', true);
        $this->assertNotNull($index->json('data.0.verified_at'));
        $this->assertNotNull($index->json('data.0.last_activity_at'));
        $this->assertSame(2, $index->json('meta.total_accounts'));
        $this->assertSame(
            ['pending_verification' => 1, 'active' => 1, 'inactive' => 0, 'blocked' => 0],
            $index->json('meta.by_status'),
        );
        $this->assertSame(
            ['pending_verification', 'active', 'inactive', 'blocked'],
            array_column($index->json('meta.status_options'), 'value'),
        );

        // Filter status akun.
        $this->getJson('/api/v1/ppdb/parent-accounts?account_status=pending_verification')
            ->assertOk()
            ->assertJsonPath('data.0.parent_account_id', $pending->id)
            ->assertJsonPath('data.0.applicant_count', 0)
            ->assertJsonPath('data.0.registration_count', 0);

        // Detail: anak + agregat pendaftaran, tanpa isi pendaftaran/tagihan.
        $show = $this->getJson('/api/v1/ppdb/parent-accounts/'.$account->id)->assertOk();
        $this->assertCount(2, $show->json('data.applicants'));
        $this->assertEqualsCanonicalizing(
            ['Anak Sulung 9X7Q', 'Anak Kedua 4T2M'],
            array_column($show->json('data.applicants'), 'full_name'),
        );
        $this->assertSame(3, $show->json('data.registration_summary.total'));
        $this->assertSame(3, $show->json('data.registration_summary.by_status.draft'));
        $this->assertCount(3, $show->json('data.recent_registrations'));
        $this->assertStringContainsString('menaungi 2 anak', (string) $show->json('data.children_note'));

        // Hanya pemegang `ppdb.account.manage` yang boleh membaca identitas ini.
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/ppdb/parent-accounts')->assertForbidden();
        $this->getJson('/api/v1/ppdb/parent-accounts/'.$account->id)->assertForbidden();
        $this->postJson('/api/v1/ppdb/parent-accounts/'.$account->id.'/status', ['account_status' => 'blocked'])
            ->assertForbidden();
    }

    public function test_status_akun_divalidasi_dan_pemblokiran_membaca_portal(): void
    {
        $account = $this->account();
        $admin = $this->actingAsRole('panitia-ppdb');

        // Status di luar 4 yang didokumentasikan -> 422, akun tidak berubah.
        $this->postJson('/api/v1/ppdb/parent-accounts/'.$account->id.'/status', ['account_status' => 'dibekukan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['account_status']);
        $this->assertSame(PpdbParentAccount::STATUS_ACTIVE, $account->refresh()->account_status);

        $blocked = $this->postJson('/api/v1/ppdb/parent-accounts/'.$account->id.'/status', [
            'account_status' => PpdbParentAccount::STATUS_BLOCKED,
            'notes' => 'Dugaan data ganda pada dua akun.',
        ])->assertOk();

        $blocked->assertJsonPath('data.can_transact', false)
            ->assertJsonPath('data.parent_account.account_status', PpdbParentAccount::STATUS_BLOCKED)
            ->assertJsonPath('data.parent_account.notes', 'Dugaan data ganda pada dua akun.');
        $this->assertStringContainsString('read-only', (string) $blocked->json('data.portal_effect'));
        $this->assertFalse($account->refresh()->canTransact());

        // Jejak audit + kabar ke orang tuanya.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'ppdb',
            'event' => 'PPDB_ACCOUNT_STATUS_CHANGED',
            'subject_type' => PpdbParentAccount::class,
            'subject_id' => $account->id,
            'causer_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $account->user_id,
            'type' => PpdbActivityNotification::class,
            'data->type' => 'account_blocked',
        ]);

        // Akun terblokir: kotak masuk tetap terbaca (pesan blokir sampai),
        // aksi tulis portal ditolak.
        Sanctum::actingAs($account->user, ['*']);
        $this->getJson('/api/v1/ppdb/portal/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/ppdb/portal/notifications/read-all')->assertForbidden();

        // Diaktifkan kembali: transaksi portal terbuka lagi.
        $this->actingAsRole('panitia-ppdb');
        $this->postJson('/api/v1/ppdb/parent-accounts/'.$account->id.'/status', ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.can_transact', true);
        $this->assertTrue($account->refresh()->canTransact());
    }

    public function test_reset_password_sekali_pakai_dan_tercatat_di_audit(): void
    {
        $account = $this->account();
        $admin = $this->actingAsRole('admin-sekolah');
        $before = $account->user->password;

        $response = $this->postJson('/api/v1/ppdb/parent-accounts/'.$account->id.'/reset-password')->assertOk();

        $temporary = (string) $response->json('data.temporary_password');
        $this->assertNotSame('', $temporary);
        $this->assertTrue($response->json('data.one_time_credential'));
        $this->assertStringContainsString('satu kali', (string) $response->json('data.warning'));

        // Yang tersimpan hanya hash baru; password lama tidak berlaku lagi.
        $fresh = $account->user->refresh();
        $this->assertNotSame($before, $fresh->password);
        $this->assertTrue(Hash::check($temporary, $fresh->password));
        $this->assertFalse(Hash::check('rahasia123', $fresh->password));

        // Audit tercatat tanpa menyalin kredensialnya.
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'ppdb',
            'event' => 'PPDB_ACCOUNT_PASSWORD_RESET',
            'subject_type' => PpdbParentAccount::class,
            'subject_id' => $account->id,
            'causer_id' => $admin->id,
        ]);

        $auditTrail = Activity::query()->where('subject_id', $account->id)->pluck('properties')->implode('|');
        $this->assertStringNotContainsString($temporary, $auditTrail);

        // Orang tua diberi tahu tanpa dikirimi password-nya.
        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $account->user_id)
            ->where('data->type', 'account_password_reset')
            ->sole();
        $this->assertStringNotContainsString($temporary, (string) $notification->data['body']);
        $this->assertStringNotContainsString($temporary, (string) json_encode($notification->data));
    }

    // =================================================================
    // KOTAK MASUK NOTIFIKASI
    // =================================================================

    public function test_kotak_masuk_notifikasi_terisolasi_per_pengguna(): void
    {
        $parentA = $this->account();
        $parentB = $this->account();

        $parentA->user->notify(new PpdbActivityNotification(
            PpdbActivityNotification::TYPE_REGISTRATION_SUBMITTED,
            'Pendaftaran A1 terkirim',
            'Isi pesan untuk orang tua A nomor satu',
            ['parent_account_id' => $parentA->id, 'registration_number' => 'PPDB-A1-RAHASIA'],
        ));
        $parentA->user->notify(new PpdbActivityNotification(
            PpdbActivityNotification::TYPE_REGISTRATION_VERIFIED,
            'Pendaftaran A2 terverifikasi',
            'Isi pesan untuk orang tua A nomor dua',
            ['parent_account_id' => $parentA->id],
        ));
        $parentB->user->notify(new PpdbActivityNotification(
            PpdbActivityNotification::TYPE_PAYMENT_REMINDER,
            'Pengingat pembayaran B',
            'Isi pesan untuk orang tua B',
            ['parent_account_id' => $parentB->id],
        ));

        $foreign = DatabaseNotification::query()->where('notifiable_id', $parentB->user_id)->sole();
        $secondOfA = DatabaseNotification::query()
            ->where('notifiable_id', $parentA->user_id)
            ->where('data->title', 'Pendaftaran A2 terverifikasi')
            ->sole();

        Sanctum::actingAs($parentA->user, ['*']);

        $this->getJson('/api/v1/ppdb/portal/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 2)
            ->assertJsonPath('data.total', 2);

        $inbox = $this->getJson('/api/v1/ppdb/portal/notifications?per_page=5')->assertOk();
        $this->assertCount(2, $inbox->json('data'));
        $this->assertSame(2, $inbox->json('meta.unread_count'));
        $this->assertEqualsCanonicalizing(
            ['Pendaftaran A1 terkirim', 'Pendaftaran A2 terverifikasi'],
            array_column($inbox->json('data'), 'title'),
        );
        $this->assertSame('Pendaftaran terkirim', $inbox->json('data.0.type_label'));
        $this->assertFalse($inbox->json('data.0.is_read'));
        $this->assertContains($inbox->json('data.0.type'), [
            PpdbActivityNotification::TYPE_REGISTRATION_SUBMITTED,
            PpdbActivityNotification::TYPE_REGISTRATION_VERIFIED,
        ]);
        // Kotak masuk orang tua lain tidak pernah ikut terbaca.
        $this->assertStringNotContainsString('orang tua B', $inbox->getContent());

        // Notifikasi milik orang tua B: 403 dan tetap belum terbaca.
        $stolen = $this->postJson('/api/v1/ppdb/portal/notifications/'.$foreign->id.'/read')->assertForbidden();
        $this->assertStringContainsString('bukan milik Anda', (string) $stolen->json('message'));
        $this->assertNull($foreign->refresh()->read_at);

        // ID yang memang tidak ada -> 404, bukan 500.
        $this->postJson('/api/v1/ppdb/portal/notifications/'.(string) Str::uuid().'/read')->assertNotFound();

        // Miliknya sendiri boleh ditandai terbaca.
        $this->postJson('/api/v1/ppdb/portal/notifications/'.$secondOfA->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $secondOfA->id)
            ->assertJsonPath('data.is_read', true);
        $this->getJson('/api/v1/ppdb/portal/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        // `unread_only` menyisakan yang belum terbaca.
        $unread = $this->getJson('/api/v1/ppdb/portal/notifications?unread_only=1')->assertOk();
        $this->assertCount(1, $unread->json('data'));
        $this->assertSame('Pendaftaran A1 terkirim', $unread->json('data.0.title'));

        // read-all hanya menyentuh baris pemanggil.
        $this->postJson('/api/v1/ppdb/portal/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_as_read', 1)
            ->assertJsonPath('data.unread', 0);
        $this->assertNull($foreign->refresh()->read_at);

        // Pengguna tanpa akun PPDB: penolakan bersih, bukan 500.
        Sanctum::actingAs($this->user(), ['*']);
        $refused = $this->getJson('/api/v1/ppdb/portal/notifications')->assertForbidden();
        $this->assertStringContainsString('Akun PPDB Anda tidak ditemukan', (string) $refused->json('message'));
        $this->getJson('/api/v1/ppdb/portal/notifications/unread-count')->assertForbidden();
        $this->postJson('/api/v1/ppdb/portal/notifications/read-all')->assertForbidden();
    }

    // =================================================================
    // HELPER
    // =================================================================

    /**
     * Lapis 1: kunci data pribadi tidak boleh ada (dipindai rekursif).
     * Lapis 2: nilai fixture rahasia tidak boleh muncul di body.
     *
     * @param  list<string>  $secretValues
     */
    private function assertNoPrivateData(string $content, array $secretValues): void
    {
        $keys = $this->keysOf((array) json_decode($content, true));

        $this->assertSame([], array_values(array_intersect(self::PRIVATE_KEYS, $keys)));

        foreach ($secretValues as $value) {
            $this->assertStringNotContainsString($value, $content);
        }
    }

    /** @return list<string> */
    private function keysOf(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[$key] = true;
            }

            if (is_array($value)) {
                foreach ($this->keysOf($value) as $nested) {
                    $keys[$nested] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Nilai yang hanya boleh dilihat pemiliknya atau panitia.
     *
     * @return list<string>
     */
    private function secretValues(PpdbRegistration $registration): array
    {
        $registration->loadMissing(['applicant', 'parentAccount.user', 'parentAccount.guardian']);

        return [
            (string) $registration->registration_number,
            (string) $registration->applicant->full_name,
            (string) $registration->applicant->nik,
            (string) $registration->applicant->nisn,
            (string) $registration->parentAccount->user->email,
            (string) $registration->parentAccount->user->phone,
            (string) $registration->parentAccount->guardian->address,
            'PPDB-RAHASIA',
        ];
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'.portal@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'name' => ucfirst($role), 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function user(): User
    {
        static $sequence = 0;
        $sequence++;

        return User::factory()->create([
            'name' => 'Orang Tua Uji '.$sequence,
            'email' => 'ortu-uji-'.$sequence.'@ppdb-uji.test',
            'phone' => '0811000'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'password' => Hash::make('rahasia123'),
            'is_active' => true,
        ]);
    }

    /** Akun orang tua + baris `guardians` existing (identitas tidak disalin). */
    private function account(string $status = PpdbParentAccount::STATUS_ACTIVE, ?User $user = null): PpdbParentAccount
    {
        $user ??= $this->user();

        $guardian = Guardian::create([
            'full_name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'address' => 'Alamat Wali '.$user->id,
        ]);

        return PpdbParentAccount::create([
            'user_id' => $user->id,
            'guardian_id' => $guardian->id,
            'account_status' => $status,
            'verified_at' => $status === PpdbParentAccount::STATUS_ACTIVE ? now() : null,
            'last_activity_at' => now(),
        ]);
    }

    private function period(array $overrides = []): PpdbPeriod
    {
        static $sequence = 0;
        $sequence++;

        return PpdbPeriod::create(array_merge([
            'code' => 'UJI-PUB-'.$sequence,
            'name' => 'PPDB Publik Uji '.$sequence,
            'slug' => 'ppdb-publik-uji-'.$sequence,
            'public_title' => 'Penerimaan Peserta Didik Baru '.$sequence,
            'public_description' => 'Info publik periode '.$sequence,
            'contact_name' => 'Sekretariat PPDB',
            'contact_phone' => '021-555-'.$sequence,
            'contact_email' => 'sekretariat'.$sequence.'@sekolah.test',
            'contact_address' => 'Jl. Sekolah No. '.$sequence,
            'faq' => [['question' => 'Kapan pendaftaran dibuka?', 'answer' => 'Sesuai jadwal periode.']],
            'registration_start' => now()->subDay()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
            'selection_announcement_date' => now()->addMonths(2)->toDateString(),
            'status' => PpdbPeriod::STATUS_OPEN,
        ], $overrides));
    }

    private function program(PpdbPeriod $period, array $overrides = []): PpdbProgram
    {
        static $sequence = 0;
        $sequence++;

        return PpdbProgram::create(array_merge([
            'period_id' => $period->id,
            'code' => 'PUB-'.$sequence,
            'name' => 'Program Uji '.$sequence,
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
            'code' => 'GWP-'.$sequence,
            'name' => 'Gelombang Uji '.$sequence,
            'quota' => 40,
            'priority' => 1,
            'status' => PpdbWave::STATUS_ACTIVE,
            'registration_start' => now()->subDay()->toDateString(),
            'registration_end' => now()->addMonth()->toDateString(),
        ], $overrides));
    }

    private function fee(PpdbPeriod $period, ?PpdbProgram $program = null): PpdbRegistrationFee
    {
        return PpdbRegistrationFee::create([
            'period_id' => $period->id,
            'program_id' => $program?->id,
            'name' => 'Biaya Pendaftaran Uji',
            'type' => PpdbRegistrationFee::TYPE_REGISTRATION,
            'amount' => 150000,
        ]);
    }

    private function requirement(PpdbPeriod $period, ?PpdbProgram $program = null): PpdbDocumentRequirement
    {
        static $sequence = 0;
        $sequence++;

        return PpdbDocumentRequirement::create([
            'period_id' => $period->id,
            'program_id' => $program?->id,
            'code' => 'REQ-'.$sequence,
            'name' => 'Kartu Keluarga Uji',
            'file_types' => 'pdf,jpg',
            'max_size_kb' => 1024,
        ]);
    }

    private function stage(PpdbPeriod $period): PpdbSelectionStage
    {
        return PpdbSelectionStage::create([
            'period_id' => $period->id,
            'name' => 'Tes Akademik Uji',
            'type' => 'academic_test',
            'status' => PpdbSelectionStage::STATUS_SCHEDULED,
            'scheduled_at' => now()->addWeeks(3),
            'location' => 'Aula Sekolah',
            'order' => 1,
        ]);
    }

    private function child(PpdbParentAccount $account, string $name): PpdbApplicant
    {
        static $sequence = 0;
        $sequence++;

        return PpdbApplicant::create([
            'parent_account_id' => $account->id,
            'guardian_id' => $account->guardian_id,
            'full_name' => $name,
            'nik' => 'NIK-RAHASIA-'.$sequence,
            'nisn' => 'NISN-RAHASIA-'.$sequence,
            'birth_date' => '2013-04-01',
            'status' => PpdbApplicant::STATUS_ACTIVE,
        ]);
    }

    private function registration(
        PpdbParentAccount $account,
        PpdbApplicant $applicant,
        PpdbPeriod $period,
        PpdbProgram $program,
        ?PpdbWave $wave = null,
    ): PpdbRegistration {
        static $sequence = 0;
        $sequence++;

        return PpdbRegistration::create([
            'registration_number' => 'PPDB-RAHASIA-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'period_id' => $period->id,
            'program_id' => $program->id,
            'wave_id' => $wave?->id,
            'applicant_id' => $applicant->id,
            'parent_account_id' => $account->id,
        ]);
    }

    /** Akun + calon + pendaftaran penuh: "data rahasia" untuk uji kebocoran. */
    private function application(PpdbPeriod $period, PpdbProgram $program, ?PpdbWave $wave = null): PpdbRegistration
    {
        $account = $this->account();

        return $this->registration($account, $this->child($account, 'Calon Rahasia Sekali'), $period, $program, $wave);
    }
}
