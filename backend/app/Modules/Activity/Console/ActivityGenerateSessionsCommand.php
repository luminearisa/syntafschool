<?php

namespace App\Modules\Activity\Console;

use App\Modules\Activity\Application\Services\ActivityScheduleService;
use App\Modules\Activity\Domain\Enums\ProgramStatus;
use App\Modules\Activity\Models\ActivityProgram;
use Illuminate\Console\Command;

/**
 * Buat sesi pertemuan dari jadwal berulang (§29, §31).
 *
 * Aman dijalankan berulang: setiap sesi memiliki `generation_key`, sehingga
 * menjalankan perintah ini dua kali tidak menggandakan pertemuan.
 */
class ActivityGenerateSessionsCommand extends Command
{
    protected $signature = 'activity:generate-sessions
        {--program= : Batasi pada satu kode program}
        {--days= : Rentang hari ke depan (bawaan dari konfigurasi)}
        {--from= : Tanggal mulai (Y-m-d)}
        {--to= : Tanggal akhir (Y-m-d)}';

    protected $description = 'Buat sesi pertemuan dari jadwal aktivitas berulang (idempoten)';

    public function handle(ActivityScheduleService $schedules): int
    {
        $programs = ActivityProgram::query()
            ->when($this->option('program') !== null, fn ($query) => $query->where('code', $this->option('program')))
            ->whereIn('status', [ProgramStatus::PUBLISHED->value, ProgramStatus::ACTIVE->value])
            ->get();

        if ($programs->isEmpty()) {
            $this->warn('Tidak ada program terbit yang perlu dijadwalkan.');

            return self::SUCCESS;
        }

        $from = $this->option('from') ?? now()->toDateString();
        $to = $this->option('to')
            ?? now()->addDays((int) ($this->option('days') ?? config('activity.sessions.generation_horizon_days', 90)))->toDateString();

        $created = 0;
        $skipped = 0;

        foreach ($programs as $program) {
            $result = $schedules->generateSessions($program, $from, $to);
            $created += $result['created'];
            $skipped += $result['skipped'];

            if ($result['created'] > 0) {
                $this->line(sprintf('  %s: %d sesi baru', $program->code, $result['created']));
            }
        }

        $this->info(sprintf('Selesai: %d sesi dibuat, %d dilewati (sudah ada).', $created, $skipped));

        return self::SUCCESS;
    }
}
