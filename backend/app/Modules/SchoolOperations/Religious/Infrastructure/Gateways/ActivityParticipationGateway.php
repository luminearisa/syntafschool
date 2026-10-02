<?php

namespace App\Modules\SchoolOperations\Religious\Infrastructure\Gateways;

use App\Modules\SchoolOperations\Religious\Domain\Contracts\StudentActivityGatewayInterface;
use Illuminate\Support\Facades\DB;

/**
 * Student Activity: partisipasi, prestasi, dan portofolio (§54, §55, §56).
 */
class ActivityParticipationGateway implements StudentActivityGatewayInterface
{
    use GatewaySupport;

    protected function provider(): ?object
    {
        return $this->delegate('App\\Modules\\Asset\\Domain\\Contracts\\StudentActivityProviderInterface')
            ?? $this->delegate('App\\Modules\\Bk\\Domain\\Contracts\\StudentActivityProviderInterface');
    }

    public function recordParticipation(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['recordParticipation', 'record', 'addParticipant'] as $method) {
                    if (method_exists($provider, $method)) {
                        return (array) $provider->{$method}($payload);
                    }
                }
            } catch (\Throwable $e) {
                return ['written' => false, 'error' => $e->getMessage()];
            }
        }

        $table = $this->firstTable(['activity_participants', 'student_activity_participants']);
        if ($table === null) {
            return ['written' => false, 'reason' => $this->unavailableReason()];
        }

        $id = DB::table($table)->insertGetId($this->fit($table, $payload));

        return ['written' => true, 'id' => $id, 'source' => $table];
    }

    public function recordAchievement(array $payload): array
    {
        if ($provider = $this->provider()) {
            try {
                foreach (['recordAchievement', 'addAchievement'] as $method) {
                    if (method_exists($provider, $method)) {
                        return (array) $provider->{$method}($payload);
                    }
                }
            } catch (\Throwable $e) {
                return ['written' => false, 'error' => $e->getMessage()];
            }
        }

        $table = $this->firstTable(['activity_achievements', 'student_achievements']);
        if ($table === null) {
            return ['written' => false, 'reason' => $this->unavailableReason()];
        }

        $id = DB::table($table)->insertGetId($this->fit($table, $payload));

        return ['written' => true, 'id' => $id, 'source' => $table];
    }

    /**
     * Sesuaikan payload dengan skema tabel tujuan: buang kolom tak dikenal dan
     * isi kolom NOT NULL tanpa nilai bawaan (mis. `code` pada
     * `activity_achievements`) agar penerusan lintas modul tidak gagal.
     */
    protected function fit(string $table, array $payload): array
    {
        $info = DB::select('pragma table_info('.$table.')');
        $data = [];

        foreach ($info as $col) {
            $name = $col->name;
            if (array_key_exists($name, $payload)) {
                $data[$name] = $payload[$name];

                continue;
            }

            $required = $col->notnull && $col->dflt_value === null && ! $col->pk;
            $isStamp = in_array($name, ['created_at', 'updated_at'], true);
            if (! $required && ! $isStamp) {
                continue;
            }

            if ($isStamp) {
                $data[$name] = now();

                continue;
            }

            $type = strtolower((string) $col->type);
            $data[$name] = match (true) {
                str_contains($name, 'code') => 'REL-'.strtoupper(substr(md5($table.serialize($payload)), 0, 8)),
                str_contains($name, 'date') || str_contains($type, 'date') => now()->toDateString(),
                str_contains($type, 'int') => 0,
                str_contains($type, 'real') || str_contains($type, 'num') || str_contains($type, 'dec') => 0,
                default => '-',
            };
        }

        return $data;
    }

    public function portfolioFor(int $studentId): array
    {
        $table = $this->firstTable(['activity_participants', 'student_activity_participants']);
        if ($table === null || ! in_array('student_id', $this->columns($table), true)) {
            return [];
        }

        return array_map(fn ($row): array => (array) $row,
            DB::table($table)->where('student_id', $studentId)->limit(100)->get()->all());
    }

    public function available(): bool
    {
        return $this->provider() !== null
            || $this->firstTable(['activity_participants', 'student_activity_participants',
                'activity_achievements', 'student_achievements']) !== null;
    }

    public function unavailableReason(): ?string
    {
        return $this->available() ? null : 'Modul Student Activity tidak tersedia pada instalasi ini.';
    }
}
