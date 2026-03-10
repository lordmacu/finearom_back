<?php

use Illuminate\Support\Facades\Route;

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

// Google OAuth callback (Google redirige aquí con code + state)
Route::get('/auth/google/callback', [\App\Http\Controllers\GoogleAuthController::class, 'callback'])
    ->name('google.callback');
