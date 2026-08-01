<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // Route names registered ahead of their controllers (Task 2/3 of the
    // Administrasi CRUD UI plan) so the sidebar's route() calls resolve.
    // Route::get() only needs the controller class to exist once the route
    // is actually dispatched, not at registration/URL-generation time.
    Route::get('/branches', [App\Http\Controllers\BranchController::class, 'index'])->name('branches.index');
    Route::get('/users', [App\Http\Controllers\UserController::class, 'index'])->name('users.index');
});
