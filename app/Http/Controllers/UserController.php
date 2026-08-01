<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Menu;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        $this->authorize('user.view');

        $users = User::orderBy('name')->simplePaginate(15);

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $this->authorize('user.create');

        return view('users.create');
    }

    public function store(Request $request)
    {
        $this->authorize('user.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'password' => ['required', 'string', 'min:6'],
        ]);

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
        $menus = Menu::with(['permissions' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($menu) => $menu->permissions->isNotEmpty());
        $grantedPermissionIds = $user->userPermissions()->pluck('permission_id')->all();

        return view('users.show', compact('user', 'allBranches', 'menus', 'grantedPermissionIds'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('user.edit');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'username' => ['required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
        ]);

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
