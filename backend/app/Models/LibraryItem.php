<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eksemplar koleksi — benda fisik/digital yang sebenarnya.
 *
 * Menunjuk tepat satu bibliographic record (Rule 2). Barcode & RFID unik bila
 * diisi (Rule 3 & 4). Status transaksi (`on_loan`, `reserved`) disiapkan
 * tetapi pengendaliannya milik modul Circulation pada Fase 4.
 */
class LibraryItem extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    /** Status yang dihitung "tersedia" untuk OPAC (Rule 9). */
    public const AVAILABLE_STATUSES = ['available'];

    /** Status yang dikendalikan modul Circulation (Fase 4). */
    public const CIRCULATION_STATUSES = ['on_loan', 'reserved'];

    public const STATUSES = [
        'available', 'on_loan', 'reserved', 'processing',
        'reference_only', 'lost', 'damaged', 'withdrawn', 'inactive',
    ];

    public const RFID_STATUSES = ['unassigned', 'assigned', 'active', 'lost', 'disabled'];

    public const BARCODE_TYPES = ['code128', 'ean13', 'qr', 'manual'];

    protected $casts = [
        'acquisition_date' => 'date:Y-m-d',
        'acquisition_price' => 'decimal:2',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function bibliography(): BelongsTo
    {
        return $this->belongsTo(LibraryBibliography::class, 'bibliography_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(LibraryLocation::class, 'location_id');
    }

    public function condition(): BelongsTo
    {
        return $this->belongsTo(LibraryCollectionCondition::class, 'condition_id');
    }

    public function collectionStatus(): BelongsTo
    {
        return $this->belongsTo(LibraryCollectionStatus::class, 'status_id');
    }

    public function acquisitionSource(): BelongsTo
    {
        return $this->belongsTo(LibraryAcquisitionSource::class, 'acquisition_source_id');
    }

    public function loans(): HasMany
    {
        return $this->hasMany(LibraryLoan::class, 'item_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LibraryReservation::class, 'item_id');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereIn('status', self::AVAILABLE_STATUSES);
    }

    public function isAvailable(): bool
    {
        return in_array($this->status, self::AVAILABLE_STATUSES, true);
    }

    public function activityEventPrefix(): string
    {
        return 'ITEM';
    }
}