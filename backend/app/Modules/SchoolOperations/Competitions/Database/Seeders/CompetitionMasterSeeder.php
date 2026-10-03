<?php

declare(strict_types=1);

namespace App\Modules\SchoolOperations\Competitions\Database\Seeders;

use App\Modules\SchoolOperations\Competitions\Models\CompetitionDivision;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionDomain;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionFormat;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionLevel;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTemplate;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTemplateCategory;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionTemplateRound;
use App\Modules\SchoolOperations\Competitions\Models\CompetitionType;
use Illuminate\Database\Seeder;

/** Configurable reference data and reusable templates; no fake live competition records. */
class CompetitionMasterSeeder extends Seeder
{
    public function run(): void
    {
        $domains = [
            ['ACADEMIC', 'Akademik', '#2563eb', 'graduation-cap'],
            ['SPORTS', 'Olahraga', '#16a34a', 'trophy'],
            ['ARTS', 'Seni', '#9333ea', 'palette'],
            ['RELIGIOUS', 'Keagamaan', '#0f766e', 'moon-star'],
            ['TECHNOLOGY', 'Teknologi', '#0891b2', 'cpu'],
            ['LANGUAGE', 'Bahasa', '#ea580c', 'languages'],
            ['LEADERSHIP', 'Kepemimpinan', '#475569', 'users'],
            ['ENTREPRENEURSHIP', 'Kewirausahaan', '#ca8a04', 'lightbulb'],
            ['SCIENCE', 'Sains', '#0284c7', 'flask-conical'],
            ['SOCIAL', 'Sosial', '#db2777', 'heart-handshake'],
            ['OTHER', 'Lainnya', '#64748b', 'sparkles'],
        ];
        $domainIds = [];
        foreach ($domains as $i => [$code, $name, $color, $icon]) {
            $row = CompetitionDomain::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'color' => $color, 'icon' => $icon, 'sort_order' => $i, 'is_active' => true,
            ]);
            $domainIds[$code] = $row->id;
        }

        $formats = [
            ['SINGLE_ROUND', 'Single round', 'SINGLE', false, false, false],
            ['MULTI_ROUND', 'Multi-round', 'MULTI', true, false, false],
            ['KNOCKOUT', 'Knockout', 'BRACKET', true, true, false],
            ['ROUND_ROBIN', 'Round robin', 'PAIRING', false, false, true],
            ['SWISS', 'Swiss system', 'PAIRING', true, false, true],
            ['GROUP_STAGE', 'Group stage', 'GROUP', true, false, false],
            ['GROUP_KNOCKOUT', 'Group + knockout', 'HYBRID', true, true, true],
            ['QUALIFICATION_FINAL', 'Qualification + final', 'MULTI', true, false, false],
            ['SUBMISSION_JUDGING', 'Submission → judging', 'SUBMISSION', true, false, false],
            ['CONTINUOUS', 'Continuous scoring', 'CONTINUOUS', false, false, false],
            ['TIME_BASED', 'Time-based', 'RACE', false, false, false],
            ['POINT_BASED', 'Point-based', 'POINTS', false, false, false],
            ['BEST_OF_N', 'Best-of-N', 'SERIES', true, false, false],
            ['EXHIBITION', 'Exhibition', 'SHOWCASE', false, false, false],
        ];
        foreach ($formats as $i => [$code, $name, $family, $advancement, $bracket, $pairing]) {
            CompetitionFormat::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'family' => $family, 'supports_advancement' => $advancement,
                'requires_bracket' => $bracket, 'requires_pairing' => $pairing, 'sort_order' => $i, 'is_active' => true,
            ]);
        }

        $typeRows = [
            ['TOURNAMENT', 'Turnamen', 'SPORTS', 'KNOCKOUT', 'POINTS', 'TEAM', true, true, false, true, true],
            ['OLYMPIAD', 'Olimpiade', 'ACADEMIC', 'QUALIFICATION_FINAL', 'NUMERIC', 'INDIVIDUAL', false, true, false, true, false],
            ['QUIZ', 'Quiz', 'ACADEMIC', 'MULTI_ROUND', 'POINTS', 'TEAM', true, true, false, true, false],
            ['DEBATE', 'Debat', 'LANGUAGE', 'KNOCKOUT', 'WEIGHTED', 'TEAM', true, true, false, true, true],
            ['SPEECH', 'Pidato', 'LANGUAGE', 'SINGLE_ROUND', 'WEIGHTED', 'INDIVIDUAL', false, true, false, false, false],
            ['PRESENTATION', 'Presentasi', 'ACADEMIC', 'SINGLE_ROUND', 'WEIGHTED', 'MIXED', false, true, true, false, false],
            ['PERFORMANCE', 'Pertunjukan', 'ARTS', 'SINGLE_ROUND', 'WEIGHTED', 'INDIVIDUAL', false, true, false, false, false],
            ['SPORTS', 'Olahraga', 'SPORTS', 'ROUND_ROBIN', 'POINTS', 'TEAM', true, false, false, true, true],
            ['PROJECT', 'Project competition', 'TECHNOLOGY', 'SUBMISSION_JUDGING', 'WEIGHTED', 'MIXED', false, true, true, false, false],
            ['SUBMISSION', 'Kompetisi berbasis karya', 'ACADEMIC', 'SUBMISSION_JUDGING', 'WEIGHTED', 'INDIVIDUAL', false, true, true, false, false],
            ['JUDGED_PERFORMANCE', 'Penampilan dinilai juri', 'ARTS', 'SINGLE_ROUND', 'WEIGHTED', 'INDIVIDUAL', false, true, false, false, false],
            ['RACE', 'Balapan/lomba waktu', 'SPORTS', 'TIME_BASED', 'TIME', 'INDIVIDUAL', false, false, false, false, false],
            ['EXAMINATION', 'Ujian kompetisi', 'ACADEMIC', 'QUALIFICATION_FINAL', 'NUMERIC', 'INDIVIDUAL', false, false, false, true, false],
            ['EXHIBITION', 'Pameran', 'ARTS', 'EXHIBITION', 'PLACEMENT', 'MIXED', false, true, true, false, false],
            ['CHALLENGE', 'Tantangan', 'TECHNOLOGY', 'POINT_BASED', 'POINTS', 'MIXED', false, true, false, true, false],
            ['CODING', 'Coding & robotik', 'TECHNOLOGY', 'SUBMISSION_JUDGING', 'WEIGHTED', 'MIXED', false, true, true, true, false],
            ['RELIGIOUS', 'Kompetisi keagamaan', 'RELIGIOUS', 'SINGLE_ROUND', 'WEIGHTED', 'INDIVIDUAL', false, true, false, true, false],
        ];
        $typeIds = [];
        foreach ($typeRows as $i => [$code, $name, $domain, $format, $scoring, $mode, $team, $judge, $submission, $multi, $bracket]) {
            $row = CompetitionType::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'competition_domain_id' => $domainIds[$domain], 'default_format' => $format,
                'default_scoring_model' => $scoring, 'default_ranking_model' => 'HIGHER_BETTER',
                'participant_mode' => $mode, 'requires_team' => $team, 'requires_judge' => $judge,
                'requires_submission' => $submission, 'allows_multiple_rounds' => $multi,
                'supports_bracket' => $bracket, 'supports_heat' => $code === 'RACE', 'sort_order' => $i, 'is_active' => true,
            ]);
            $typeIds[$code] = $row->id;
        }

        $levels = [
            ['INTERNAL', 'Internal sekolah', 'INTERNAL', 1], ['CLASS', 'Antar kelas', 'INTERNAL', 2],
            ['DORMITORY', 'Antar asrama', 'INTERNAL', 3], ['UNIT', 'Antar unit', 'INTERNAL', 4],
            ['CAMPUS', 'Antar kampus', 'INTERNAL', 5], ['SCHOOL', 'Tingkat sekolah', 'INTERNAL', 6],
            ['DISTRICT', 'Kecamatan/kabupaten', 'EXTERNAL', 7], ['REGIONAL', 'Regional', 'EXTERNAL', 8],
            ['NATIONAL', 'Nasional', 'EXTERNAL', 9], ['INTERNATIONAL', 'Internasional', 'EXTERNAL', 10],
        ];
        foreach ($levels as $i => [$code, $name, $scope, $rank]) {
            CompetitionLevel::query()->updateOrCreate(['code' => $code], ['name' => $name, 'scope' => $scope, 'rank' => $rank, 'sort_order' => $i, 'is_active' => true]);
        }

        $divisions = [
            ['SD', 'SD', 'EDUCATION_LEVEL', 7, 12, 'SD', 'SD', 'ANY', null],
            ['SMP', 'SMP', 'EDUCATION_LEVEL', 12, 16, 'SMP', 'SMP', 'ANY', null],
            ['SMA', 'SMA/SMK', 'EDUCATION_LEVEL', 15, 20, 'SMA', 'SMA', 'ANY', null],
            ['STUDENT', 'Mahasiswa', 'PARTICIPANT', 17, 35, 'MAHASISWA', 'MAHASISWA', 'ANY', null],
            ['TEACHER', 'Guru', 'PARTICIPANT', null, null, null, null, 'ANY', null],
            ['STAFF', 'Staf', 'PARTICIPANT', null, null, null, null, 'ANY', null],
            ['GENERAL', 'Umum', 'PARTICIPANT', null, null, null, null, 'ANY', null],
            ['BEGINNER', 'Pemula', 'SKILL', null, null, null, null, 'ANY', 'BEGINNER'],
            ['INTERMEDIATE', 'Menengah', 'SKILL', null, null, null, null, 'ANY', 'INTERMEDIATE'],
            ['ADVANCED', 'Lanjutan', 'SKILL', null, null, null, null, 'ANY', 'ADVANCED'],
        ];
        foreach ($divisions as $i => [$code, $name, $type, $minAge, $maxAge, $minGrade, $maxGrade, $gender, $skill]) {
            CompetitionDivision::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'division_type' => $type, 'min_age' => $minAge, 'max_age' => $maxAge,
                'min_grade_level' => $minGrade, 'max_grade_level' => $maxGrade, 'gender' => $gender,
                'skill_level' => $skill, 'sort_order' => $i, 'is_active' => true,
            ]);
        }

        $this->seedTemplates($domainIds, $typeIds);
    }

    private function seedTemplates(array $domainIds, array $typeIds): void
    {
        $templates = [
            ['MATH-OLYMPIAD', 'Mathematics Olympiad Template', 'OLYMPIAD', 'ACADEMIC', 'QUALIFICATION_FINAL', 'INDIVIDUAL', 'NUMERIC', [
                ['SD', 'SD'], ['SMP', 'SMP'], ['SMA', 'SMA/SMK'],
            ], [['QUAL', 'Kualifikasi', 'QUALIFICATION', 'SINGLE', 1], ['FINAL', 'Final', 'FINAL', 'SINGLE', 2]]],
            ['DEBATE-TOURNAMENT', 'Debate Tournament Template', 'DEBATE', 'LANGUAGE', 'KNOCKOUT', 'TEAM', 'WEIGHTED', [
                ['JUNIOR', 'Junior'], ['SENIOR', 'Senior'],
            ], [['PRELIM', 'Penyisihan', 'QUALIFICATION', 'ROUND_ROBIN', 1], ['FINAL', 'Final', 'FINAL', 'KNOCKOUT', 2]]],
            ['BASKETBALL', 'Basketball Tournament Template', 'SPORTS', 'SPORTS', 'ROUND_ROBIN', 'TEAM', 'POINTS', [
                ['PUTRA', 'Putra'], ['PUTRI', 'Putri'], ['MIXED', 'Campuran'],
            ], [['GROUP', 'Group stage', 'QUALIFICATION', 'ROUND_ROBIN', 1], ['FINAL', 'Final', 'FINAL', 'KNOCKOUT', 2]]],
            ['TAHFIDZ', 'Tahfidz Competition Template', 'RELIGIOUS', 'RELIGIOUS', 'SINGLE_ROUND', 'INDIVIDUAL', 'WEIGHTED', [
                ['JUZ-30', 'Juz 30'], ['JUZ-1-5', 'Juz 1–5'], ['MURATALL', 'Muratall'],
            ], [['FINAL', 'Penilaian utama', 'FINAL', 'SINGLE', 1]]],
            ['SPEECH', 'Speech Competition Template', 'SPEECH', 'LANGUAGE', 'SINGLE_ROUND', 'INDIVIDUAL', 'WEIGHTED', [
                ['INDONESIAN', 'Bahasa Indonesia'], ['ENGLISH', 'Bahasa Inggris'],
            ], [['FINAL', 'Penampilan', 'FINAL', 'SINGLE', 1]]],
            ['CODING', 'Coding Competition Template', 'CODING', 'TECHNOLOGY', 'SUBMISSION_JUDGING', 'MIXED', 'WEIGHTED', [
                ['WEB', 'Web development'], ['ALGORITHM', 'Algoritma'], ['ROBOTICS', 'Robotik'],
            ], [['QUAL', 'Seleksi karya', 'QUALIFICATION', 'SUBMISSION_JUDGING', 1], ['FINAL', 'Presentasi final', 'FINAL', 'SINGLE', 2]]],
        ];
        foreach ($templates as [$code, $name, $type, $domain, $format, $mode, $scoring, $categories, $rounds]) {
            $template = CompetitionTemplate::query()->updateOrCreate(['code' => $code], [
                'name' => $name, 'description' => $name.' untuk membuat edition tanpa menyalin data historis.',
                'competition_type_id' => $typeIds[$type], 'competition_domain_id' => $domainIds[$domain],
                'default_participant_mode' => $mode, 'default_scoring_model' => $scoring,
                'default_ranking_model' => 'HIGHER_BETTER', 'requires_eligibility' => true,
                'requires_parent_consent' => false, 'requires_selection' => false, 'is_active' => true,
            ]);
            foreach ($categories as $index => [$categoryCode, $categoryName]) {
                CompetitionTemplateCategory::query()->updateOrCreate(
                    ['template_id' => $template->id, 'code' => $categoryCode],
                    ['name' => $categoryName, 'participant_mode' => $mode, 'scoring_model' => $scoring, 'ranking_model' => 'HIGHER_BETTER', 'sort_order' => $index],
                );
            }
            foreach ($rounds as [$roundCode, $roundName, $roundType, $roundFormat, $sequence]) {
                CompetitionTemplateRound::query()->updateOrCreate(
                    ['template_id' => $template->id, 'code' => $roundCode],
                    ['name' => $roundName, 'sequence' => $sequence, 'round_type' => $roundType, 'format' => $roundFormat, 'advancement_rule' => 'RANKING', 'sort_order' => $sequence],
                );
            }
        }
    }
}
