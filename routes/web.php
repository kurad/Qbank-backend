<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    

Route::get('/debug-node', function () {
    return response()->json([
        'NODE_BIN_env' => env('NODE_BIN'),
        'NODE_BIN_cfg' => config('services.node_bin'),
        'exists' => is_file(config('services.node_bin') ?? ''),
        'exec' => is_executable(config('services.node_bin') ?? ''),
    ]);
});
