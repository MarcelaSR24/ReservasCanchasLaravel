<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SoapController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome'));

Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1')->name('register.submit');
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', [AuthController::class, 'dashboard'])->name('dashboard');
    Route::post('/soap/validate-token', [AuthController::class, 'validateSoapToken'])->name('soap.validate');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

Route::match(['GET', 'POST'], '/soap', [SoapController::class, 'handle']);
