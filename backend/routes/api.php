<?php

use App\Api\Controllers\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks', [TaskController::class, 'store']);
Route::get('/tasks/{taskId}', [TaskController::class, 'show']);
Route::patch('/tasks/{taskId}', [TaskController::class, 'update']);
Route::delete('/tasks/{taskId}', [TaskController::class, 'destroy']);
Route::post('/tasks/{taskId}/split', [TaskController::class, 'split']);
