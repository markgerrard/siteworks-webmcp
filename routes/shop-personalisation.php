<?php

use App\Http\Controllers\Shop\PersonalisationFileController;
use Illuminate\Support\Facades\Route;

Route::get('/shop/personalisation', [PersonalisationFileController::class, 'show'])
    ->middleware('signed')
    ->name('shop.personalisation.show');
