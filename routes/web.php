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
    

// Route::get('/debug-node', function () {
//     return response()->json([
//         'NODE_BIN_env' => env('NODE_BIN'),
//         'NODE_BIN_cfg' => config('services.node_bin'),
//         'exists' => is_file(config('services.node_bin') ?? ''),
//         'exec' => is_executable(config('services.node_bin') ?? ''),
//     ]);
// });


Route::get('/debug-node', function () {
    $envPath = app()->environmentFilePath();

    $nodeEnv = env('NODE_BIN');
    $nodeCfg = config('services.node_bin');

    $existsEnv = $nodeEnv ? file_exists($nodeEnv) : false;
    $execEnv   = $nodeEnv ? is_executable($nodeEnv) : false;

    return response()->json([
        'base_path' => base_path(),
        'env_file'  => $envPath,
        'env_exists' => file_exists($envPath),

        'NODE_BIN_env' => $nodeEnv,
        'NODE_BIN_cfg' => $nodeCfg,

        'exists_env' => $existsEnv,
        'exec_env'   => $execEnv,

        // show if config is cached
        'config_cached' => file_exists(base_path('bootstrap/cache/config.php')),
    ]);
});

