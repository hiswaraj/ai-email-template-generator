<?php

use App\Http\Controllers\Api\EmailTemplateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/email-templates/generate', [EmailTemplateController::class, 'generate']);
});
