<?php

declare(strict_types=1);

use App\Modules\SchoolOperations\Competitions\Http\Controllers\CompetitionPublicController;
use Illuminate\Support\Facades\Route;

Route::get('{slug}', [CompetitionPublicController::class, 'show'])->name('show');
