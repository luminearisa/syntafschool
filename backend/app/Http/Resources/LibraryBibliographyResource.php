<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LibraryBibliographyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'library_id' => $this->library_id,
            'control_number' => $this->control_number,
            'slug' => $this->slug,

            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'uniform_title' => $this->uniform_title,

            'edition_statement' => $this->edition_statement,
            'edition_number' => $this->edition_number,
            'edition_year' => $this->edition_year,

            'publisher_id' => $this->publisher_id,
            'publication_place' => $this->publication_place,
            'publication_year' => $this->publication_year,
            'publication_statement' => $this->publication_statement,

            'language_id' => $this->language_id,
            'collection_type_id' => $this->collection_type_id,
            'category_id' => $this->category_id,
            'material_type_id' => $this->material_type_id,
            'series_id' => $this->series_id,
            'classification_id' => $this->classification_id,
            'call_number' => $this->call_number,

            'description' => $this->description,
            'abstract' => $this->abstract,
            'table_of_contents' => $this->table_of_contents,
            'physical_description' => $this->physical_description,
            'notes' => $this->notes,

            'cover_image' => $this->cover_image,
            'thumbnail' => $this->thumbnail,
            'digital_available' => (bool) $this->digital_available,
            'physical_available' => (bool) $this->physical_available,

            'status' => $this->status,
            'is_public' => $this->isPublic(),

            // Agregat eksemplar (dihitung, bukan disimpan di record).
            'items_count' => (int) ($this->items_count ?? 0),
            'available_items_count' => (int) ($this->available_items_count ?? 0),

            'publisher' => $this->whenLoaded('publisher', fn () => $this->publisher ? [
                'id' => $this->publisher->id,
                'name' => $this->publisher->name,
            ] : null),
            'language' => $this->whenLoaded('language', fn () => $this->language ? [
                'id' => $this->language->id,
                'name' => $this->language->name,
            ] : null),
            'collection_type' => $this->whenLoaded('collectionType', fn () => $this->collectionType ? [
                'id' => $this->collectionType->id,
                'name' => $this->collectionType->name,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ] : null),
            'material_type' => $this->whenLoaded('materialType', fn () => $this->materialType ? [
                'id' => $this->materialType->id,
                'name' => $this->materialType->name,
            ] : null),
            'series' => $this->whenLoaded('series', fn () => $this->series ? [
                'id' => $this->series->id,
                'name' => $this->series->name,
            ] : null),
            'classification' => $this->whenLoaded('classification', fn () => $this->classification ? [
                'id' => $this->classification->id,
                'code' => $this->classification->code,
                'name' => $this->classification->name,
            ] : null),

            'creators' => $this->whenLoaded('creatorLinks', fn () => $this->creatorLinks->map(fn ($link) => [
                'id' => $link->id,
                'creator_id' => $link->creator_id,
                'name' => $link->display_name ?: $link->creator?->name,
                'creator_role_id' => $link->creator_role_id,
                'role' => $link->creatorRole?->name,
                'sequence' => $link->sequence,
                'is_primary' => (bool) $link->is_primary,
            ])->values()),

            'subjects' => $this->whenLoaded('subjects', fn () => $this->subjects->map(fn ($subject) => [
                'id' => $subject->id,
                'name' => $subject->name,
            ])->values()),

            'genres' => $this->whenLoaded('genres', fn () => $this->genres->map(fn ($genre) => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->values()),

            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
            ])->values()),

            'identifiers' => $this->whenLoaded('identifiers', fn () => $this->identifiers->map(fn ($identifier) => [
                'id' => $identifier->id,
                'identifier_type' => $identifier->identifier_type,
                'value' => $identifier->value,
                'normalized_value' => $identifier->normalized_value,
                'is_primary' => (bool) $identifier->is_primary,
            ])->values()),

            'files' => $this->whenLoaded('files', fn () => $this->files->map(fn ($file) => [
                'id' => $file->id,
                'file_type' => $file->file_type,
                'file_path' => $file->file_path,
                'caption' => $file->caption,
                'sort_order' => $file->sort_order,
            ])->values()),

            'previous_slugs' => $this->previous_slugs ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}