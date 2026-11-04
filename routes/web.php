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
    return view('indexMidtrans');
});

Route::get('/og/campaign/{id}', [\App\Http\Controllers\OgCampaignController::class, 'show'])
    ->whereNumber('id');
Route::get('/og/campaign/{id}/json', [\App\Http\Controllers\OgCampaignController::class, 'json'])
    ->whereNumber('id');
