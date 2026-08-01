<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $activeBranchCount = Branch::where('is_active', true)->count();
        $activeUserCount = User::where('is_active', true)->count();

        return view('dashboard', compact('activeBranchCount', 'activeUserCount'));
    }
}
