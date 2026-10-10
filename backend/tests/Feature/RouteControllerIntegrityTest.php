<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RouteControllerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Setiap route API harus menunjuk controller yang benar-benar ada.
     *
     * Bug yang dicegah: `Routes/api.php` modul HRD sempat memuat 150+ endpoint
     * ke sembilan controller yang tak pernah disertakan, sehingga permintaan
     * gagal fatal "Class not found" dan `php artisan route:list` berhenti.
     */
    public function test_setiap_route_api_menunjuk_controller_yang_ada(): void
    {
        $missing = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $controller = $route->getControllerClass();

            if ($controller !== null && ! class_exists($controller)) {
                $missing[] = $route->methods()[0].' /'.$route->uri().' -> '.$controller;
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($missing)),
            'Route berikut menunjuk controller yang tidak ada.',
        );
    }

    /**
     * Endpoint HRD yang controller-nya masih stub membalas 501 (bukan 500),
     * dan otorisasi tetap jujur: hanya super-admin yang sampai ke controller.
     */
    public function test_endpoint_hrd_belum_diimplementasikan_membalas_501(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->syncRoles(['super-admin']);
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/hrd/employees')
            ->assertStatus(501)
            ->assertJsonPath('data', null);
    }
}
