<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identitas tambahan sebuah akun.
 *
 * `users.userable` hanya memuat satu entitas, sehingga pegawai yang juga wali
 * murid tidak dapat dimodelkan. Tabel ini menautkan SATU akun ke banyak
 * identitas (pegawai / wali / siswa) yang ditetapkan admin secara eksplisit —
 * tidak pernah diturunkan otomatis dari kecocokan nama/telepon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('identity_type', 20); // employee | guardian | student
            $table->unsignedBigInteger('identity_id');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['identity_type', 'identity_id']);
            $table->unique(['user_id', 'identity_type', 'identity_id'], 'user_identity_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
