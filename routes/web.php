<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health', function () {
    return response()->json([
        'service' => 'WhatsApp AI Agent',
        'status' => 'running',
        'version' => '1.0.0',
    ]);
});
