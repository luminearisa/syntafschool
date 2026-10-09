<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom `student_attendances.status` didefinisikan sebagai
     * enum('pending','verified','locked') padahal kode memakai `recorded`
     * (StudentAttendance::STATUS_RECORDED). SQLite mengabaikan enum sehingga
     * bug ini tidak terasa, tetapi MySQL/PostgreSQL akan menolak/mengosongkan
     * nilai `recorded`.
     *
     * Kolom diubah menjadi string biasa (lebih portabel daripada enum) dan
     * nilai lama yang setara dirapikan. Pada SQLite kolom dibiarkan apa adanya
     * (tidak ada penegakan enum) agar migrasi tidak membangun ulang tabel.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('student_attendances', function (Blueprint $table): void {
                $table->string('status', 20)->default('recorded')->change();
            });
        }

        // 'pending' adalah default lama yang tidak pernah dikenali kode.
        DB::table('student_attendances')
            ->where('status', 'pending')
            ->update(['status' => 'recorded']);
    }

    public function down(): void
    {
        DB::table('student_attendances')
            ->where('status', 'recorded')
            ->update(['status' => 'pending']);

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('student_attendances', function (Blueprint $table): void {
                $table->enum('status', ['pending', 'verified', 'locked'])->default('pending')->change();
            });
        }
    }
};
