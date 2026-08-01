<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\UserBranchAssignmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionAssignmentController;
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
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Route names registered ahead of their controllers (Task 2/3 of the
    // Administrasi CRUD UI plan) so the sidebar's route() calls resolve.
    // Route::get() only needs the controller class to exist once the route
    // is actually dispatched, not at registration/URL-generation time.
    Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
    Route::get('/branches/create', [BranchController::class, 'create'])->name('branches.create');
    Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
    Route::get('/branches/{branch}/edit', [BranchController::class, 'edit'])->name('branches.edit');
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

    Route::post('/users/{user}/branches/{branch}', [UserBranchAssignmentController::class, 'store'])->name('users.branches.store');
    Route::delete('/users/{user}/branches/{branch}', [UserBranchAssignmentController::class, 'destroy'])->name('users.branches.destroy');
    Route::put('/users/{user}/branches/{branch}/default', [UserBranchAssignmentController::class, 'setDefault'])->name('users.branches.setDefault');

    Route::post('/users/{user}/permissions/{permission}', [UserPermissionAssignmentController::class, 'store'])->name('users.permissions.store');
    Route::delete('/users/{user}/permissions/{permission}', [UserPermissionAssignmentController::class, 'destroy'])->name('users.permissions.destroy');
});
