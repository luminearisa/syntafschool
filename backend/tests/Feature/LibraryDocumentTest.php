<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\LibraryItem;
use App\Models\Student;
use App\Models\User;
use App\Services\Library\Documents\CodeImageService;
use App\Services\Library\LibraryService;
use Database\Seeders\LibraryMasterSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dokumen PDF & gambar QR/barcode (library: dompdf + bacon-qr-code + picqer).
 */
class LibraryDocumentTest extends TestCase
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
        Sanctum::actingAs($user);

        return $user;
    }

    private function library(string $code = 'LIB-DOC'): Library
    {
        return app(LibraryService::class)->create([
            'code' => $code,
            'name' => 'Perpustakaan Dokumen',
            'timezone' => 'Asia/Jakarta',
            'status' => Library::STATUS_DRAFT,
        ]);
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

    // =================================================================
    // GAMBAR QR & BARCODE
    // =================================================================

    public function test_qr_dan_barcode_dibangkitkan_sebagai_png(): void
    {
        $codes = app(CodeImageService::class);

        $qr = $codes->qrPng('TOKEN-UJI-123');
        // Header PNG.
        $this->assertSame("\x89PNG", substr($qr, 0, 4));
        $this->assertStringStartsWith('data:image/png;base64,', $codes->qrDataUri('TOKEN-UJI-123'));

        $barcode = $codes->barcodePng('LIB-0001');
        $this->assertNotNull($barcode);
        $this->assertSame("\x89PNG", substr((string) $barcode, 0, 4));

        // Nilai kosong tidak menghasilkan barcode.
        $this->assertNull($codes->barcodePng(null));
    }

    // =================================================================
    // KARTU ANGGOTA
    // =================================================================

    public function test_kartu_anggota_pdf_dapat_diunduh(): void
    {
        $library = $this->library();
        $this->actingAsRole('admin-sekolah');

        $student = Student::create(['nis' => 'S-PDF', 'full_name' => 'Anggota PDF', 'is_active' => true]);

        $memberId = $this->postJson('/api/v1/library/members', [
            'library_id' => $library->id,
            'student_id' => $student->id,
            'membership_type_id' => $this->master('membership-types', 'STUDENT'),
            'valid_until' => now()->addYear()->toDateString(),
        ])->json('data.id');

        // Terbitkan kartu agar QR memuat token kartu.
        $cardId = $this->postJson("/api/v1/library/members/{$memberId}/card/generate")->json('data.id');

        $response = $this->get("/api/v1/library/members/{$memberId}/card/pdf")->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));

        $this->assertNotNull($cardId);
    }

    // =================================================================
    // LABEL EKSEMPLAR
    // =================================================================

    public function test_label_eksemplar_pdf_dapat_dicetak(): void
    {
        $library = $this->library();
        $this->actingAsRole('admin-sekolah');

        $bibliographyId = $this->postJson('/api/v1/library/catalog', [
            'library_id' => $library->id,
            'title' => 'Buku Label',
            'collection_type_id' => $this->master('collection-types', 'BOOK'),
        ])->json('data.id');

        $this->postJson("/api/v1/library/catalog/{$bibliographyId}/items/bulk", ['quantity' => 2]);

        $response = $this->getJson('/api/v1/library/labels/pdf?bibliography_id='.$bibliographyId)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Tanpa pilihan eksemplar/judul → 422.
        $this->getJson('/api/v1/library/labels/pdf')->assertStatus(422);
    }

    // =================================================================
    // LAPORAN PDF
    // =================================================================

    public function test_seluruh_laporan_pdf_dapat_diunduh(): void
    {
        $library = $this->library();
        $this->actingAsRole('admin-sekolah');

        foreach (['collection', 'circulation', 'member', 'fine', 'acquisition', 'inventory', 'reservation'] as $type) {
            $response = $this->get("/api/v1/library/reports/{$type}/pdf?library_id={$library->id}")->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('content-type'), "laporan {$type}");
            $this->assertStringStartsWith('%PDF', $response->getContent(), "laporan {$type}");
        }

        // Jenis tidak dikenal → 404, bukan 500.
        $this->getJson('/api/v1/library/reports/tidak-ada/pdf')->assertNotFound();
    }

    // =================================================================
    // OTORISASI
    // =================================================================

    public function test_otorisasi_dokumen_pdf(): void
    {
        $library = $this->library();

        // Guru tidak punya akses laporan perpustakaan.
        $this->actingAsRole('guru');
        $this->getJson("/api/v1/library/reports/collection/pdf?library_id={$library->id}")->assertForbidden();

        // Pustakawan boleh mencetak label (item.view) dan laporan (report.view).
        $this->actingAsRole('pustakawan');
        $this->getJson("/api/v1/library/reports/collection/pdf?library_id={$library->id}")->assertOk();

        $this->assertSame(0, LibraryItem::query()->count());
    }
}