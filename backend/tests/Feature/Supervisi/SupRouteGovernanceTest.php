<?php

namespace Tests\Feature\Supervisi;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Fase 5 — tata kelola rute (uji struktural).
 *
 * Temuan audit: 122 rute modul tidak memiliki batas laju sama sekali, dan tidak
 * ada cara cepat untuk mengetahui rute mana yang kehilangan penjaga izin saat
 * modul tumbuh. Tes ini menegakkan aturan tersebut untuk **seluruh** rute
 * supervisi sekaligus, sehingga penambahan rute baru yang lupa dijaga akan
 * langsung terlihat.
 *
 * Aturan yang ditegakkan:
 *  1. Setiap rute supervisi wajib memiliki middleware izin Spatie
 *     (`PermissionMiddleware`) — kecuali endpoint verifikasi dokumen publik.
 *  2. Setiap rute wajib memiliki batas laju (`ThrottleRequests`).
 *  3. Endpoint publik wajib punya batas laju yang lebih ketat dan **tidak**
 *     boleh memakai middleware izin.
 *  4. Handler endpoint harus berada di dalam Modul Supervisi.
 */
class SupRouteGovernanceTest extends SupTestCase
{
    /** @return list<RoutingRoute> */
    protected function supervisiRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with(ltrim($route->uri(), '/'), ltrim('api/v1/supervisi', '/')))
            ->values()
            ->all();
    }

    /**
     * Middleware rute dalam bentuk yang seragam.
     *
     * `gatherMiddleware()` mengembalikan alias yang ditulis di berkas rute
     * (`auth:sanctum`, `throttle:…`, `permission:…`), sedangkan `route:list`
     * menampilkan nama kelasnya. Di sini alias diperluas agar pemeriksaan tidak
     * bergantung pada cara middleware dideklarasikan.
     *
     * @param  RoutingRoute  $route
     * @return list<string>
     */
    protected function middlewareOf(RoutingRoute $route): array
    {
        return collect($route->gatherMiddleware())
            ->map(function ($middleware): string {
                $name = is_string($middleware) ? $middleware : (string) $middleware;

                return match (true) {
                    str_starts_with($name, 'auth:') => 'Authenticate:'.substr($name, 5),
                    str_starts_with($name, 'throttle:') => 'ThrottleRequests:'.substr($name, 9),
                    str_starts_with($name, 'permission:') => 'PermissionMiddleware:'.substr($name, 11),
                    default => $name,
                };
            })
            ->values()
            ->all();
    }

    public function test_setiap_rute_wajib_dijaga_izin_dan_batas_laju(): void
    {
        $tanpaIzin = [];
        $tanpaBatas = [];
        $diLuarModul = [];

        foreach ($this->supervisiRoutes() as $route) {
            $middlewares = $this->middlewareOf($route);
            $publik = str_contains($route->uri(), 'reports/verify');

            $punyaIzin = collect($middlewares)->contains(fn (string $m) => str_contains($m, 'PermissionMiddleware:'));
            $punyaBatas = collect($middlewares)->contains(fn (string $m) => str_contains($m, 'ThrottleRequests:'));

            if (! $punyaIzin && ! $publik) {
                $tanpaIzin[] = $route->uri();
            }

            if (! $punyaBatas) {
                $tanpaBatas[] = $route->uri();
            }

            if (! str_starts_with((string) $route->getActionName(), 'App\\Modules\\Supervisi')) {
                $diLuarModul[] = $route->uri();
            }
        }

        $this->assertSame([], $tanpaIzin, 'Rute tanpa izin: '.implode(', ', $tanpaIzin));
        $this->assertSame([], $tanpaBatas, 'Rute tanpa batas laju: '.implode(', ', $tanpaBatas));
        $this->assertSame([], $diLuarModul, 'Handler di luar modul: '.implode(', ', $diLuarModul));
        $this->assertGreaterThan(120, count($this->supervisiRoutes()));
    }

    public function test_endpoint_publik_hanya_verifikasi_dokumen_dan_dibatasi_ketat(): void
    {
        $publik = collect($this->supervisiRoutes())
            ->filter(fn (RoutingRoute $route) => ! collect($this->middlewareOf($route))->contains(fn (string $m) => str_contains($m, 'Authenticate')))
            ->values();

        $this->assertCount(1, $publik, 'Hanya satu endpoint supervisi yang boleh publik.');
        $this->assertStringContainsString('reports/verify', $publik[0]->uri());

        $middlewares = implode(' ', $this->middlewareOf($publik[0]));

        $this->assertStringContainsString('ThrottleRequests:supervisi-public-verify', $middlewares);
        $this->assertStringNotContainsString('PermissionMiddleware', $middlewares);
    }

    public function test_endpoint_publik_tidak_membocorkan_isi_laporan(): void
    {
        $balasan = $this->getJson('/api/v1/supervisi/reports/verify/TIDAKDIKENAL')->assertStatus(404)->json('data');

        // Kunci yang boleh muncul hanya keterangan dokumen.
        $diizinkan = ['valid', 'code', 'title', 'report_key', 'format', 'rows_count', 'generated_at', 'checksum', 'checksum_matches', 'status', 'message', 'file_available'];

        foreach (array_keys($balasan) as $kunci) {
            $this->assertContains($kunci, $diizinkan, "Kunci '{$kunci}' tidak seharusnya diungkap endpoint publik.");
        }
    }

    public function test_operasi_berat_memakai_batas_laju_khusus(): void
    {
        $khusus = [
            'api/v1/supervisi/analytics' => 'supervisi-analytics',
            'api/v1/supervisi/health' => 'supervisi-analytics',
            'api/v1/supervisi/reports' => 'supervisi-report-generate',
            'api/v1/supervisi/visits/{visit}/answers' => 'supervisi-answer-save',
            'api/v1/supervisi/visits/{visit}/answers/sync' => 'supervisi-answer-save',
            'api/v1/supervisi/visits/{visit}/finalize' => 'supervisi-visit-finalize',
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! isset($khusus[$uri])) {
                continue;
            }

            $middlewares = implode(' ', $this->middlewareOf($route));
            $this->assertStringContainsString('ThrottleRequests:'.$khusus[$uri], $middlewares, "Rute {$uri} kehilangan batas laju khusus.");
            unset($khusus[$uri]);
        }

        $this->assertSame([], array_keys($khusus), 'Rute berat yang tidak ditemukan: '.implode(', ', array_keys($khusus)));
    }
}
