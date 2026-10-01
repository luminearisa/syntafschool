<?php

namespace Tests\Feature\Bk;

use App\Modules\Bk\Application\Services\BkEarlyWarningEngine;
use App\Modules\Bk\Application\Services\BkRiskIndicatorService;
use App\Modules\Bk\Domain\Enums\EarlyWarningEventStatus;
use App\Modules\Bk\Domain\Enums\RiskIndicatorStatus;
use App\Modules\Bk\Models\BkEarlyWarningEvent;
use App\Modules\Bk\Models\BkEarlyWarningRule;
use App\Modules\Bk\Models\BkRiskIndicator;
use Illuminate\Support\Facades\DB;

/**
 * Mesin peringatan dini.
 *
 * Yang diuji: ambang benar-benar dibaca dari basis data (bukan ditanam di kode),
 * evaluasi berulang tidak menggandakan penanda, jeda (cooldown) dihormati, metrik
 * yang tidak tersedia dicatat apa adanya — bukan dianggap nol — dan aturan
 * ber-scope tidak berlaku untuk siswa di luar cakupannya.
 */
class BkEarlyWarningEngineTest extends BkPhase2TestCase
{
    public function test_ambang_dibaca_dari_basis_data_bukan_dari_kode(): void
    {
        $family = $this->makeStudentWithFamily('Nabila');
        $admin = $this->makeCounselor('BK_ADMIN');

        $this->makingReferralRule($admin, threshold: 2);

        // Dua rujukan: ambang terpenuhi hanya karena aturannya menyebut 2.
        $this->makeReferral($admin, $family['student']);
        $this->makeReferral($admin, $family['student']);

        $engine = app(BkEarlyWarningEngine::class);
        $events = $engine->evaluateStudent((int) $family['student']->id, $admin);

        $matched = collect($events)->firstWhere('metric.value', 'REFERRAL_COUNT');
        $this->assertNotNull($matched);
        $this->assertSame(EarlyWarningEventStatus::ACTIONED, $matched->status, 'Ambang 2 harus terpenuhi oleh 2 absen.');
        $this->assertSame(2.0, (float) $matched->threshold);
    }

    public function test_evaluasi_berulang_tidak_menggandakan_penanda(): void
    {
        $family = $this->makeStudentWithFamily('Nawal');
        $admin = $this->makeCounselor('BK_ADMIN');

        $this->makingReferralRule($admin, threshold: 1);
        $this->makeReferral($admin, $family['student']);
        $this->makeReferral($admin, $family['student']);

        $engine = app(BkEarlyWarningEngine::class);

        $first = $engine->evaluateStudent((int) $family['student']->id, $admin);
        $second = $engine->evaluateStudent((int) $family['student']->id, $admin);
        $third = $engine->evaluateStudent((int) $family['student']->id, $admin);

        $this->assertCount(1, BkRiskIndicator::query()->where('student_id', $family['student']->id)->get(), 'Satu penanda, bukan tiga.');
        $this->assertCount(1, BkEarlyWarningEvent::query()->where('student_id', $family['student']->id)->matched()->get(), 'Satu peristiwa tercatat sebagai tindakan.');

        // Evaluasi ulang meninggalkan jejak COOLDOWN (satu per hari), bukan tindakan baru.
        $cooldowns = BkEarlyWarningEvent::query()->where('student_id', $family['student']->id)
            ->where('status', EarlyWarningEventStatus::COOLDOWN->value)->count();
        $this->assertSame(1, $cooldowns, 'Evaluasi ulang hari yang sama tercatat sekali sebagai cooldown.');
        $this->assertStringContainsString('Sudah ditindak', (string) BkEarlyWarningEvent::query()
            ->where('status', EarlyWarningEventStatus::COOLDOWN->value)->value('reason'));

        $this->assertNotEmpty($first);
        $this->assertNotEmpty($second);
        $this->assertNotEmpty($third);
    }

    public function test_metrik_yang_tidak_tersedia_dicatat_apa_adanya(): void
    {
        $family = $this->makeStudentWithFamily('Hafshah');
        $admin = $this->makeCounselor('BK_ADMIN');

        BkEarlyWarningRule::query()->create([
            'code' => 'ACTIVITY_DISENGAGEMENT_RULE',
            'version' => 1,
            'name' => 'Hari tanpa kegiatan',
            'metric' => 'ACTIVITY_DISENGAGEMENT_DAYS',
            'operator' => 'GTE',
            'threshold' => 14,
            'window_days' => 30,
            'action' => 'CREATE_SUPPORT_INDICATOR',
            'indicator_code' => 'ACTIVITY_DISENGAGEMENT',
            'severity' => 'LOW',
            'cooldown_days' => 7,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $events = app(BkEarlyWarningEngine::class)->evaluateStudent((int) $family['student']->id, $admin);
        $event = collect($events)->firstWhere('metric.value', 'ACTIVITY_DISENGAGEMENT_DAYS');

        $this->assertNotNull($event);
        $this->assertSame(EarlyWarningEventStatus::SKIPPED, $event->status);
        $this->assertNotNull($event->reason, 'Alasan ketiadaan data harus terbaca, bukan kosong.');
        $this->assertSame(0, BkRiskIndicator::query()->where('student_id', $family['student']->id)->count());
    }

    public function test_aturan_ber_scope_tidak_berlaku_di_luar_cakupannya(): void
    {
        $family = $this->makeStudentWithFamily('Zubair');
        $admin = $this->makeCounselor('BK_ADMIN');

        BkEarlyWarningRule::query()->create([
            'code' => 'GRADE9_ONLY',
            'version' => 1,
            'name' => 'Hanya kelas tertentu',
            'metric' => 'REFERRAL_COUNT',
            'operator' => 'GTE',
            'threshold' => 1,
            'window_days' => 30,
            'action' => 'CREATE_SUPPORT_INDICATOR',
            'indicator_code' => 'MULTIPLE_REFERRALS',
            'severity' => 'LOW',
            'cooldown_days' => 7,
            // Cakupan menyebut kelas yang tidak dimiliki siswa pada uji ini.
            'scope' => ['class_ids' => [999999]],
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->makeReferral($admin, $family['student']);

        $events = app(BkEarlyWarningEngine::class)->evaluateStudent((int) $family['student']->id, $admin);

        $this->assertSame([], array_values(array_filter(
            $events,
            fn ($event): bool => $event->metric->value === 'REFERRAL_COUNT',
        )), 'Aturan dengan cakupan kelas lain tidak dievaluasi untuk siswa ini.');
    }

    public function test_versi_aturan_baru_dapat_berjalan_berdampingan(): void
    {
        $family = $this->makeStudentWithFamily('Ummu Kultsum');
        $admin = $this->makeCounselor('BK_ADMIN');

        $this->makingReferralRule($admin, threshold: 5, code: 'REFERRAL_V1', version: 1);

        BkEarlyWarningRule::query()->create([
            'code' => 'REFERRAL_V1',
            'version' => 2,
            'name' => 'Rujukan berulang (versi 2)',
            'metric' => 'REFERRAL_COUNT',
            'operator' => 'GTE',
            'threshold' => 1,
            'window_days' => 30,
            'action' => 'CREATE_SUPPORT_INDICATOR',
            'indicator_code' => 'MULTIPLE_REFERRALS',
            'severity' => 'HIGH',
            'cooldown_days' => 7,
            'is_active' => true,
            'created_by' => $admin->id,
        ]);

        $this->makeReferral($admin, $family['student']);

        $events = app(BkEarlyWarningEngine::class)->evaluateStudent((int) $family['student']->id, $admin);
        $codes = collect($events)->map(fn ($event): string => $event->rule?->code.'#'.$event->rule_version)->all();

        // Versi 1 (ambang 5) tidak cocok; versi 2 (ambang 1) cocok. Keduanya terekam.
        $this->assertContains('REFERRAL_V1#2', $codes);
        $v1 = collect($events)->firstWhere('rule_version', 1);
        $this->assertSame(EarlyWarningEventStatus::SKIPPED, $v1->status, 'Versi lama tetap dievaluasi dan dicatat hasilnya.');
    }

    public function test_penanda_dapat_ditinjau_dan_tidak_pernah_mengubah_status_siswa(): void
    {
        $family = $this->makeStudentWithFamily('Khadijah');
        $admin = $this->makeCounselor('BK_ADMIN');
        $student = $family['student'];

        $statusBefore = $student->fresh()->status_id ?? null;

        $indicator = app(BkRiskIndicatorService::class)->create($admin, (int) $student->id,
            \App\Modules\Bk\Domain\Enums\RiskIndicatorCode::REPEATED_ABSENCE);

        $reviewed = app(BkRiskIndicatorService::class)->review($admin, $indicator, [
            'status' => RiskIndicatorStatus::REVIEWED->value,
            'review_note' => 'Sudah ditanyakan; ternyata karena sakit dan sudah ada surat.',
        ]);

        $this->assertSame(RiskIndicatorStatus::REVIEWED, $reviewed->status);
        $this->assertSame($admin->id, (int) $reviewed->reviewed_by);
        // Penanda tidak menyentuh data siswa: bukan tugasnya.
        $this->assertSame($statusBefore, $student->fresh()->status_id ?? null);
    }

    /** Membuat aturan berbasis jumlah rujukan (metrik milik modul BK sendiri). */
    protected function makingReferralRule($actor, int $threshold, string $code = 'REFERRAL_COUNT_RULE', int $version = 1): BkEarlyWarningRule
    {
        return BkEarlyWarningRule::query()->create([
            'code' => $code,
            'version' => $version,
            'name' => 'Rujukan '.$threshold.' kali atau lebih',
            'metric' => 'REFERRAL_COUNT',
            'operator' => 'GTE',
            'threshold' => $threshold,
            'window_days' => 30,
            'action' => 'CREATE_SUPPORT_INDICATOR',
            'indicator_code' => 'MULTIPLE_REFERRALS',
            'severity' => 'MODERATE',
            'cooldown_days' => 7,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
    }

}
