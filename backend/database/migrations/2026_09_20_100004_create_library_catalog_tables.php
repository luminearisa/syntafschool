<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Katalog bibliografi modul Library (Fase 3).
 *
 * Satu **bibliographic record** mewakili satu judul (deskripsi intelektual),
 * bukan satu buku fisik — eksemplar fisiknya ada di `library_items`.
 *
 * Relasi ke master Fase 2 memakai foreign key, bukan teks bebas: creator,
 * subject, genre, tag, classification, publisher, bahasa, jenis koleksi,
 * kategori, bentuk media, dan series. Tidak ada data bibliografi yang
 * disimpan sebagai string gabungan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_bibliographies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('libraries')->cascadeOnDelete();

            // Identifier internal dari NumberingService (format configurable).
            // Penomoran bersifat per-perpustakaan, jadi keunikannya pun
            // per-perpustakaan (bukan global).
            $table->string('control_number', 50);
            // Slug publik untuk OPAC — bukan raw id.
            $table->string('slug', 200)->unique();
            // Slug lama disimpan agar tautan lama tetap dapat ditelusuri (§50).
            $table->json('previous_slugs')->nullable();

            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->string('uniform_title', 255)->nullable();
            // Judul ternormalisasi (huruf kecil, tanda baca dibuang) agar
            // pencarian tidak bergantung LIKE liar ke banyak kolom.
            $table->string('normalized_title', 255)->nullable();

            $table->string('edition_statement', 100)->nullable();
            $table->unsignedSmallInteger('edition_number')->nullable();
            $table->unsignedSmallInteger('edition_year')->nullable();

            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->string('publication_place', 100)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('publication_statement', 200)->nullable();

            $table->foreignId('language_id')->nullable()->constrained('library_languages')->nullOnDelete();
            $table->foreignId('collection_type_id')->nullable()->constrained('library_collection_types')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('book_categories')->nullOnDelete();
            $table->foreignId('material_type_id')->nullable()->constrained('library_material_types')->nullOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('library_series')->nullOnDelete();
            $table->foreignId('classification_id')->nullable()->constrained('library_classifications')->nullOnDelete();

            $table->string('call_number', 100)->nullable();

            $table->text('description')->nullable();
            $table->text('abstract')->nullable();
            $table->text('table_of_contents')->nullable();
            $table->string('physical_description', 255)->nullable();
            $table->text('notes')->nullable();

            // Path media saja — tidak ada binary di basis data (Fase 3 tidak
            // membangun uploader; kolom disiapkan agar media system menyusul).
            $table->string('cover_image')->nullable();
            $table->string('thumbnail')->nullable();

            // Penanda ketersediaan digital/fisik; digital lending = Fase 4.
            $table->boolean('digital_available')->default(false);
            $table->boolean('physical_available')->default(true);

            $table->enum('status', ['draft', 'active', 'inactive', 'archived'])->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['library_id', 'control_number']);
            $table->index(['status', 'library_id']);
            $table->index('normalized_title');
            $table->index('publication_year');
            $table->index('publisher_id');
        });

        // Kreator per record, dengan peran & urutan tampil.
        Schema::create('library_bibliography_creators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('authors')->cascadeOnDelete();
            $table->foreignId('creator_role_id')->nullable()->constrained('library_creator_roles')->nullOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->boolean('is_primary')->default(false);
            // Nama tampil pada karya ini (mis. gelar berbeda) — opsional.
            $table->string('display_name', 150)->nullable();
            $table->timestamps();

            $table->unique(['bibliography_id', 'creator_id', 'creator_role_id'], 'bib_creator_role_unique');
            $table->index('creator_id');
        });

        Schema::create('library_bibliography_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('library_subjects')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bibliography_id', 'subject_id']);
        });

        Schema::create('library_bibliography_genres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained('library_genres')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bibliography_id', 'genre_id']);
        });

        Schema::create('library_bibliography_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('library_tags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bibliography_id', 'tag_id']);
        });

        // Satu record boleh punya banyak identifier (§14) — bukan satu kolom isbn.
        Schema::create('library_bibliography_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->string('identifier_type', 20); // isbn10|isbn13|issn|doi|oclc|local|other
            $table->string('value', 100);
            // Nilai tanpa tanda hubung/spasi agar pencarian konsisten.
            $table->string('normalized_value', 100);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index(['identifier_type', 'normalized_value']);
            $table->index('bibliography_id');
        });

        // Cover/thumbnail/back/additional — path saja.
        Schema::create('library_bibliography_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliography_id')->constrained('library_bibliographies')->cascadeOnDelete();
            $table->string('file_type', 20)->default('additional'); // cover|thumbnail|back|additional
            $table->string('file_path');
            $table->string('caption', 150)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['bibliography_id', 'file_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_bibliography_files');
        Schema::dropIfExists('library_bibliography_identifiers');
        Schema::dropIfExists('library_bibliography_tags');
        Schema::dropIfExists('library_bibliography_genres');
        Schema::dropIfExists('library_bibliography_subjects');
        Schema::dropIfExists('library_bibliography_creators');
        Schema::dropIfExists('library_bibliographies');
    }
};