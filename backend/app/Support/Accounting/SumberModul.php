<?php

namespace App\Support\Accounting;

use App\Models\FinancialTransaction;

/**
 * Daftar sah `sumber_modul` — satu sumber kebenaran untuk asal-usul jurnal.
 *
 * Sebelumnya asal-usul jurnal hanya tersirat dari `reference_type` yang bebas
 * diisi siapa saja, sehingga "jurnal ini lahir dari modul apa?" tidak dapat
 * dijawab dengan pasti dan pemetaan unit bawaan per modul tidak punya tempat
 * berpijak. Daftar ini menutupnya: nilai di luar daftar ditolak saat posting.
 *
 * Modul baru WAJIB ditambahkan di sini sebelum memanggil engine, dan sekalian
 * dipetakan ke tipe transaksi lamanya di `type()` supaya laporan lama tetap
 * membaca angka yang sama.
 */
final class SumberModul
{
    public const JURNAL_UMUM = 'JurnalUmum';
    public const KAS_MASUK = 'KasMasuk';
    public const KAS_KELUAR = 'KasKeluar';
    public const INVOICE = 'Invoice';
    public const ACCRUE = 'Accrue';
    public const DEPRESIASI = 'Depresiasi';
    public const PENYELESAIAN_UANG_MUKA = 'PenyelesaianUangMuka';
    public const UANG_MUKA_OPERASIONAL = 'UangMukaOperasional';
    public const SALDO_AWAL = 'SaldoAwal';
    public const TUTUP_BUKU = 'TutupBuku';
    public const REKONSILIASI_BANK = 'RekonsiliasiBank';
    public const PINJAMAN_BANK = 'PinjamanBank';
    public const PINDAH_BUKU = 'PindahBuku';
    public const PENGAKUAN_PENDAPATAN = 'PengakuanPendapatan';
    public const PENGAJUAN_PEMBAYARAN = 'PengajuanPembayaran';
    public const PEMBAYARAN_SANTRI = 'PembayaranSantri';
    public const MUTASI_DOMPET = 'MutasiDompet';
    public const TAGIHAN_SPP = 'TagihanSpp';
    public const TAGIHAN_LAIN = 'TagihanLain';
    public const KOREKSI_TAGIHAN = 'KoreksiTagihan';
    public const PERSEDIAAN = 'Persediaan';
    public const PELEPASAN_ASET = 'PelepasanAset';
    public const PPDB = 'Ppdb';
    public const DENDA_PERPUSTAKAAN = 'DendaPerpustakaan';
    public const BIAYA_KLINIK = 'BiayaKlinik';
    public const PENGGAJIAN = 'Penggajian';
    public const BEBAN_OPERASIONAL = 'BebanOperasional';
    public const TRANSFER_KAS_BANK = 'TransferKasBank';
    public const WRITE_OFF = 'WriteOff';

    public const ALL = [
        self::JURNAL_UMUM,
        self::KAS_MASUK,
        self::KAS_KELUAR,
        self::INVOICE,
        self::ACCRUE,
        self::DEPRESIASI,
        self::PENYELESAIAN_UANG_MUKA,
        self::UANG_MUKA_OPERASIONAL,
        self::SALDO_AWAL,
        self::TUTUP_BUKU,
        self::REKONSILIASI_BANK,
        self::PINJAMAN_BANK,
        self::PINDAH_BUKU,
        self::PENGAKUAN_PENDAPATAN,
        self::PENGAJUAN_PEMBAYARAN,
        self::PEMBAYARAN_SANTRI,
        self::MUTASI_DOMPET,
        self::TAGIHAN_SPP,
        self::TAGIHAN_LAIN,
        self::KOREKSI_TAGIHAN,
        self::PERSEDIAAN,
        self::PELEPASAN_ASET,
        self::PPDB,
        self::DENDA_PERPUSTAKAAN,
        self::BIAYA_KLINIK,
        self::PENGGAJIAN,
        self::BEBAN_OPERASIONAL,
        self::TRANSFER_KAS_BANK,
        self::WRITE_OFF,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::JURNAL_UMUM => 'Jurnal Umum',
        self::KAS_MASUK => 'Kas Masuk',
        self::KAS_KELUAR => 'Kas Keluar',
        self::INVOICE => 'Invoice / Tagihan',
        self::ACCRUE => 'Akrual',
        self::DEPRESIASI => 'Depresiasi Aset',
        self::PENYELESAIAN_UANG_MUKA => 'Penyelesaian Uang Muka',
        self::UANG_MUKA_OPERASIONAL => 'Uang Muka Operasional',
        self::SALDO_AWAL => 'Saldo Awal',
        self::TUTUP_BUKU => 'Tutup Buku',
        self::REKONSILIASI_BANK => 'Rekonsiliasi Bank',
        self::PINJAMAN_BANK => 'Pinjaman Bank',
        self::PINDAH_BUKU => 'Pindah Buku',
        self::PENGAKUAN_PENDAPATAN => 'Pengakuan Pendapatan',
        self::PENGAJUAN_PEMBAYARAN => 'Pengajuan Pembayaran',
        self::PEMBAYARAN_SANTRI => 'Pembayaran Santri',
        self::MUTASI_DOMPET => 'Mutasi Dompet Santri',
        self::TAGIHAN_SPP => 'Tagihan SPP',
        self::TAGIHAN_LAIN => 'Tagihan Lain-lain',
        self::KOREKSI_TAGIHAN => 'Koreksi Tagihan',
        self::PERSEDIAAN => 'Persediaan',
        self::PELEPASAN_ASET => 'Pelepasan Aset',
        self::PPDB => 'PPDB',
        self::DENDA_PERPUSTAKAAN => 'Denda Perpustakaan',
        self::BIAYA_KLINIK => 'Biaya Klinik/UKS',
        self::PENGGAJIAN => 'Penggajian',
        self::BEBAN_OPERASIONAL => 'Beban Operasional',
        self::TRANSFER_KAS_BANK => 'Transfer Kas/Bank',
        self::WRITE_OFF => 'Penghapusan Piutang',
    ];

    /** Pemetaan ke tipe transaksi lama, agar laporan & filter lama tetap bekerja. */
    public static function type(string $sumberModul): string
    {
        return match ($sumberModul) {
            self::INVOICE, self::TAGIHAN_SPP, self::TAGIHAN_LAIN, self::PPDB, self::DENDA_PERPUSTAKAAN,
            self::BIAYA_KLINIK, self::PENGAKUAN_PENDAPATAN => FinancialTransaction::TYPE_INVOICE,

            self::KAS_MASUK, self::PEMBAYARAN_SANTRI, self::MUTASI_DOMPET, self::PENYELESAIAN_UANG_MUKA => FinancialTransaction::TYPE_PAYMENT,

            self::KAS_KELUAR, self::BEBAN_OPERASIONAL, self::PENGGAJIAN, self::PENGAJUAN_PEMBAYARAN,
            self::UANG_MUKA_OPERASIONAL, self::PERSEDIAAN => FinancialTransaction::TYPE_EXPENSE,

            self::PINJAMAN_BANK, self::ACCRUE => FinancialTransaction::TYPE_PAYABLE,

            self::TRANSFER_KAS_BANK, self::PINDAH_BUKU => FinancialTransaction::TYPE_TRANSFER,

            self::SALDO_AWAL => FinancialTransaction::TYPE_OPENING,
            self::TUTUP_BUKU => FinancialTransaction::TYPE_OPENING,
            self::WRITE_OFF => FinancialTransaction::TYPE_WRITE_OFF,

            default => FinancialTransaction::TYPE_ADJUSTMENT,
        };
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function label(string $value): string
    {
        return self::LABELS[$value] ?? $value;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $value): array => ['value' => $value, 'label' => self::label($value)],
            self::ALL,
        );
    }
}
