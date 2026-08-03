<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Branch;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $this->authorize('user.view');

        $branchIds = collect(request('branch_ids', []))
            ->map(fn ($id) => (int) $id)
            ->intersect(auth()->user()->branches->pluck('id'))
            ->values()->all();

        $search = is_string(request('q')) ? trim(request('q')) : null;

        $users = User::orderBy('name')
            ->when($search, function ($query, $q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', '%' . addcslashes($q, '%_\\') . '%')
                        ->orWhere('username', 'like', '%' . addcslashes($q, '%_\\') . '%');
                });
            })
            ->when($branchIds, fn ($query) => $query->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds)))
            ->simplePaginate(15)
            ->withQueryString();

        $userBranches = auth()->user()->branches;

        return view('users.index', compact('users'))
            ->with('branches', $userBranches)
            ->with('selectedBranchIds', $branchIds)
            ->with('search', $search);
    }

    public function create()
    {
        $this->authorize('user.create');

        return view('users.create');
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('users.index')->with('status', 'User berhasil ditambahkan.');
    }

    public function show(User $user)
    {
        $this->authorize('user.view');

        $user->load('userBranches');
        $allBranches = Branch::orderBy('name')->get();
        $assignedBranches = $user->branches;

        $branchScopedMenus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->where('is_branch_scoped', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());

        $globalMenus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->where('is_branch_scoped', false)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());

        $grantedPermissionIds = $user->userPermissions()->pluck('permission_id')->all();

        $grantedBranchPermissionIds = $user->userBranchPermissions()
            ->get()
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->pluck('permission_id')->all());

        return view('users.show', compact(
            'user', 'allBranches', 'assignedBranches', 'branchScopedMenus', 'globalMenus',
            'grantedPermissionIds', 'grantedBranchPermissionIds'
        ));
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        $user->name = $data['name'];
        $user->username = $data['username'];

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->is_active = $user->id === $request->user()->id
            ? true
            : $request->boolean('is_active');

        $user->save();

        return redirect()->route('users.show', $user)->with('status', 'User berhasil diperbarui.');
    }
}
