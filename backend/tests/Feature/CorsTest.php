<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * CORS untuk dev server.
 *
 * Regresi yang dijaga: Vite memilih port lain (5174, 5175, ...) begitu 5173
 * sedang dipakai. Daftar origin eksplisit membuat preflight gagal dengan
 * "No 'Access-Control-Allow-Origin' header", sehingga seluruh aplikasi tidak
 * bisa memanggil API sama sekali.
 */
class CorsTest extends TestCase
{
    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
    }

    public function test_preflight_from_any_localhost_port_is_allowed(): void
    {
        foreach (['http://localhost:5173', 'http://localhost:5175', 'http://localhost:6000'] as $origin) {
            $response = $this->preflight($origin);

            $response->assertNoContent(204);
            $this->assertSame(
                $origin,
                $response->headers->get('Access-Control-Allow-Origin'),
                "Origin {$origin} seharusnya diizinkan.",
            );
        }
    }

    public function test_preflight_from_any_loopback_ip_port_is_allowed(): void
    {
        $response = $this->preflight('http://127.0.0.1:3000');

        $response->assertNoContent(204);
        $this->assertSame('http://127.0.0.1:3000', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_preflight_from_foreign_origin_is_rejected(): void
    {
        $response = $this->preflight('https://situs-lain.example.com');

        $this->assertNull(
            $response->headers->get('Access-Control-Allow-Origin'),
            'Origin asing tidak boleh mendapat header CORS.',
        );
    }

    public function test_host_masquerading_as_localhost_is_rejected(): void
    {
        // Pola harus di-anchor, kalau tidak "localhost.evil.com" ikut lolos.
        foreach (['http://localhost.evil.com', 'http://127.0.0.1.evil.com'] as $origin) {
            $response = $this->preflight($origin);

            $this->assertNull(
                $response->headers->get('Access-Control-Allow-Origin'),
                "Origin {$origin} tidak boleh diizinkan.",
            );
        }
    }

    public function test_actual_request_also_carries_cors_header(): void
    {
        $response = $this->call('POST', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5175',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['email' => 'bukan@ada.test', 'password' => 'salah']));

        $this->assertSame(
            'http://localhost:5175',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
