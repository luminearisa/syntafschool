<?php

namespace App\Modules\Procurement\Domain\Enums;

use App\Modules\Procurement\Domain\Support\EnumOptions;

/** Jenis dokumen vendor (§5). */
enum VendorDocumentType: string
{
    use EnumOptions;

    case LEGAL = 'LEGAL';
    case NPWP = 'NPWP';
    case NIB = 'NIB';
    case BANK_ACCOUNT = 'BANK_ACCOUNT';
    case CONTRACT = 'CONTRACT';
    case QUOTATION_PROFILE = 'QUOTATION_PROFILE';
    case CERTIFICATE = 'CERTIFICATE';
    case LICENSE = 'LICENSE';
    case INSURANCE = 'INSURANCE';
    case COMPLIANCE = 'COMPLIANCE';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::LEGAL => 'Dokumen Legal',
            self::NPWP => 'NPWP',
            self::NIB => 'NIB',
            self::BANK_ACCOUNT => 'Rekening Bank',
            self::CONTRACT => 'Kontrak',
            self::QUOTATION_PROFILE => 'Profil Penawaran',
            self::CERTIFICATE => 'Sertifikat',
            self::LICENSE => 'Izin',
            self::INSURANCE => 'Asuransi',
            self::COMPLIANCE => 'Dokumen Kepatuhan',
            self::OTHER => 'Lainnya',
        };
    }
}
