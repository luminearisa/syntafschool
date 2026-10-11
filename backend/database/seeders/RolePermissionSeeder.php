<?php

namespace Database\Seeders;

use App\Services\Library\MasterData\LibraryMasterRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    /**
     * Modul yang tersedia dan aksi CRUD-nya.
     *
     * @var array<string, list<string>>
     */
    protected array $modules = [
        'master-data' => ['view', 'create', 'update', 'delete', 'import', 'export'],
        'academic' => ['view', 'create', 'update', 'delete', 'publish', 'approve', 'promote', 'grade', 'attendance', 'portal'],
        'student' => ['view', 'create', 'update', 'delete'],
        'employee' => ['view', 'create', 'update', 'delete'],
        // Modul Finance/Keuangan: akuntansi double-entry + COA, tagihan/SPP,
        // pembayaran, kas & bank, pengeluaran, pembelian, rekonsiliasi, closing.
        // Aksi bersarang menghasilkan nama berjenjang, mis. "finance.billing.create".
        //
        // Hak dipecah granular (§3) supaya "admin keuangan" tidak otomatis berarti
        // boleh menyetujui budget, membayar pengeluaran, dan menutup periode
        // sekaligus — pemisahan pencatat vs penyetuju dijaga di level permission.
        // Pembagian per role lihat `grantFinancePermissions()`.
        'finance' => [
            'view',
            // Master akuntansi & anggaran.
            'account.view', 'account.manage',
            'cost_center.view', 'cost_center.manage',
            'budget.view', 'budget.manage', 'budget.approve',
            'fee.view', 'fee.manage',
            // Tagihan & penagihan (§14 idempotent, §21 approval).
            'billing.view', 'billing.create', 'billing.update', 'billing.cancel',
            'invoice.view', 'invoice.create', 'invoice.cancel',
            // Uang masuk (§24 receipt hanya untuk payment confirmed, §25 no hard delete).
            'payment.view', 'payment.create', 'payment.verify', 'payment.reject', 'payment.refund',
            'receipt.view', 'receipt.create', 'receipt.cancel',
            // Rencana pembayaran / cicilan (§7). Memecah jatuh tempo sebuah
            // invoice bukan keputusan yang boleh diambil siapa pun yang bisa
            // mencatat pembayaran: `manage` menyusun jadwalnya, `cancel`
            // membubarkan kesepakatan yang sudah berjalan. Keduanya dipisah
            // dari payment.* supaya plan tidak menjadi jalan pintas melunasi
            // tagihan — yang tetap hanya bisa terjadi lewat payment + jurnal.
            'payment.plan.view', 'payment.plan.manage', 'payment.plan.cancel',
            // Daftar Ulang (§2–§7 Phase 2) + gateway (§20, §36 Phase 2).
            're_registration.view', 're_registration.generate', 're_registration.manage',
            'gateway.view', 'gateway.manage',
            // Uang keluar & pengadaan.
            'expense.view', 'expense.create', 'expense.approve', 'expense.reject', 'expense.pay',
            'vendor.view', 'vendor.manage',
            'purchase.view', 'purchase.create', 'purchase.approve',
            // Kas, bank, mutasi internal.
            'cash.view', 'cash.manage', 'cashier.view', 'cashier.manage', 'cashier.review',
            'bank.view', 'bank.manage',
            'transfer.view', 'transfer.create', 'transfer.approve',
            // §16: transfer di atas ambang nominal (`transfer_approval_threshold`)
            // baru bisa dieksekusi setelah penyetuju KEDUA yang bukan pengaju dan
            // bukan penyetuju pertama membubuhkan persetujuannya. Hak ini sengaja
            // tidak masuk daftar finance-manager — pemegangnya otoritas yang lebih
            // tinggi (bendahara / admin), sehingga persetujuan kedua benar-benar
            // orang ketiga.
            'transfer.approve_secondary',
            'reconciliation.view', 'reconciliation.manage',
            'adjustment.view', 'adjustment.create', 'adjustment.approve',
            // Buku besar & jurnal (Phase 3 §3–§6, §33, §38). Membaca jurnal
            // dipisah dari membaliknya: pembalikan jurnal posted adalah
            // keputusan supervisor, bukan operator pencatat.
            'transaction.view', 'journal.view', 'journal.reverse', 'ledger.view',
            // Write-off piutang (§33): peminta dan penyetuju tidak boleh orang sama.
            'write_off.request', 'write_off.approve',
            // Penagihan (§6): melihat antrean, mencatat aksi, dan naik ke tangga
            // eskalasi adalah tiga hal berbeda. Reminder boleh dicatat petugas;
            // telepon, rencana pembayaran, waiver, dan write-off request menuntut
            // hak eskalasi sendiri, supaya "sudah ditagih sampai tingkat mana"
            // tidak bisa dinaikkan oleh orang yang sama setiap hari.
            'collection.view', 'collection.manage', 'collection.escalate',
            // Kelayakan finansial (§11). Hanya dua hak, dan keduanya baca/putus:
            // `view` menjawab "santri ini boleh ikut ujian/ambil rapor?",
            // `approve` adalah penyetuju yang disebut sebuah putusan
            // REQUIRES_APPROVAL. Yang TIDAK ada di sini: hak mengubah status
            // akademik — gerbangnya milik modul akademik, Finance hanya menjawab
            // pertanyaan, jadi tidak ada jalur HTTP yang bisa menahan rapor dari
            // meja bendahara.
            'eligibility.view', 'eligibility.approve',
            // Keberesan finansial (§12). Empat hak, dan pembagiannya mengikuti
            // bentuk pekerjaannya: `view` membaca daftar periksa, `request`
            // mengajukan/mengisi ulang pemeriksaan untuk satu santri (ia tidak
            // memutuskan apa pun), `approve` menjawab REQUIRES_APPROVAL, dan
            // `waive` adalah pengecualian sadar atas aturan yang menahan.
            // Yang TIDAK ada: hak menghapus piutang — WAIVED bukan write-off dan
            // jalurnya tetap `write_off.*` (§33), jadi tidak satu pun keputusan
            // keberesan yang bisa mengubah buku besar.
            'clearance.view', 'clearance.request', 'clearance.approve', 'clearance.waive',
            // Penutupan periode (§49 setelah closed tidak boleh diedit).
            'closing.view', 'closing.manage',
            // Persetujuan yang tidak boleh dilakukan orang yang sama dengan pengaju:
            'closing.approve', 'journal.approve',
            // Aset tetap, penyusutan, akrual, dan persediaan bermuara jurnal.
            'asset.view', 'asset.manage', 'depreciation.view', 'depreciation.post',
            'accrual.view', 'accrual.manage', 'inventory.post',
            // Laporan, konfigurasi.
            'report.view', 'report.export',
            // Otomasi & observability (§28, §29). Membaca register pekerjaan/kegagalan
            // adalah hak baca tersendiri — isinya menyebut dokumen dan kesalahan
            // sistem, bukan angka yang boleh dilihat siapa saja. Menyalakan pekerjaan
            // berat dan menjalankan ulang (`manage`) dipisah dari sekadar melihat,
            // karena keduanya bisa melahirkan dokumen baru.
            'operation.view', 'operation.manage',
            'settings.view', 'settings.manage',
            'audit.view',
        ],
        'assessment' => ['view', 'create', 'update', 'delete'],
        // Rapor memiliki lingkaran akses tersendiri karena siswa hanya boleh melihat nilainya sendiri.
        'report-card' => ['view', 'generate', 'update', 'approve', 'publish'],
        // Modul Library (Step 1 — fondasi). Aksi bersarang menghasilkan nama
        // berjenjang, mis. "library.settings.view" & "library.location.create".
        'library' => [
            'view', 'create', 'update', 'delete',
            'settings.view', 'settings.update',
            'location.view', 'location.create', 'location.update', 'location.delete',
            'operating_hours.view', 'operating_hours.update',
            'closure.view', 'closure.create', 'closure.update', 'closure.delete',
            'numbering.view', 'numbering.update',
            'dashboard.view',
            'audit.view',
            // Fase 3 — katalog, koleksi, keanggotaan, kartu, OPAC.
            'catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete',
            'item.view', 'item.create', 'item.update', 'item.delete',
            'member.view', 'member.create', 'member.update', 'member.delete',
            'membership.view', 'membership.create', 'membership.update', 'membership.delete',
            'member_card.view', 'member_card.generate',
            'opac.view', 'opac.manage',
            // Fase 4 — sirkulasi, denda, reservasi, pengadaan, inventaris.
            'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew', 'circulation.manage',
            'fine.view', 'fine.manage',
            'reservation.view', 'reservation.create', 'reservation.manage',
            'acquisition.view', 'acquisition.create', 'acquisition.manage',
            'inventory.view', 'inventory.manage',
            // Phase 5 — laporan & ekspor.
            'report.view', 'report.export',
        ],
        'inventory' => ['view', 'create', 'update', 'delete'],
        // Modul PPDB: konfigurasi penerimaan, pendaftaran, pembayaran, seleksi.
        // Aksi bersarang menghasilkan nama berjenjang, mis. "ppdb.payment.verify".
        'ppdb' => [
            'view', 'create', 'update', 'delete', 'verify',
            'period.manage', 'wave.manage', 'program.manage', 'form.manage',
            'question.manage', 'document.manage', 'fee.manage',
            'registration.view', 'registration.manage', 'registration.verify',
            // Menetapkan lulus di atas kuota adalah keputusan kepemimpinan, bukan
            // operasional panitia — sengaja tidak diberikan ke panitia-ppdb.
            'registration.override_quota',
            'payment.view', 'payment.verify',
            'selection.manage', 'announcement.manage', 'account.manage',
            'report.view', 'report.export',
        ],
        // Modul Wallet/E-Wallet: saldo santri, karyawan & orang tua. Aksi bersarang
        // menghasilkan nama berjenjang, mis. "wallet.topup.verify".
        //
        // Daftar ini sengaja selengkap spesifikasi §2: kemampuan mengelola uang
        // orang lain dipecah supaya "admin" tidak otomatis berarti boleh semua.
        // Lihat `grantWalletModulePermissions()` untuk pembagian per role.
        'wallet' => [
            'view', 'create', 'update',
            'block', 'unblock', 'close',
            'rfid.view', 'rfid.manage',
            'topup.view', 'topup.create', 'topup.verify', 'topup.reject',
            'withdrawal.view', 'withdrawal.create', 'withdrawal.approve',
            'withdrawal.reject', 'withdrawal.complete',
            'ledger.view',
            // Pembuat adjustment bukan penyetuju adjustment (§20).
            'adjustment.view', 'adjustment.create', 'adjustment.approve',
            'reversal.create',
            'report.view', 'report.export',
            'settings.view', 'settings.manage',
        ],
        // Modul Boarding/Pesantren: struktur asrama, kegiatan harian, absensi,
        // perizinan, kedisiplinan, kesehatan, dan kunjungan. Aksi bersarang
        // menghasilkan nama berjenjang, mis. "boarding.permission.approve".
        //
        // Daftar ini selengkap mungkin karena pembeda hak pengasuh bukan di
        // menu — ia menentukan santri siapa yang boleh dilihat (`BoardingScopeService`).
        'boarding' => [
            'view', 'create', 'update', 'delete',
            'dashboard.view',
            // Struktur fisik (§5): asrama, gedung, lantai, kamar, tempat tidur.
            'dorm.manage', 'building.manage', 'floor.manage', 'room.manage', 'bed.manage',
            // Penempatan & mutasi santri (§7, §9, §11).
            'placement.view', 'placement.manage', 'mutation.manage',
            // Pengasuh & kelompok binaan (§8).
            'musyrif.view', 'musyrif.manage', 'group.view', 'group.manage',
            // Kegiatan & jadwal (§16, §19).
            'activity.view', 'activity.manage', 'schedule.view', 'schedule.manage',
            // Absensi (§17, §24, §26).
            'attendance.view', 'attendance.manage',
            // Izin keluar (§27, §28, §29) — pencatat bukan penyetuju.
            'permission.view', 'permission.manage', 'permission.approve',
            // Kepulangan (§30, §31).
            'homecoming.view', 'homecoming.manage', 'homecoming.approve',
            // Kunjungan (§40).
            'visitor.view', 'visitor.manage',
            // Kedisiplinan & poin (§35, §36, §37, §38).
            'violation.view', 'violation.manage', 'point.view', 'point.manage',
            // Prestasi (§41).
            'achievement.view', 'achievement.manage',
            // Kesehatan asrama (§39).
            'health.view', 'health.manage',
            // Titipan barang santri (gudang titipan, pinjam-kembali).
            'deposit.view', 'deposit.manage',
            // Laporan, konfigurasi, audit.
            'report.view', 'report.export', 'settings.view', 'settings.manage', 'audit.view',
        ],
        'report' => ['view', 'export'],
        'user' => ['view', 'create', 'update', 'delete'],
        'setting' => ['view', 'update'],
        // Modul Tahfiz: setoran adalah pencatatan inti, approve untuk
        // persetujuan pendaftaran & penerbitan sertifikat.
        'tahfiz' => ['view', 'create', 'update', 'delete', 'setoran', 'approve', 'assess', 'report'],
    ];

    /** @var array<string, list<string>> */
    protected array $roles = [
        'super-admin' => ['*'],
        'admin-sekolah' => ['master-data', 'academic', 'student', 'employee', 'finance', 'assessment', 'report-card', 'library', 'inventory', 'ppdb', 'wallet', 'boarding', 'report', 'user', 'setting', 'tahfiz'],
        // Kepala sekolah hanya memantau uang: lihat daftar, buku besar, dan laporan.
        // Satu-satunya hak menulisnya di modul finance adalah persetujuan
        // kelayakan finansial (§11) dan keputusan keberesan (§12): ia sudah
        // memegang `academic:approve`, jadi keputusan "anak ini boleh ikut ujian
        // meski orang tuanya belum lunas" dan "ijazahnya boleh keluar dulu"
        // diambil oleh orang yang sama dengan keputusan akademiknya — bukan oleh
        // bendahara, dan bukan oleh frontend.
        'kepala-sekolah' => ['report', 'master-data:view', 'academic:view', 'assessment:view', 'academic:approve', 'academic:publish', 'academic:promote', 'report-card:view', 'report-card:approve', 'report-card:publish', 'student:view', 'employee:view', 'finance:view', 'finance:eligibility.view', 'finance:eligibility.approve', 'finance:clearance.view', 'finance:clearance.approve', 'finance:clearance.waive', 'tahfiz:view', 'tahfiz:report', 'library:view', 'library:dashboard.view', 'wallet:view', 'wallet:ledger.view', 'wallet:report.view', 'wallet:report.export', 'boarding:view', 'boarding:dashboard.view', 'boarding:report.view'],
        // Bendahara = pengelola keuangan: top up, penarikan, adjustment, reversal.
        // Sengaja tidak menyebut modul `wallet` secara utuh — konfigurasi modul
        // (settings.*) dan penutupan wallet permanen (wallet.close) hak admin.
        'bendahara' => [
            'finance', 'report:view',
            'wallet:view', 'wallet:create', 'wallet:update', 'wallet:block', 'wallet:unblock',
            'wallet:rfid.view', 'wallet:rfid.manage',
            'wallet:topup.view', 'wallet:topup.create', 'wallet:topup.verify', 'wallet:topup.reject',
            'wallet:withdrawal.view', 'wallet:withdrawal.create', 'wallet:withdrawal.approve',
            'wallet:withdrawal.reject', 'wallet:withdrawal.complete',
            'wallet:ledger.view',
            'wallet:adjustment.view', 'wallet:adjustment.create', 'wallet:adjustment.approve',
            'wallet:reversal.create',
            'wallet:report.view', 'wallet:report.export',
        ],
        // Petugas wallet: operasional harian kartu & verifikasi setoran.
        // Tanpa hak adjustment dan tanpa hak menyetujui penarikan (§2).
        'petugas-wallet' => [
            'wallet:view', 'wallet:rfid.view', 'wallet:rfid.manage',
            'wallet:topup.view', 'wallet:topup.verify', 'wallet:topup.reject',
            'wallet:withdrawal.view', 'wallet:ledger.view',
        ],
        // Guru Tahfiz: lihat, catat setoran, dan menilai. Tidak menghapus data.
        'guru' => ['academic:view', 'academic:grade', 'academic:attendance', 'student:view', 'assessment', 'report-card:view', 'report-card:generate', 'report-card:update', 'report:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess', 'master-data:view', 'employee:view'],
        // Musyrif/musyrifah: memantau seluruh halaqah (view + laporan) DAN seluruh
        // hak wali kamar asrama, ditambah wewenang yang hanya boleh dipakai
        // pengasuh tetap: menyetujui izin/kepulangan, mencatat prestasi, dan
        // melihat daftar penugasan serta kelompok binaannya sendiri.
        'musyrif' => [
            'tahfiz:view', 'tahfiz:report', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess',
            'boarding:view', 'boarding:dashboard.view', 'boarding:placement.view',
            'boarding:attendance.view', 'boarding:attendance.manage',
            'boarding:permission.view', 'boarding:permission.approve',
            'boarding:violation.view', 'boarding:violation.manage',
            'boarding:homecoming.view', 'boarding:homecoming.approve',
            'boarding:health.view', 'boarding:point.view', 'boarding:achievement.manage',
            'boarding:musyrif.view', 'boarding:group.view',
            'boarding:deposit.view', 'boarding:deposit.manage',
        ],
        // ===== Peran khusus modul Boarding/Pesantren =====
        // Admin asrama: seluruh aksi modul (dorm, kamar, penempatan, mutasi,
        // absensi, perizinan, poin, kesehatan, laporan, konfigurasi, audit).
        'admin-boarding' => ['boarding'],
        // Kepala asrama: seluruh operasional area, kecuali menulis konfigurasi
        // modul dan membaca audit (`settings.manage`, `audit.view`) yang tetap
        // hak admin sekolah.
        'kepala-asrama' => [
            'boarding:view', 'boarding:dashboard.view',
            'boarding:placement.view', 'boarding:placement.manage', 'boarding:mutation.manage',
            'boarding:musyrif.view', 'boarding:musyrif.manage', 'boarding:group.view', 'boarding:group.manage',
            'boarding:activity.view', 'boarding:activity.manage', 'boarding:schedule.view', 'boarding:schedule.manage',
            'boarding:attendance.view', 'boarding:attendance.manage',
            'boarding:permission.view', 'boarding:permission.manage', 'boarding:permission.approve',
            'boarding:homecoming.view', 'boarding:homecoming.manage', 'boarding:homecoming.approve',
            'boarding:visitor.view', 'boarding:visitor.manage',
            'boarding:violation.view', 'boarding:violation.manage', 'boarding:point.view', 'boarding:point.manage',
            'boarding:achievement.view', 'boarding:achievement.manage',
            'boarding:health.view', 'boarding:health.manage',
            'boarding:deposit.view', 'boarding:deposit.manage',
            'boarding:report.view', 'boarding:report.export', 'boarding:settings.view',
        ],
        // Wali kamar: hanya melihat & mencatat pada santri binaannya (scope
        // dibatasi `BoardingScopeService`, bukan oleh permission).
        'wali-kamar' => [
            'boarding:view', 'boarding:dashboard.view', 'boarding:placement.view',
            'boarding:attendance.view', 'boarding:attendance.manage',
            'boarding:permission.view',
            'boarding:violation.view', 'boarding:violation.manage',
            'boarding:health.view', 'boarding:point.view',
            'boarding:deposit.view', 'boarding:deposit.manage',
        ],
        // Petugas absensi: mesin pencatat harian, tanpa hak perizinan & poin.
        'petugas-absensi' => [
            'boarding:view', 'boarding:attendance.view', 'boarding:attendance.manage',
            'boarding:activity.view', 'boarding:schedule.view',
        ],
        // Petugas kedisiplinan: pelanggaran, poin, prestasi; membaca absensi
        // hanya sebagai bukti (tanpa hak mengubahnya).
        'petugas-kedisiplinan' => [
            'boarding:view', 'boarding:violation.view', 'boarding:violation.manage',
            'boarding:point.view', 'boarding:point.manage',
            'boarding:achievement.view', 'boarding:attendance.view',
        ],
        // Petugas gerbang/perizinan: memproses izin, kepulangan, dan kunjungan.
        'petugas-perizinan' => [
            'boarding:view',
            'boarding:permission.view', 'boarding:permission.manage', 'boarding:permission.approve',
            'boarding:homecoming.view', 'boarding:homecoming.manage', 'boarding:homecoming.approve',
            'boarding:visitor.view', 'boarding:visitor.manage',
        ],
        // Petugas kesehatan (UKS asrama): catatan kesehatan & kunjungan pasien.
        'petugas-kesehatan' => [
            'boarding:view', 'boarding:health.view', 'boarding:health.manage',
            'boarding:visitor.view', 'boarding:permission.view',
        ],
        'wali-kelas' => ['academic:view', 'academic:grade', 'academic:attendance', 'academic:approve', 'student:view', 'assessment', 'report-card:view', 'report-card:generate', 'report-card:update', 'report:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:setoran', 'tahfiz:assess', 'master-data:view', 'employee:view'],
        'staf' => ['master-data:view', 'student:view', 'employee:view', 'tahfiz:view', 'tahfiz:create', 'tahfiz:update'],
        // Peserta Tahfiz (termasuk siswa yang mengikuti program): lihat progress sendiri.
        // `siswa` dan `wali-murid` sengaja TIDAK diberi permission boarding apa
        // pun: portal mereka dibatasi kepemilikan (dirinya / anak walinya), bukan
        // oleh permission modul, jadi menambah aksi di sini justru memberi akses
        // ke data santri lain.
        'siswa' => ['academic:portal', 'report:view', 'tahfiz:view'],
        // `wali-murid` sengaja TIDAK diberi permission finance: portal keuangan
        // orang tua (§57) dibatasi kepemilikan (tagihan anak walinya), bukan oleh
        // permission modul — sama seperti portal boarding. Memberi `finance:view`
        // di sini justru membuka menu/read data keuangan sekolah secara admin.
        'wali-murid' => ['academic:portal', 'report:view', 'tahfiz:view'],
        // Library Manager: mengelola konfigurasi operasional perpustakaan
        // (settings, lokasi, jam, penutupan, penomoran) — tanpa hak hapus.
        'library-manager' => [
            'library:view', 'library:create', 'library:update',
            'library:settings.view', 'library:settings.update',
            'library:location.view', 'library:location.create', 'library:location.update', 'library:location.delete',
            'library:operating_hours.view', 'library:operating_hours.update',
            'library:closure.view', 'library:closure.create', 'library:closure.update', 'library:closure.delete',
            'library:numbering.view', 'library:numbering.update',
            'library:dashboard.view', 'library:audit.view',
            'report:view',
        ],
        // Pustakawan: operasional harian (katalog & sirkulasi menyusul pada
        // Step berikutnya). Tidak mengubah konfigurasi maupun penomoran.
        'pustakawan' => [
            'library:view', 'library:create', 'library:update',
            'library:location.view', 'library:location.create', 'library:location.update',
            'library:operating_hours.view',
            'library:closure.view', 'library:closure.create', 'library:closure.update',
            'library:dashboard.view',
            'report:view',
        ],
        // ===== Peran khusus modul Finance/Keuangan =====
        // Admin keuangan: seluruh aksi modul finance (COA, budget, billing,
        // payment, kas/bank, expense, pembelian, rekonsiliasi, closing, settings).
        'finance-admin' => ['finance'],
        // Manajer keuangan = otoritas persetujuan (§3 pemisahan pencatat vs
        // penyetuju). Menyetujui budget/expense/transfer/adjustment/pembelian dan
        // menutup periode; tidak melakukan pencatatan kasir harian.
        'finance-manager' => [
            'finance:view',
            'finance:account.view', 'finance:cost_center.view',
            'finance:budget.view', 'finance:budget.approve',
            'finance:fee.view', 'finance:billing.view', 'finance:invoice.view',
            'finance:payment.view', 'finance:payment.verify', 'finance:payment.reject', 'finance:payment.refund',
            'finance:receipt.view', 'finance:receipt.cancel',
            // Cicilan (§7): memperpanjang jatuh tempo adalah keputusan kredit
            // kecil di atas tagihan yang sudah ada, jadi manager menyusun
            // sekaligus boleh membubarkan jadwalnya.
            'finance:payment.plan.view', 'finance:payment.plan.manage', 'finance:payment.plan.cancel',
            'finance:re_registration.view', 'finance:re_registration.manage',
            'finance:gateway.view', 'finance:gateway.manage',
            'finance:expense.view', 'finance:expense.approve', 'finance:expense.reject', 'finance:expense.pay',
            'finance:vendor.view', 'finance:purchase.view', 'finance:purchase.approve',
            'finance:cash.view', 'finance:cashier.view', 'finance:cashier.review', 'finance:bank.view',
            'finance:transfer.view', 'finance:transfer.approve',
            'finance:reconciliation.view', 'finance:reconciliation.manage',
            'finance:adjustment.view', 'finance:adjustment.approve',
            // Manager membaca buku besar dan membalik jurnal posted; write-off
            // dimintakan oleh officer dan disetujui di sini (§33).
            'finance:transaction.view', 'finance:journal.view', 'finance:journal.reverse',
            'finance:ledger.view', 'finance:write_off.approve',
            // Penagihan sampai tangga eskalasi (§6): keputusan menelepon orang
            // tua, menyusun rencana pembayaran, atau mengajukan write-off adalah
            // keputusan manajerial, jadi hak eskalasinya ada di sini.
            'finance:collection.view', 'finance:collection.manage', 'finance:collection.escalate',
            // Kelayakan finansial (§11): manager membaca putusannya dan sekaligus
            // penyetuju yang disebut sebuah REQUIRES_APPROVAL. Mencatat tagihan
            // tetap bukan tugasnya (§3).
            'finance:eligibility.view', 'finance:eligibility.approve',
            // Keberesan finansial (§12): manajer mengajukan pemeriksaan, dan ia
            // pemegang kedua keputusan yang mengubah jawaban — `approve` untuk
            // yang menunggu persetujuan, `waive` untuk pengecualian. Keduanya
            // tetap tidak menyentuh uang: hak `write_off.approve` di atas adalah
            // satu-satunya jalur yang bisa menghapus piutang.
            'finance:clearance.view', 'finance:clearance.request', 'finance:clearance.approve', 'finance:clearance.waive',
            'finance:closing.view', 'finance:closing.manage',
            'finance:report.view', 'finance:report.export',
            // Register pekerjaan terjadwal & kegagalan operasional (§28, §29):
            // manager membacanya dan menjalankan ulang job yang gagal, karena
            // retry adalah keputusan operasional — bukan sekadar melihat log.
            'finance:operation.view', 'finance:operation.manage',
            'finance:settings.view', 'finance:audit.view',
        ],
        // Staf keuangan: pencatatan operasional (billing, invoice, payment,
        // expense, pembelian, kas/bank, transfer, adjustment) TANPA hak approve
        // maupun closing — persetujuan ada di finance-manager (§3).
        'finance-officer' => [
            'finance:view',
            'finance:account.view', 'finance:cost_center.view', 'finance:budget.view',
            'finance:fee.view', 'finance:fee.manage',
            'finance:billing.view', 'finance:billing.create', 'finance:billing.update', 'finance:billing.cancel',
            'finance:invoice.view', 'finance:invoice.create', 'finance:invoice.cancel',
            'finance:payment.view', 'finance:payment.create',
            'finance:receipt.view', 'finance:receipt.create',
            // Menyusun jadwal cicilan boleh dari meja petugas (§7 manage);
            // membatalkan kesepakatan yang sedang berjalan tidak (§3).
            'finance:payment.plan.view', 'finance:payment.plan.manage',
            'finance:re_registration.view', 'finance:re_registration.generate',
            'finance:gateway.view',
            'finance:expense.view', 'finance:expense.create',
            'finance:vendor.view', 'finance:vendor.manage',
            'finance:purchase.view', 'finance:purchase.create',
            'finance:cash.view', 'finance:cash.manage', 'finance:cashier.view', 'finance:cashier.manage',
            'finance:bank.view', 'finance:bank.manage',
            'finance:transfer.view', 'finance:transfer.create',
            'finance:reconciliation.view', 'finance:reconciliation.manage',
            'finance:adjustment.view', 'finance:adjustment.create',
            // Officer melihat jurnal & buku besar hasil pencatatannya dan mengajukan
            // write-off, tanpa hak menyetujui atau membalik (§3, §33).
            'finance:transaction.view', 'finance:journal.view', 'finance:ledger.view',
            'finance:write_off.request',
            // Petugas penagihan: melihat antrean dan mencatat reminder/WhatsApp/
            // email (§6). Tangga eskalasi tidak ikut diberikan — naik tingkat
            // penagihan adalah keputusan atasan, bukan operator pencatat.
            'finance:collection.view', 'finance:collection.manage',
            // Petugas membaca putusan kelayakan (§11) untuk menjelaskan situasinya
            // ke orang tua. Menyetujuinya bukan: hak `approve` ada di manajer.
            'finance:eligibility.view',
            // Petugas juga yang menerima permintaan keberesan dari santri/wali lalu
            // mengisi ulang pemeriksaannya (§12). Menyetujui atau mengecualikan
            // bukan tugasnya — dua keputusan itu tinggal di manajer/kepala sekolah.
            'finance:clearance.view', 'finance:clearance.request',
            // Petugas memanggil pekerjaan berat (bulk billing/invoice, reminder)
            // dan melihat kegagalannya (§28). Ia tidak menyetujui apa pun, tapi
            // menjalankan generasi tagihan memang bagian pencatatannya.
            'finance:operation.view', 'finance:operation.manage',
            'finance:report.view', 'finance:report.export',
        ],
        // Kasir: menerima pembayaran & menerbitkan kuitansi, mengelola sesi kas.
        // Tanpa hak mengubah master tagihan, tanpa approve, tanpa bank manage.
        'kasir' => [
            'finance:view',
            'finance:billing.view', 'finance:invoice.view',
            'finance:payment.view', 'finance:payment.create', 'finance:payment.verify', 'finance:payment.reject',
            'finance:receipt.view', 'finance:receipt.create', 'finance:receipt.cancel',
            // Kasir melihat jadwal cicilan untuk menagih nominal yang benar di
            // loket, tapi tidak menyusunnya — kesepakatan mencicil dibuat petugas
            // keuangan/manager, bukan di kasir (§3).
            'finance:payment.plan.view',
            'finance:cash.view', 'finance:cashier.view', 'finance:cashier.manage',
            'finance:report.view',
        ],
        // Pengadaan: vendor & pembelian (PR/PO), mengajukan expense. Tanpa kas/bank.
        'pengadaan' => [
            'finance:view',
            'finance:vendor.view', 'finance:vendor.manage',
            'finance:purchase.view', 'finance:purchase.create',
            'finance:expense.view', 'finance:expense.create',
            'finance:budget.view', 'finance:report.view',
        ],
        // Kepala unit: mengajukan expense unit & melihat budget unitnya. Read + submit.
        'kepala-unit' => [
            'finance:view',
            'finance:budget.view', 'finance:cost_center.view',
            'finance:expense.view', 'finance:expense.create',
            'finance:report.view',
        ],
        // Auditor: baca-saja seluruh finance + audit trail & laporan. Tanpa aksi
        // tulis apa pun (§56 kepemimpinan/pengawas read-only).
        'auditor' => [
            'finance:view',
            'finance:account.view', 'finance:cost_center.view', 'finance:budget.view', 'finance:fee.view',
            'finance:billing.view', 'finance:invoice.view', 'finance:payment.view', 'finance:receipt.view',
            'finance:expense.view', 'finance:vendor.view', 'finance:purchase.view',
            'finance:cash.view', 'finance:cashier.view', 'finance:bank.view',
            'finance:transfer.view', 'finance:reconciliation.view', 'finance:adjustment.view',
            // Jurnal & buku besar read-only: auditor memeriksa pembukuan, bukan
            // melakukannya — tanpa hak reverse meski semua lainnya boleh dibaca.
            'finance:transaction.view', 'finance:journal.view', 'finance:ledger.view',
            // Sejarah penagihan ikut dibaca (audit tagihan §6) tanpa hak mencatat;
            // begitu pula jadwal cicilan (§7) yang menjadi dasar sejarah itu.
            'finance:collection.view',
            'finance:payment.plan.view',
            // Keberesan finansial ikut dibaca (§12): auditor memeriksa apakah
            // pengecualian diberi alasannya, bukan memberi pengecualian.
            'finance:clearance.view',
            // Register pekerjaan & kegagalan ikut dibaca (§29) sebagai bagian
            // pemeriksaan pembukuan; menjalankan ulang bukan hak auditor.
            'finance:operation.view',
            'finance:closing.view', 'finance:report.view', 'finance:report.export',
            'finance:settings.view', 'finance:audit.view',
        ],
    ];

    public function run(): void
    {
        // Aksi master Library dibangkitkan dari registry (satu sumber
        // kebenaran), sehingga menambah master baru otomatis menambah
        // permission-nya tanpa menyunting seeder ini.
        $this->modules['library'] = array_values(array_unique(array_merge(
            $this->modules['library'],
            $this->libraryMasterActions(),
        )));

        // Buat seluruh permission
        $allPermissions = [];
        foreach ($this->modules as $module => $actions) {
            foreach ($actions as $action) {
                $name = "{$module}.{$action}";
                $allPermissions[] = $name;
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
        }

        $this->grantStandardAccountingPermissions();

        // Buat role & assign permission
        foreach ($this->roles as $roleName => $scopes) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

            if (in_array('*', $scopes, true)) {
                $role->syncPermissions($allPermissions);

                continue;
            }

            $granted = [];
            foreach ($scopes as $scope) {
                if (str_contains($scope, ':')) {
                    [$module, $action] = explode(':', $scope);
                    $granted[] = "{$module}.{$action}";

                    continue;
                }

                foreach ($this->modules[$scope] ?? [] as $action) {
                    $granted[] = "{$scope}.{$action}";
                }
            }

            $role->syncPermissions(array_unique($granted));
        }

        $this->grantLibraryMasterPermissions();
        $this->grantLibraryModulePermissions();
        $this->grantPpdbPermissions();
    }

    /**
     * Peran operasional PPDB.
     *
     * `panitia-ppdb` = panitia penerimaan (kelola konfigurasi & pendaftaran,
     * verifikasi dokumen, jadwalkan seleksi) tanpa hak hapus.
     * Bendahara memverifikasi pembayaran; kepala sekolah hanya melihat laporan.
     */
    /**
     * Izin akuntansi standar (aset, penyusutan, akrual, persediaan) diberikan
     * mengikuti izin "saudaranya" yang sudah ada, bukan lewat daftar peran baru:
     * siapa pun yang selama ini boleh mengelola COA juga boleh mengelola aset,
     * dan siapa pun yang boleh melihat COA boleh melihatnya. Dengan begitu tidak
     * ada peran yang tiba-tiba kehilangan akses, dan tidak ada pula yang mendapat
     * akses baru di luar lingkupnya.
     */
    protected function grantStandardAccountingPermissions(): void
    {
        $peta = [
            'finance.asset.view' => 'finance.account.view',
            'finance.depreciation.view' => 'finance.account.view',
            'finance.accrual.view' => 'finance.account.view',
            'finance.asset.manage' => 'finance.account.manage',
            'finance.depreciation.post' => 'finance.account.manage',
            'finance.accrual.manage' => 'finance.account.manage',
            'finance.inventory.post' => 'finance.account.manage',
            // Persetujuan tidak diwariskan dari pencatat: yang boleh menyetujui
            // adalah pemegang izin persetujuan lain (manajer/direktur).
            'finance.closing.approve' => 'finance.budget.approve',
            'finance.journal.approve' => 'finance.adjustment.approve',
        ];

        foreach ($peta as $baru => $saudara) {
            $izin = Permission::firstOrCreate(['name' => $baru, 'guard_name' => 'web']);
            $asli = Permission::query()->where('name', $saudara)->where('guard_name', 'web')->first();

            if ($asli === null) {
                continue;
            }

            foreach (Role::query()->whereHas('permissions', fn ($q) => $q->where('name', $saudara))->get() as $role) {
                if (! $role->hasPermissionTo($baru)) {
                    $role->givePermissionTo($izin);
                }
            }
        }
    }

    protected function grantPpdbPermissions(): void
    {
        $role = Role::firstOrCreate(['name' => 'panitia-ppdb', 'guard_name' => 'web']);

        $role->syncPermissions(array_map(fn (string $permission) => "ppdb.{$permission}", [
            'view',
            'period.manage', 'wave.manage', 'program.manage', 'form.manage',
            'question.manage', 'document.manage', 'fee.manage',
            'registration.view', 'registration.manage', 'registration.verify',
            'payment.view', 'payment.verify',
            'selection.manage', 'announcement.manage', 'account.manage',
            'report.view', 'report.export',
        ]));

        // Bendahara: verifikasi pembayaran PPDB.
        Role::query()->where('name', 'bendahara')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['ppdb.view', 'ppdb.payment.view', 'ppdb.payment.verify', 'ppdb.report.view']);

        // Kepala sekolah: pemantauan.
        Role::query()->where('name', 'kepala-sekolah')->where('guard_name', 'web')->first()
            ?->givePermissionTo(['ppdb.view', 'ppdb.registration.view', 'ppdb.payment.view', 'ppdb.report.view', 'ppdb.report.export']);
    }

    /**
     * Permission modul Library tahap lanjut (Fase 3: katalog, koleksi,
     * keanggotaan, kartu, OPAC) untuk peran operasional.
     */
    protected function grantLibraryModulePermissions(): void
    {
        $grants = [
            // Library manager: mengelola, tanpa hak hapus (konsisten dengan master).
            'library-manager' => [
                'catalog.view', 'catalog.create', 'catalog.update',
                'item.view', 'item.create', 'item.update',
                'member.view', 'member.create', 'member.update',
                'membership.view', 'membership.create', 'membership.update',
                'member_card.view', 'member_card.generate',
                'opac.view', 'opac.manage',
                'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew', 'circulation.manage',
                'fine.view', 'fine.manage',
                'reservation.view', 'reservation.create', 'reservation.manage',
                'acquisition.view', 'acquisition.create', 'acquisition.manage',
                'inventory.view', 'inventory.manage',
                'report.view', 'report.export',
            ],
            // Pustakawan: operasional harian katalog, sirkulasi, dan anggota.
            'pustakawan' => [
                'catalog.view', 'catalog.create', 'catalog.update',
                'item.view', 'item.create', 'item.update',
                'member.view', 'member.create', 'member.update',
                'membership.view', 'membership.update',
                'member_card.view', 'member_card.generate',
                'opac.view',
                'circulation.view', 'circulation.checkout', 'circulation.return', 'circulation.renew',
                'fine.view', 'fine.manage',
                'reservation.view', 'reservation.create', 'reservation.manage',
                'acquisition.view', 'inventory.view',
                'report.view',
            ],
            // Kepala sekolah: hanya melihat (termasuk laporan).
            'kepala-sekolah' => [
                'catalog.view', 'item.view', 'member.view', 'membership.view', 'opac.view',
                'circulation.view', 'fine.view', 'reservation.view',
                'acquisition.view', 'inventory.view', 'report.view', 'report.export',
            ],
            // Siswa & wali: katalog publik (tanpa permission pun dapat diakses,
            // permission ini untuk visibilitas menu).
            'siswa' => ['opac.view'],
            'wali-murid' => ['opac.view'],
        ];

        foreach ($grants as $roleName => $permissions) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $names = array_map(fn (string $permission) => "library.{$permission}", $permissions);
            $role->givePermissionTo($names);
        }
    }

    /**
     * Aksi permission master Library, mis. "master.view",
     * "collection_type.create" — dipakai agar scope modul `library`
     * (admin) otomatis mencakup seluruh master.
     *
     * @return list<string>
     */
    protected function libraryMasterActions(): array
    {
        $actions = [];

        foreach (LibraryMasterRegistry::permissionNames() as $name) {
            if (str_starts_with($name, 'library.')) {
                $actions[] = substr($name, strlen('library.'));
            }
        }

        return $actions;
    }

    /**
     * Master data Library tidak diambil dari scope modul penuh untuk semua
     * peran: pengelola perpustakaan boleh mengubah master, pustakawan boleh
     * menambah/mengubah (tanpa menghapus), kepala sekolah hanya melihat.
     */
    protected function grantLibraryMasterPermissions(): void
    {
        $levels = [
            'library-manager' => ['manage'],       // view + create + update
            'pustakawan' => ['view', 'create', 'update'],
            'kepala-sekolah' => ['view'],
        ];

        $names = LibraryMasterRegistry::permissionNames();

        foreach ($levels as $roleName => $wanted) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role === null) {
                continue;
            }

            $actions = in_array('manage', $wanted, true) ? ['view', 'create', 'update'] : $wanted;
            $granted = [];

            foreach ($names as $name) {
                if ($name === 'library.master.view') {
                    $granted[] = $name;

                    continue;
                }

                foreach ($actions as $action) {
                    if (str_ends_with($name, '.'.$action)) {
                        $granted[] = $name;
                    }
                }
            }

            $role->givePermissionTo(array_unique($granted));
        }
    }
}
