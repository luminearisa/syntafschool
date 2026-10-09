<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryFine;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\Student;
use App\Models\User;
use App\Services\Library\LibraryService;
use Database\Seeders\LibraryMasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Fase 4 — Sirkulasi, denda, reservasi, pengadaan, inventaris.
 *
 * Mengikuti skenario §22: catalog→item→member→checkout→return,
 * checkout→overdue→fine→payment, reservation→available→pickup→checkout,
 * acquisition→receive→item, inventory→scan→finalize, plus validasi
 * (keanggotaan kedaluwarsa, eksemplar tak tersedia, batas pinjam, dan
 * peminjaman ganda atas satu eksemplar).
 */
class LibraryCirculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(LibraryMasterSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $email = str_replace('-', '.', $role).'@schoolerp.test';

        $user = User::query()->firstWhere('email', $email)
            ?? User::factory()->create(['email' => $email, 'is_active' => true]);

        $user->syncRoles([$role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function library(string $code = 'LIB-CIRC'): Library
    {
        $library = app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Sirkulasi',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);

        // Aktifkan denda agar skenario denda bisa diuji.
        app(\App\Services\Library\LibrarySettingsService::class)->update($library, [
            'fine.enabled' => true,
            'fine.per_day' => 1000,
            'reservation.enabled' => true,
        ]);

        return $library->refresh();
    }

    private function master(string $key, string $code): int
    {
        $master = \App\Services\Library\MasterData\LibraryMasterRegistry::find($key);

        $query = $master['model']::query()->where('code', $code);

        if ($master['scoped']) {
            $query->whereNull('library_id');
        }

        return (int) $query->value('id');
    }

    /** Satu judul + sejumlah eksemplar siap pinjam. */
    private function bibliography(Library $library, int $copies = 1, string $title = 'Belajar Laravel'): array
    {
        $this->actingAsRole('admin-sekolah');

        $id = $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => $title,
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
        ])->json('data.id');

        $items = $this->postJson("/api/v1/library/catalog/{$id}/items/bulk", ['quantity' => $copies])
            ->json('data');

        $this->putJson("/api/v1/library/catalog/{$id}", ['title' => $title, 'status' => 'active'])->assertOk();

        return [$id, $items];
    }

    private function member(Library $library, string $nis = 'S-001', array $overrides = []): array
    {
        $this->actingAsRole('admin-sekolah');

        $student = Student::create(['nis' => $nis, 'full_name' => 'Ahmad Fauzan', 'is_active' => true]);

        $response = $this->postJson('/api/v1/library/members', array_merge([
            'library_id' => $library->id,
            'student_id' => $student->id,
            'membership_type_id' => $this->master('membership-types', 'STUDENT'),
            'valid_from' => now()->toDateString(),
            'valid_until' => now()->addYear()->toDateString(),
        ], $overrides))->assertCreated();

        return [$response->json('data.id'), $student];
    }

    // =================================================================
    // SKENARIO 1 — catalog → item → member → checkout → return
    // =================================================================

    public function test_skenario_peminjaman_dan_pengembalian(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 2);
        [$memberId] = $this->member($library);

        $this->actingAsRole('pustakawan');

        // Checkout.
        $response = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId,
            'item_id' => $items[0]['id'],
        ])->assertCreated();

        $loanId = $response->json('data.id');
        $this->assertSame('active', $response->json('data.status'));
        $this->assertStringStartsWith('LOAN-', $response->json('data.loan_code'));
        // Tenggat mengikuti policy jenis keanggotaan (Siswa = 7 hari).
        $this->assertSame(now()->addDays(7)->toDateString(), $response->json('data.due_at'));

        // Eksemplar menjadi on_loan.
        $this->assertSame('on_loan', LibraryItem::query()->find($items[0]['id'])->status);

        // Return.
        $this->postJson("/api/v1/library/loans/{$loanId}/return")
            ->assertOk()
            ->assertJsonPath('data.status', 'returned');

        $this->assertSame('available', LibraryItem::query()->find($items[0]['id'])->status);

        $events = Activity::query()->where('log_name', 'library')->pluck('event')->all();
        $this->assertContains('LOAN_CHECKOUT', $events);
        $this->assertContains('LOAN_RETURNED', $events);
    }

    public function test_eksemplar_yang_tidak_tersedia_ditolak(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library);

        LibraryItem::query()->whereKey($items[0]['id'])->update(['status' => 'withdrawn']);

        $this->actingAsRole('pustakawan');
        $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId,
            'item_id' => $items[0]['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('item');
    }

    public function test_keanggotaan_kedaluwarsa_tidak_dapat_meminjam(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);

        // Keanggotaan aktif, lalu masa berlakunya lewat (status expired).
        [$memberId] = $this->member($library, 'S-002');

        \App\Models\LibraryMember::query()->whereKey($memberId)->update([
            'valid_until' => now()->subDay()->toDateString(),
            'status' => 'expired',
        ]);

        $this->actingAsRole('pustakawan');
        $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId,
            'item_id' => $items[0]['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('member');
    }

    public function test_satu_eksemplar_tidak_dapat_dipinjam_dua_kali(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberA] = $this->member($library, 'S-003');
        [$memberB] = $this->member($library, 'S-004');

        $this->actingAsRole('pustakawan');

        $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberA, 'item_id' => $items[0]['id'],
        ])->assertCreated();

        // Peminjaman kedua atas eksemplar yang sama ditolak (status on_loan).
        $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberB, 'item_id' => $items[0]['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('item');

        $this->assertSame(1, LibraryLoan::query()->where('item_id', $items[0]['id'])->count());
    }

    public function test_batas_jumlah_pinjaman_mengikuti_jenis_keanggotaan(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 5);
        [$memberId] = $this->member($library, 'S-005');

        $this->actingAsRole('pustakawan');

        // Siswa: max_loan = 3.
        foreach (array_slice($items, 0, 3) as $item) {
            $this->postJson('/api/v1/library/circulation/checkout', [
                'member_id' => $memberId, 'item_id' => $item['id'],
            ])->assertCreated();
        }

        $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId, 'item_id' => $items[3]['id'],
        ])->assertStatus(422)->assertJsonValidationErrors('member');
    }

    public function test_checkout_dan_return_dapat_memakai_kode_hasil_pindai(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-006');

        $this->actingAsRole('pustakawan');

        $memberNumber = \App\Models\LibraryMember::query()->find($memberId)->member_number;

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_code' => $memberNumber,
            'item_code' => $items[0]['barcode'],
        ])->assertCreated()->json('data.id');

        // Pencarian cepat mengenali kode yang sama.
        $this->getJson('/api/v1/library/circulation/lookup?code='.$items[0]['barcode'])
            ->assertOk()
            ->assertJsonPath('data.type', 'item');

        $this->getJson('/api/v1/library/circulation/lookup?code='.$memberNumber)
            ->assertOk()
            ->assertJsonPath('data.type', 'member');

        $this->postJson("/api/v1/library/loans/{$loanId}/return")->assertOk();
    }

    // =================================================================
    // SKENARIO 2 — checkout → overdue → fine → payment
    // =================================================================

    public function test_skenario_keterlambatan_denda_dan_pembayaran(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-007');

        $this->actingAsRole('pustakawan');

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId, 'item_id' => $items[0]['id'],
        ])->json('data.id');

        // Mundurkan tenggat agar terlambat 5 hari.
        LibraryLoan::query()->whereKey($loanId)->update(['due_at' => now()->subDays(5)->toDateString()]);

        // Penandaan terlambat adalah aksi pengelola (juga dijalankan scheduler).
        $this->actingAsRole('library-manager');
        $this->postJson('/api/v1/library/circulation/overdue/mark')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $loan = LibraryLoan::query()->find($loanId);
        $this->assertSame('overdue', $loan->status);
        $this->assertSame(5, $loan->overdueDays());

        // Denda 5 × 1.000 = 5.000.
        $fine = LibraryFine::query()->where('loan_id', $loanId)->first();
        $this->assertNotNull($fine);
        $this->assertSame(5000.0, (float) $fine->amount);
        $this->assertSame('unpaid', $fine->status);

        // Menjalankan ulang tidak menggandakan denda (idempoten).
        $this->postJson('/api/v1/library/circulation/overdue/mark')->assertOk();
        $this->assertSame(1, LibraryFine::query()->where('loan_id', $loanId)->count());

        // Pembayaran dilakukan pustakawan (fine.manage).
        $this->actingAsRole('pustakawan');

        // Pembayaran sebagian lalu pelunasan.
        $this->postJson("/api/v1/library/fines/{$fine->id}/pay", ['amount' => 2000])
            ->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.outstanding_amount', 3000);

        $this->postJson("/api/v1/library/fines/{$fine->id}/pay", ['amount' => 3000])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.outstanding_amount', 0);

        // Kelebihan bayar ditolak.
        $this->postJson("/api/v1/library/fines/{$fine->id}/pay", ['amount' => 100])
            ->assertStatus(422);

        $this->assertContains(
            'FINE_PAID',
            Activity::query()->where('log_name', 'library')->pluck('event')->all()
        );
    }

    public function test_denda_dapat_dibebaskan_sebagian(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-008');

        $this->actingAsRole('pustakawan');

        $fineId = $this->postJson('/api/v1/library/fines', [
            'member_id' => $memberId,
            'type' => 'damage',
            'amount' => 10000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/library/fines/{$fineId}/waive", [
            'reason' => 'Kerusakan ringan, dibebaskan sebagian',
            'amount' => 4000,
        ])->assertOk()->assertJsonPath('data.status', 'partial');

        $this->postJson("/api/v1/library/fines/{$fineId}/waive", [
            'reason' => 'Sisa dibebaskan',
        ])->assertOk()->assertJsonPath('data.status', 'waived');

        $this->assertSame(0.0, (float) LibraryFine::query()->find($fineId)->outstandingAmount());
    }

    public function test_perpanjangan_memperpanjang_tenggat_dan_dibatasi(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-009');

        $this->actingAsRole('pustakawan');

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId, 'item_id' => $items[0]['id'],
        ])->json('data.id');

        $dueAt = LibraryLoan::query()->find($loanId)->due_at->copy();

        $this->postJson("/api/v1/library/loans/{$loanId}/renew")
            ->assertOk()
            ->assertJsonPath('data.renewed_count', 1);

        $this->assertSame(
            $dueAt->addDays(7)->toDateString(),
            LibraryLoan::query()->find($loanId)->due_at->toDateString()
        );

        // Siswa: renewal_limit = 1.
        $this->postJson("/api/v1/library/loans/{$loanId}/renew")
            ->assertStatus(422);
    }

    public function test_pinjaman_terlambat_tidak_dapat_diperpanjang(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-010');

        $this->actingAsRole('pustakawan');

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberId, 'item_id' => $items[0]['id'],
        ])->json('data.id');

        LibraryLoan::query()->whereKey($loanId)->update(['due_at' => now()->subDays(2)->toDateString()]);

        $this->postJson("/api/v1/library/loans/{$loanId}/renew")->assertStatus(422);
    }

    // =================================================================
    // SKENARIO 3 — reservation → available → pickup → checkout
    // =================================================================

    public function test_skenario_reservasi_sampai_peminjaman(): void
    {
        $library = $this->library();
        [$bibliographyId, $items] = $this->bibliography($library, 1);
        [$memberA] = $this->member($library, 'S-011');
        [$memberB] = $this->member($library, 'S-012');

        $this->actingAsRole('pustakawan');

        // A meminjam satu-satunya eksemplar.
        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberA, 'item_id' => $items[0]['id'],
        ])->json('data.id');

        // B mengantre.
        $reservationId = $this->postJson('/api/v1/library/reservations', [
            'member_id' => $memberB,
            'bibliography_id' => $bibliographyId,
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, (int) $this->getJson("/api/v1/library/reservations/{$reservationId}")->json('data.queue_position'));

        // Pengembalian oleh A menahan eksemplar untuk B.
        $this->postJson("/api/v1/library/loans/{$loanId}/return")->assertOk();

        $this->getJson("/api/v1/library/reservations/{$reservationId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $this->assertSame('reserved', LibraryItem::query()->find($items[0]['id'])->status);

        // B mengambil → otomatis menjadi peminjaman.
        $response = $this->postJson("/api/v1/library/reservations/{$reservationId}/pickup")->assertOk();

        $this->assertSame('completed', $response->json('data.reservation.status'));
        $this->assertSame('active', $response->json('data.loan.status'));
        $this->assertSame('on_loan', LibraryItem::query()->find($items[0]['id'])->status);
    }

    public function test_reservasi_ditolak_bila_eksemplar_tersedia(): void
    {
        $library = $this->library();
        [$bibliographyId] = $this->bibliography($library, 1);
        [$memberId] = $this->member($library, 'S-013');

        $this->actingAsRole('pustakawan');

        $this->postJson('/api/v1/library/reservations', [
            'member_id' => $memberId,
            'bibliography_id' => $bibliographyId,
        ])->assertStatus(422)->assertJsonValidationErrors('reservation');
    }

    public function test_reservasi_yang_tidak_diambil_kedaluwarsa(): void
    {
        $library = $this->library();
        [$bibliographyId, $items] = $this->bibliography($library, 1);
        [$memberA] = $this->member($library, 'S-014');
        [$memberB] = $this->member($library, 'S-015');

        $this->actingAsRole('pustakawan');

        $loanId = $this->postJson('/api/v1/library/circulation/checkout', [
            'member_id' => $memberA, 'item_id' => $items[0]['id'],
        ])->json('data.id');

        $reservationId = $this->postJson('/api/v1/library/reservations', [
            'member_id' => $memberB, 'bibliography_id' => $bibliographyId,
        ])->json('data.id');

        $this->postJson("/api/v1/library/loans/{$loanId}/return")->assertOk();

        // Mundurkan tenggat pengambilan.
        \App\Models\LibraryReservation::query()->whereKey($reservationId)
            ->update(['expires_at' => now()->subDay()->toDateString()]);

        $this->postJson('/api/v1/library/reservations/expire')
            ->assertOk()
            ->assertJsonPath('data.expired', 1);

        // Eksemplar kembali tersedia karena tidak ada antrean lain.
        $this->assertSame('available', LibraryItem::query()->find($items[0]['id'])->status);
    }

    // =================================================================
    // SKENARIO 4 — acquisition → receive → item
    // =================================================================

    public function test_skenario_pengadaan_sampai_eksemplar(): void
    {
        $library = $this->library();

        $this->actingAsRole('library-manager');

        $requestId = $this->postJson('/api/v1/library/acquisitions', [
            'library_id' => $library->id,
            'supplier_name' => 'Toko Buku Uji',
            'items' => [
                ['title' => 'Buku Pengadaan Satu', 'quantity' => 3, 'unit_price' => 50000],
            ],
        ])->assertCreated()->json('data.id');

        // Draf → disetujui → dipesan → diterima.
        $this->postJson("/api/v1/library/acquisitions/{$requestId}/transition", ['status' => 'submitted'])->assertOk();
        $this->postJson("/api/v1/library/acquisitions/{$requestId}/transition", ['status' => 'approved'])->assertOk();
        $this->postJson("/api/v1/library/acquisitions/{$requestId}/transition", ['status' => 'ordered'])->assertOk();

        // Transisi tak sah ditolak.
        $this->postJson("/api/v1/library/acquisitions/{$requestId}/transition", ['status' => 'draft'])
            ->assertStatus(422);

        $response = $this->postJson("/api/v1/library/acquisitions/{$requestId}/receive")->assertOk();

        $this->assertSame(3, $response->json('data.created_items'));
        $this->assertSame('received', $response->json('data.request.status'));

        // Record katalog dibuat otomatis dan eksemplarnya ada.
        $bibliography = \App\Models\LibraryBibliography::query()
            ->where('title', 'Buku Pengadaan Satu')->first();

        $this->assertNotNull($bibliography);
        $this->assertSame(3, LibraryItem::query()->where('bibliography_id', $bibliography->id)->count());

        // Penerimaan ulang ditolak.
        $this->postJson("/api/v1/library/acquisitions/{$requestId}/receive")->assertStatus(422);

        // Pengadaan yang sudah diterima tidak boleh dihapus (koleksinya sudah ada).
        $this->deleteJson("/api/v1/library/acquisitions/{$requestId}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('acquisition');

        // Draf yang belum diterima boleh dihapus.
        $draftId = $this->postJson('/api/v1/library/acquisitions', [
            'library_id' => $library->id,
            'items' => [['title' => 'Draf Batal', 'quantity' => 1, 'unit_price' => 1000]],
        ])->json('data.id');

        $this->deleteJson("/api/v1/library/acquisitions/{$draftId}")->assertOk();
    }

    // =================================================================
    // SKENARIO 5 — inventory → scan → missing → finalize
    // =================================================================

    public function test_skenario_stock_opname(): void
    {
        $library = $this->library();
        [, $items] = $this->bibliography($library, 3);

        $this->actingAsRole('library-manager');

        $sessionId = $this->postJson('/api/v1/library/inventory-sessions', [
            'library_id' => $library->id,
            'name' => 'Opname Uji',
        ])->assertCreated()->json('data.id');

        // Pindai 2 eksemplar + 1 kode tak dikenal.
        $this->postJson("/api/v1/library/inventory-sessions/{$sessionId}/scan", ['code' => $items[0]['barcode']])
            ->assertOk()->assertJsonPath('data.result', 'found');

        $this->postJson("/api/v1/library/inventory-sessions/{$sessionId}/scan", ['code' => $items[1]['item_code']])
            ->assertOk()->assertJsonPath('data.result', 'found');

        $this->postJson("/api/v1/library/inventory-sessions/{$sessionId}/scan", ['code' => 'KODE-TIDAK-ADA'])
            ->assertOk()->assertJsonPath('data.is_unexpected', true);

        // Eksemplar ketiga tidak dipindai → hilang setelah finalisasi.
        $this->postJson("/api/v1/library/inventory-sessions/{$sessionId}/finalize", [
            'mark_missing_as_lost' => true,
        ])->assertOk()
            ->assertJsonPath('data.expected', 3)
            ->assertJsonPath('data.found', 2)
            ->assertJsonPath('data.missing', 1)
            ->assertJsonPath('data.unexpected', 1);

        $this->assertSame('lost', LibraryItem::query()->find($items[2]['id'])->status);

        // Ringkasan & laporan.
        $this->getJson("/api/v1/library/inventory-sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.missing', 1);

        $this->getJson('/api/v1/library/inventory-report')
            ->assertOk()
            ->assertJsonPath('data.totals.missing', 1);

        // Sesi yang sudah difinalkan tidak dapat dipindai lagi.
        $this->postJson("/api/v1/library/inventory-sessions/{$sessionId}/scan", ['code' => $items[0]['barcode']])
            ->assertStatus(422);
    }

    // =================================================================
    // OTORISASI
    // =================================================================

    public function test_otorisasi_sirkulasi_per_peran(): void
    {
        $library = $this->library();
        [$bibliographyId] = $this->bibliography($library, 1);

        // Tanpa permission sirkulasi.
        $this->actingAsRole('guru');
        $this->getJson('/api/v1/library/loans')->assertForbidden();

        // Pustakawan dapat meminjamkan tetapi tidak mengelola inventaris.
        $this->actingAsRole('pustakawan');
        $this->getJson('/api/v1/library/loans')->assertOk();
        $this->postJson('/api/v1/library/inventory-sessions', [
            'library_id' => $library->id,
            'name' => 'Tidak boleh',
        ])->assertForbidden();
    }
}