<?php

namespace App\Modules\Asset\Models;

use App\Modules\Asset\Models\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/**
 * Aset fisik/fasilitas individual — pusat modul ini.
 *
 * Menyimpan keadaan operasional (lokasi, pemegang, kondisi) sekaligus salinan
 * nilai yang dibutuhkan keputusan operasional. Angka buku yang mengikat tetap
 * milik modul Keuangan; di sini hanya rujukan (`finance_asset_id`).
 */
class Asset extends Model
{
    use BelongsToSchool;

    protected $table = 'assets';

    protected $fillable = [
        'category_id',
        'asset_type_id',
        'parent_asset_id',
        'finance_asset_id',
        'location_id',
        'unit_id',
        'custodian_id',
        'custodian_type',
        'custodian_label',
        'active_loan_id',
        'vendor_id',
        'asset_code',
        'asset_number',
        'asset_tag',
        'name',
        'serial_number',
        'barcode',
        'qr_token',
        'rfid',
        'manufacturer',
        'brand',
        'model',
        'acquisition_source',
        'purchase_date',
        'received_date',
        'commission_date',
        'capitalization_date',
        'acquisition_cost',
        'residual_value',
        'current_book_value',
        'accumulated_depreciation',
        'fair_value',
        'useful_life_months',
        'depreciation_method',
        'depreciation_started_at',
        'capitalization_decision',
        'capitalization_threshold',
        'status',
        'condition',
        'criticality',
        'tag_status',
        'tagged_at',
        'warranty_start',
        'warranty_end',
        'warranty_provider',
        'warranty_number',
        'warranty_terms',
        'warranty_claim_reference',
        'procurement_reference',
        'purchase_order_reference',
        'invoice_reference',
        'received_by',
        'registered_at',
        'expected_life_end',
        'last_inspection_at',
        'next_inspection_at',
        'last_maintenance_at',
        'next_maintenance_at',
        'loan_count',
        'maintenance_count',
        'downtime_minutes',
        'is_archived',
        'archived_at',
        'description',
        'notes',
        'version',
        'created_by',
        'updated_by',
        'warranty_end',
        'next_maintenance_at',
    ];

    protected $casts = [
        'status' => \App\Modules\Asset\Domain\Enums\AssetStatus::class,
        'condition' => \App\Modules\Asset\Domain\Enums\AssetCondition::class,
        'criticality' => \App\Modules\Asset\Domain\Enums\AssetCriticality::class,
        'acquisition_source' => \App\Modules\Asset\Domain\Enums\AssetAcquisitionSource::class,
        'capitalization_decision' => \App\Modules\Asset\Domain\Enums\CapitalizationDecision::class,
        'tag_status' => \App\Modules\Asset\Domain\Enums\AssetTagStatus::class,
        'depreciation_method' => \App\Modules\Asset\Domain\Enums\AssetDepreciationMethod::class,
        'purchase_date' => 'date',
        'received_date' => 'date',
        'commission_date' => 'date',
        'capitalization_date' => 'date',
        'acquisition_cost' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'current_book_value' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'fair_value' => 'decimal:2',
        'depreciation_started_at' => 'date',
        'tagged_at' => 'datetime',
        'warranty_start' => 'date',
        'warranty_end' => 'date',
        'registered_at' => 'datetime',
        'expected_life_end' => 'date',
        'last_inspection_at' => 'datetime',
        'next_inspection_at' => 'datetime',
        'last_maintenance_at' => 'datetime',
        'next_maintenance_at' => 'datetime',
        'is_archived' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id');
    }

    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function unit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Unit::class, 'unit_id');
    }

    public function custodian(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'custodian_id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function components(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    public function identifiers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetIdentifier::class);
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetTag::class);
    }

    public function specifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetSpecification::class);
    }

    public function attributeValues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetAttributeValue::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetDocument::class);
    }

    public function custodianHistory(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetCustodianHistory::class);
    }

    public function transfers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetTransferItem::class, 'asset_id');
    }

    public function loanItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetLoanItem::class, 'asset_id');
    }

    public function activeLoan(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AssetLoan::class, 'active_loan_id');
    }

    public function inspections(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetInspection::class);
    }

    public function damageReports(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(DamageReport::class);
    }

    public function incidents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetIncident::class);
    }

    public function maintenanceRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function workOrders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceWorkOrder::class);
    }

    public function schedules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function depreciations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetDepreciation::class);
    }

    public function valuations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetValuation::class);
    }

    public function disposalRequests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetDisposalRequest::class);
    }

    public function auditLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AssetAuditLog::class);
    }

    public function isLoanable(): bool
    {
        return $this->status instanceof \App\Modules\Asset\Domain\Enums\AssetStatus
            ? $this->status->isLoanable() && $this->active_loan_id === null
            : false;
    }

    public function isFrozen(): bool
    {
        return \App\Modules\Asset\Domain\StateMachines\AssetStateMachine::isFrozen((string) $this->status);
    }
}
