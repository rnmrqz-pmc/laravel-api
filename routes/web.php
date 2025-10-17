<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\MailController;

Route::get('/view/file/{base64}', function($base64) {
    $payload = json_decode(base64_decode($base64));
    $payload->path = str_replace('/storage', '', $payload->path);
    $path = storage_path('app/uploads' . $payload->path );
    if (!file_exists($path)) {
        abort(404);
    }
    return Response::file($path);
});

Route::get('/images/{filename}', function($filename) {
    $path = storage_path('app/uploads/images/' . $filename);
    if (!file_exists($path)) {
        abort(404);
    }
    return Response::file($path);
});

Route::get('/doc/{filename}', function($filename) {
    $path = storage_path('app/uploads/documents/' . $filename);
    if (!file_exists($path)) {
        abort(404);
    }
    return Response::file($path);
});