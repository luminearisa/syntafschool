<?php

namespace App\Services\Library\Documents;

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Picqer\Barcode\BarcodeGeneratorPNG;

/**
 * Pembuat gambar QR & barcode.
 *
 * - QR memakai `bacon/bacon-qr-code` (renderer GD) — dipakai untuk kartu
 *   anggota dan label eksemplar, memakai token/nilai yang dibuat server.
 * - Barcode memakai `picqer/php-barcode-generator` (Code 128) agar label
 *   eksemplar benar-benar dapat dipindai.
 *
 * Keluarannya berupa PNG (biner) dan data-URI agar dapat langsung disematkan
 * ke dokumen dompdf maupun dikirim sebagai respons gambar.
 */
class CodeImageService
{
    public function __construct(protected BarcodeGeneratorPNG $barcodes = new BarcodeGeneratorPNG())
    {
    }

    /**
     * QR dalam bentuk PNG biner.
     */
    public function qrPng(string $data, int $size = 320, int $margin = 2): string
    {
        $renderer = new GDLibRenderer($size, $margin);
        $writer = new Writer($renderer);

        return $writer->writeString($data);
    }

    /**
     * QR sebagai data-URI (siap dipakai pada `<img src="…">`).
     */
    public function qrDataUri(string $data, int $size = 320, int $margin = 2): string
    {
        return 'data:image/png;base64,'.base64_encode($this->qrPng($data, $size, $margin));
    }

    /**
     * Barcode Code 128 dalam bentuk PNG biner, atau null bila nilai kosong.
     */
    public function barcodePng(?string $code, int $widthFactor = 2, int $height = 50): ?string
    {
        if (blank($code)) {
            return null;
        }

        return $this->barcodes->getBarcode($code, BarcodeGeneratorPNG::TYPE_CODE_128, $widthFactor, $height);
    }

    /**
     * Barcode sebagai data-URI, atau null bila nilai kosong.
     */
    public function barcodeDataUri(?string $code, int $widthFactor = 2, int $height = 50): ?string
    {
        $png = $this->barcodePng($code, $widthFactor, $height);

        return $png === null ? null : 'data:image/png;base64,'.base64_encode($png);
    }
}