<?php

use Illuminate\Support\Facades\Route;
use dnj\ComposerGateway\Http\Controllers\PackagesController;

Route::get('/packages', [PackagesController::class, 'packages'])->where('path', '.*');
Route::get('/packages.json', [PackagesController::class, 'packages'])->where('path', '.*');
Route::get('/{path}/packages', [PackagesController::class, 'packages'])->where('path', '.*');
Route::get('/{path}/packages.json', [PackagesController::class, 'packages'])->where('path', '.*');
Route::get('/{path}/p2/{vendor}/{package}', [PackagesController::class, 'packages'])->where('path', '.*');
Route::get('/{path}/p2/{vendor}/{package}.json', [PackagesController::class, 'packages'])->where('path', '.*');
