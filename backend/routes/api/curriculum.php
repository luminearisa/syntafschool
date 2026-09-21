<?php

use App\Http\Controllers\Api\CurriculumStructureController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\SubjectGroupController;
use App\Http\Controllers\Api\SubjectPrerequisiteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| A5 — Kurikulum Engine
|--------------------------------------------------------------------------
|
| File ini dimuat otomatis oleh routes/api.php di dalam grup `v1` +
| `auth:sanctum`, jadi tidak perlu prefix maupun middleware auth di sini.
| Otorisasi tetap per-route lewat `permission:academic.*`.
|
*/

// ------------------------------------------------- Kelompok mata pelajaran
Route::get('subject-groups', [SubjectGroupController::class, 'index'])
    ->middleware('permission:academic.view')->name('subject-groups.index');
Route::post('subject-groups', [SubjectGroupController::class, 'store'])
    ->middleware('permission:academic.create')->name('subject-groups.store');
Route::get('subject-groups/{subjectGroup}', [SubjectGroupController::class, 'show'])
    ->middleware('permission:academic.view')->name('subject-groups.show');
Route::match(['put', 'patch'], 'subject-groups/{subjectGroup}', [SubjectGroupController::class, 'update'])
    ->middleware('permission:academic.update')->name('subject-groups.update');
Route::delete('subject-groups/{subjectGroup}', [SubjectGroupController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('subject-groups.destroy');

// ------------------------------------------------------- Program / peminatan
Route::get('programs', [ProgramController::class, 'index'])
    ->middleware('permission:academic.view')->name('programs.index');
Route::post('programs', [ProgramController::class, 'store'])
    ->middleware('permission:academic.create')->name('programs.store');
Route::get('programs/{program}', [ProgramController::class, 'show'])
    ->middleware('permission:academic.view')->name('programs.show');
Route::match(['put', 'patch'], 'programs/{program}', [ProgramController::class, 'update'])
    ->middleware('permission:academic.update')->name('programs.update');
Route::delete('programs/{program}', [ProgramController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('programs.destroy');

// --------------------------------------------------------- Prasyarat mapel
Route::get('subject-prerequisites', [SubjectPrerequisiteController::class, 'index'])
    ->middleware('permission:academic.view')->name('subject-prerequisites.index');
Route::post('subject-prerequisites', [SubjectPrerequisiteController::class, 'store'])
    ->middleware('permission:academic.create')->name('subject-prerequisites.store');
Route::get('subject-prerequisites/{subjectPrerequisite}', [SubjectPrerequisiteController::class, 'show'])
    ->middleware('permission:academic.view')->name('subject-prerequisites.show');
Route::match(['put', 'patch'], 'subject-prerequisites/{subjectPrerequisite}', [SubjectPrerequisiteController::class, 'update'])
    ->middleware('permission:academic.update')->name('subject-prerequisites.update');
Route::delete('subject-prerequisites/{subjectPrerequisite}', [SubjectPrerequisiteController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('subject-prerequisites.destroy');

// ------------------------------------------------- Struktur kurikulum
Route::get('curricula/{curriculum}/structures', [CurriculumStructureController::class, 'byCurriculum'])
    ->middleware('permission:academic.view')->name('curricula.structures');

Route::get('curriculum-structures', [CurriculumStructureController::class, 'index'])
    ->middleware('permission:academic.view')->name('curriculum-structures.index');
Route::post('curriculum-structures', [CurriculumStructureController::class, 'store'])
    ->middleware('permission:academic.create')->name('curriculum-structures.store');
Route::post('curriculum-structures/{curriculumStructure}/duplicate', [CurriculumStructureController::class, 'duplicate'])
    ->middleware('permission:academic.create')->name('curriculum-structures.duplicate');
Route::get('curriculum-structures/{curriculumStructure}', [CurriculumStructureController::class, 'show'])
    ->middleware('permission:academic.view')->name('curriculum-structures.show');
Route::match(['put', 'patch'], 'curriculum-structures/{curriculumStructure}', [CurriculumStructureController::class, 'update'])
    ->middleware('permission:academic.update')->name('curriculum-structures.update');
Route::delete('curriculum-structures/{curriculumStructure}', [CurriculumStructureController::class, 'destroy'])
    ->middleware('permission:academic.delete')->name('curriculum-structures.destroy');

// ------------------------------------------------- Kurikulum per kelas
Route::get('classes/{class}/curriculum', [CurriculumStructureController::class, 'classCurriculum'])
    ->middleware('permission:academic.view')->name('classes.curriculum');
Route::post('classes/{class}/apply-curriculum', [CurriculumStructureController::class, 'applyCurriculum'])
    ->middleware('permission:academic.create')->name('classes.apply-curriculum');
