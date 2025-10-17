<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DynamicDataController;
use App\Http\Controllers\DynamicInsert;
use App\Http\Controllers\Auth2FA;
use App\Http\Controllers\Procedure;
use App\Http\Controllers\Upload;
use App\Http\Controllers\MailController;


Route::middleware('auth.token')->get('/me', function (Request $request) {
    return $request->user();
});

Route::controller(AuthController::class)->group(function ($router) {
    Route::get('auth/login', 'login')->name('login');
    Route::post('auth/register', 'register')->name('register');
    Route::post('auth/logout', 'logout')->name('logout')->middleware('auth.token');
    Route::post('auth/refresh', 'refresh')->name('refresh');
    Route::post('auth/reset', 'reset')->name('reset');
    Route::get('auth/staging', 'getStaging')->name('getStaging');
    Route::middleware('auth.token')->patch('auth/change-pass', 'changePass')->name('changePass');
});

Route::get('/view/trainer', [DynamicDataController::class, 'getTrainer']);
Route::middleware('auth.token')->get('/data/{table}', [DynamicDataController::class, 'fetchData']);
Route::middleware('auth.token')->post('/data/{table}/upsert', [DynamicInsert::class, 'upsertData']);

Route::middleware('auth.token')->post('/auth/2fa/status', [Auth2FA::class, 'status']);
Route::middleware('auth.token')->post('/auth/2fa/verify', [Auth2FA::class, 'verify']);

Route::middleware('auth.token')->post('/call/procedure', [Procedure::class, 'callProcedure']);

Route::middleware('auth.token')->post('/upload-image', [Upload::class, 'imgUpload']);
Route::middleware('auth.token')->post('/upload-doc', [Upload::class, 'docUpload']);

Route::post('/email/send', [MailController::class, 'sendMail']);
Route::post('/email/reset', [MailController::class, 'sendResetPasswordEmail']);


