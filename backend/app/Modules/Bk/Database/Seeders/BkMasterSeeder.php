<?php

namespace App\Modules\Bk\Database\Seeders;

use App\Modules\Bk\Domain\Enums\AssessmentQuestionType;
use App\Modules\Bk\Domain\Enums\AssessmentTarget;
use App\Modules\Bk\Models\BkAssessmentQuestion;
use App\Modules\Bk\Models\BkAssessmentSection;
use App\Modules\Bk\Models\BkAssessmentTemplate;
use App\Modules\Bk\Models\BkTag;
use Illuminate\Database\Seeder;

/**
 * Data awal modul BK: label kasus yang lazim dipakai dan dua template asesmen
 * bawaan (kebiasaan belajar & pandangan orang tua).
 *
 * Template sengaja sederhana dan dapat disunting sekolah; yang penting adalah
 * contoh struktur bagian → pertanyaan dengan beragam tipe jawaban.
 */
class BkMasterSeeder extends Seeder
{
    /** @var list<array{code: string, name: string, category: string}> */
    public array $tags = [
        ['code' => 'ACADEMIC_DROP', 'name' => 'Penurunan nilai', 'category' => 'ACADEMIC'],
        ['code' => 'MOTIVATION', 'name' => 'Motivasi belajar rendah', 'category' => 'ACADEMIC'],
        ['code' => 'STUDY_HABIT', 'name' => 'Kebiasaan belajar', 'category' => 'ACADEMIC'],
        ['code' => 'FREQUENT_ABSENCE', 'name' => 'Sering tidak hadir', 'category' => 'ATTENDANCE'],
        ['code' => 'LATE_ARRIVAL', 'name' => 'Sering terlambat', 'category' => 'ATTENDANCE'],
        ['code' => 'PEER_CONFLICT', 'name' => 'Konflik teman', 'category' => 'PEER_CONFLICT'],
        ['code' => 'BULLYING', 'name' => 'Perundungan', 'category' => 'BULLYING'],
        ['code' => 'ANXIETY', 'name' => 'Kecemasan', 'category' => 'EMOTIONAL'],
        ['code' => 'FAMILY_ISSUE', 'name' => 'Persoalan keluarga', 'category' => 'FAMILY'],
        ['code' => 'HOMESICK', 'name' => 'Rindu rumah (santri)', 'category' => 'BOARDING'],
        ['code' => 'CAREER_QUESTION', 'name' => 'Pertanyaan karier', 'category' => 'CAREER'],
        ['code' => 'HEALTH_CONCERN', 'name' => 'Keluhan kesehatan', 'category' => 'HEALTH'],
        ['code' => 'SAFEGUARDING', 'name' => 'Perlindungan anak', 'category' => 'SAFEGUARDING'],
    ];

    public function run(): void
    {
        foreach ($this->tags as $tag) {
            BkTag::query()->updateOrCreate(['code' => $tag['code']], $tag + ['is_active' => true]);
        }

        $this->studyHabitsTemplate();
        $this->parentViewTemplate();
    }

    private function studyHabitsTemplate(): void
    {
        $template = BkAssessmentTemplate::query()->updateOrCreate(
            ['code' => 'BK-STUDY-HABITS'],
            [
                'name' => 'Kebiasaan Belajar Siswa',
                'target' => AssessmentTarget::STUDENT->value,
                'description' => 'Memetakan kebiasaan belajar, waktu tidur, dan hal yang dirasa memberatkan.',
                'purpose' => 'Bahan pertimbangan konselor, bukan diagnosis.',
                'is_active' => true,
                'is_default' => true,
                'requires_consent' => false,
            ],
        );

        $section = BkAssessmentSection::query()->updateOrCreate(
            ['template_id' => $template->id, 'code' => 'S1'],
            ['title' => 'Kebiasaan harian', 'sort_order' => 1],
        );

        $questions = [
            ['code' => 'Q1', 'question' => 'Berapa jam kamu belajar di luar jam sekolah setiap hari?', 'question_type' => AssessmentQuestionType::NUMBER->value, 'min_value' => 0, 'max_value' => 12, 'is_scored' => false],
            ['code' => 'Q2', 'question' => 'Seberapa mudah kamu berkonsentrasi saat belajar?', 'question_type' => AssessmentQuestionType::SCALE->value, 'min_value' => 1, 'max_value' => 5, 'is_scored' => true],
            ['code' => 'Q3', 'question' => 'Apa yang paling sering mengganggu belajarmu?', 'question_type' => AssessmentQuestionType::TEXTAREA->value, 'is_scored' => false],
            ['code' => 'Q4', 'question' => 'Apakah kamu merasa cukup tidur?', 'question_type' => AssessmentQuestionType::BOOLEAN->value, 'is_scored' => false],
            ['code' => 'Q5', 'question' => 'Mata pelajaran yang paling membuatmu khawatir', 'question_type' => AssessmentQuestionType::TEXT->value, 'is_scored' => false],
        ];

        foreach ($questions as $index => $question) {
            BkAssessmentQuestion::query()->updateOrCreate(
                ['template_id' => $template->id, 'code' => $question['code']],
                $question + ['section_id' => $section->id, 'sort_order' => $index + 1, 'is_required' => false],
            );
        }

        $feelings = BkAssessmentSection::query()->updateOrCreate(
            ['template_id' => $template->id, 'code' => 'S2'],
            ['title' => 'Perasaan & dukungan', 'sort_order' => 2],
        );

        BkAssessmentQuestion::query()->updateOrCreate(
            ['template_id' => $template->id, 'code' => 'Q6'],
            [
                'section_id' => $feelings->id,
                'question' => 'Bagaimana perasaanmu secara umum belakangan ini?',
                'question_type' => AssessmentQuestionType::SINGLE_CHOICE->value,
                'options' => ['Baik', 'Biasa saja', 'Kadang sedih', 'Sering sedih'],
                'sort_order' => 1,
                'is_sensitive' => true,
                'is_scored' => false,
            ],
        );

        BkAssessmentQuestion::query()->updateOrCreate(
            ['template_id' => $template->id, 'code' => 'Q7'],
            [
                'section_id' => $feelings->id,
                'question' => 'Dukungan apa yang kamu harapkan dari sekolah?',
                'question_type' => AssessmentQuestionType::TEXTAREA->value,
                'sort_order' => 2,
                'is_sensitive' => true,
                'is_scored' => false,
            ],
        );
    }

    private function parentViewTemplate(): void
    {
        $template = BkAssessmentTemplate::query()->updateOrCreate(
            ['code' => 'BK-PARENT-VIEW'],
            [
                'name' => 'Pandangan Orang Tua',
                'target' => AssessmentTarget::PARENT->value,
                'description' => 'Pandangan orang tua terhadap kondisi belajar & perilaku anak di rumah.',
                'purpose' => 'Melengkapi gambaran konselor dari sudut pandang keluarga.',
                'is_active' => true,
                'is_default' => false,
                'requires_consent' => true,
            ],
        );

        $section = BkAssessmentSection::query()->updateOrCreate(
            ['template_id' => $template->id, 'code' => 'S1'],
            ['title' => 'Amatan di rumah', 'sort_order' => 1],
        );

        $questions = [
            ['code' => 'P1', 'question' => 'Bagaimana perubahan belajar anak Anda dalam tiga bulan terakhir?', 'question_type' => AssessmentQuestionType::SINGLE_CHOICE->value, 'options' => ['Membaik', 'Tetap', 'Menurun'], 'is_scored' => false],
            ['code' => 'P2', 'question' => 'Hal apa yang paling Anda khawatirkan?', 'question_type' => AssessmentQuestionType::TEXTAREA->value, 'is_scored' => false, 'is_sensitive' => true],
            ['code' => 'P3', 'question' => 'Apakah anak Anda bercerita tentang kesulitannya di sekolah?', 'question_type' => AssessmentQuestionType::BOOLEAN->value, 'is_scored' => false],
            ['code' => 'P4', 'question' => 'Dukungan apa yang dapat keluarga berikan?', 'question_type' => AssessmentQuestionType::TEXTAREA->value, 'is_scored' => false],
        ];

        foreach ($questions as $index => $question) {
            BkAssessmentQuestion::query()->updateOrCreate(
                ['template_id' => $template->id, 'code' => $question['code']],
                $question + ['section_id' => $section->id, 'sort_order' => $index + 1],
            );
        }
    }
}
