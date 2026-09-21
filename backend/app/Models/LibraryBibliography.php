<?php

namespace App\Models;

use App\Models\Concerns\LogsLibraryActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bibliographic record — deskripsi intelektual satu judul.
 *
 * Berbeda dari `library_items` (eksemplar fisik/digital): satu record dapat
 * memiliki 0..N item, dan setiap item memiliki barcode, lokasi, kondisi, dan
 * status sendiri (Rule 1 & 2).
 */
class LibraryBibliography extends BaseModel
{
    use LogsLibraryActivity, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ARCHIVED,
    ];

    /** Status yang boleh muncul di OPAC (Rule 8). */
    public const PUBLIC_STATUSES = [self::STATUS_ACTIVE];

    public const IDENTIFIER_TYPES = ['isbn10', 'isbn13', 'issn', 'doi', 'oclc', 'local', 'other'];

    public const FILE_TYPES = ['cover', 'thumbnail', 'back', 'additional'];

    protected $casts = [
        'previous_slugs' => 'array',
        'edition_number' => 'integer',
        'edition_year' => 'integer',
        'publication_year' => 'integer',
        'digital_available' => 'boolean',
        'physical_available' => 'boolean',
    ];

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(LibraryLanguage::class, 'language_id');
    }

    public function collectionType(): BelongsTo
    {
        return $this->belongsTo(LibraryCollectionType::class, 'collection_type_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class, 'category_id');
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(LibraryMaterialType::class, 'material_type_id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(LibrarySeries::class, 'series_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(LibraryClassification::class, 'classification_id');
    }

    public function creatorLinks(): HasMany
    {
        return $this->hasMany(LibraryBibliographyCreator::class, 'bibliography_id')
            ->orderBy('sequence');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(LibraryBibliographyIdentifier::class, 'bibliography_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(LibraryBibliographyFile::class, 'bibliography_id')
            ->orderBy('sort_order');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(LibrarySubject::class, 'library_bibliography_subjects', 'bibliography_id', 'subject_id');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(LibraryGenre::class, 'library_bibliography_genres', 'bibliography_id', 'genre_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(LibraryTag::class, 'library_bibliography_tags', 'bibliography_id', 'tag_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LibraryItem::class, 'bibliography_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /** Hanya record yang boleh tampil di katalog publik. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereIn('status', self::PUBLIC_STATUSES);
    }

    public function isPublic(): bool
    {
        return in_array($this->status, self::PUBLIC_STATUSES, true);
    }

    public function activityEventPrefix(): string
    {
        return 'BIBLIOGRAPHIC';
    }
}