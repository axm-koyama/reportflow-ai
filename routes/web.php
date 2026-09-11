<?php

use App\Http\Controllers\AnalysisJobController;
use App\Http\Controllers\DataFileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('projects.index');
});

Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');

Route::prefix('projects/{project}')
    ->name('projects.')
    ->group(function () {
        Route::get('/data-files', [DataFileController::class, 'index'])
            ->name('data-files.index');

        Route::post('/data-files', [DataFileController::class, 'store'])
            ->name('data-files.store');
    });

Route::prefix('projects/{project}/data-files/{dataFile}')
    ->name('projects.data-files.')
    ->group(function () {
        Route::get('/analysis-jobs/create', [AnalysisJobController::class, 'create'])
            ->name('analysis-jobs.create');

        Route::post('/analysis-jobs', [AnalysisJobController::class, 'store'])
            ->name('analysis-jobs.store');
    });

Route::get('/projects/{project}/analysis-jobs', [AnalysisJobController::class, 'index'])
    ->name('projects.analysis-jobs.index');

Route::get('/projects/{project}/analysis-jobs/{analysisJob}', [AnalysisJobController::class, 'show'])
    ->name('projects.analysis-jobs.show');

Route::post('/projects/{project}/analysis-jobs/{analysisJob}/recover', [AnalysisJobController::class, 'recover'])
    ->name('projects.analysis-jobs.recover');

Route::post('/projects/{project}/analysis-jobs/{analysisJob}/report', [ReportController::class, 'store'])
    ->name('projects.analysis-jobs.reports.store');

Route::get('/projects/{project}/reports/{report}', [ReportController::class, 'show'])
    ->name('projects.reports.show');

Route::get('/projects/{project}/analysis-jobs/{analysisJob}/mapping', [AnalysisJobController::class, 'editMapping'])
    ->name('projects.analysis-jobs.mapping.edit');

Route::patch('/projects/{project}/analysis-jobs/{analysisJob}/mapping', [AnalysisJobController::class, 'updateMapping'])
    ->name('projects.analysis-jobs.mapping.update');
