<?php

namespace App\Modules\Supervisi\Application\Services;

/**
 * Sejauh mana seorang pengguna boleh melihat data supervisi.
 *
 * Blueprint §11.6: "guru melihat detail miliknya; rekan sejawat hanya melihat
 * ringkasan bila sekolah mengaktifkan". Karena itu setiap layar analitik dan
 * laporan menerima cakupan ini — bukan sekadar memeriksa izin modul, yang
 * memang dimiliki guru untuk melihat **datanya sendiri**.
 */
final class DataScope
{
    public function __construct(
        public readonly bool $school,
        public readonly ?int $employeeId,
        public readonly bool $peerSummary,
        public readonly ?int $userId = null,
    ) {
    }

    /** Pemegang tata kelola: seluruh sekolah. */
    public static function school(?int $userId = null): self
    {
        return new self(true, null, true, $userId);
    }

    /** Pegawai biasa: hanya dirinya (opsional: ringkasan agregat sekolah). */
    public static function self(?int $employeeId, bool $peerSummary = false, ?int $userId = null): self
    {
        return new self(false, $employeeId, $peerSummary, $userId);
    }

    public function isSchool(): bool
    {
        return $this->school;
    }

    public function isSelf(): bool
    {
        return ! $this->school;
    }

    /** Akun yang belum tertaut ke pegawai: tidak melihat apa pun. */
    public function isUnlinked(): bool
    {
        return ! $this->school && $this->employeeId === null;
    }

    /** Boleh melihat angka agregat sekolah (tanpa nama) atau tidak. */
    public function allowsPeerSummary(): bool
    {
        return $this->school || $this->peerSummary;
    }

    /** Cakupan ringkas untuk dikirim ke antarmuka. */
    /** @return array<string,mixed> */
    public function payload(): array
    {
        return [
            'level' => $this->school ? 'SEKOLAH' : 'DIRI',
            'employee_id' => $this->employeeId,
            'user_id' => $this->userId,
            'peer_summary' => $this->peerSummary,
            'label' => $this->school
                ? 'Seluruh sekolah'
                : 'Data Anda sendiri'.($this->peerSummary ? ' + ringkasan agregat sekolah' : ''),
            'note' => $this->school
                ? null
                : 'Sesuai kebijakan privasi, hasil supervisi pegawai lain hanya terbuka bagi kepala sekolah, pengawas, dan admin.',
        ];
    }

    /** Pegawai ini termasuk dalam cakupan baris tersebut? */
    public function covers(?int ...$employeeIds): bool
    {
        if ($this->school) {
            return true;
        }

        if ($this->employeeId === null) {
            return false;
        }

        foreach ($employeeIds as $id) {
            if ($id !== null && (int) $id === $this->employeeId) {
                return true;
            }
        }

        return false;
    }
}
