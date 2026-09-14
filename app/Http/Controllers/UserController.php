<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        $users = User::all();
        return view('users.index', compact('users'));
    }

    public function create()
    {
        return view('users.create');
    }

    public function store(Request $request)
    {
        // Bug 7 Fix: $request->all() berbahaya, gunakan only() + isi role eksplisit
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'nim_nip'  => 'nullable|string|unique:users,nim_nip',
            'role'     => 'required|in:admin,dosen,mahasiswa',
        ]);

        User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => $validated['password'],
            'nim_nip'  => $validated['nim_nip'] ?? null,
            'role'     => $validated['role'],   // diisi eksplisit setelah validasi
        ]);

        return redirect()->route('users.index')->with('success', 'User berhasil ditambahkan');
    }

    public function show(User $user)
    {
        return view('users.show', compact('user'));
    }

    public function edit(User $user)
    {
        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        // Bug 7 Fix: $request->all() berbahaya di update juga
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'required|email|unique:users,email,' . $user->id,
            'nim_nip' => 'nullable|string|unique:users,nim_nip,' . $user->id,
            'role'    => 'required|in:admin,dosen,mahasiswa',
        ]);

        $user->update([
            'name'    => $validated['name'],
            'email'   => $validated['email'],
            'nim_nip' => $validated['nim_nip'] ?? null,
            'role'    => $validated['role'],
        ]);

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return redirect()->route('users.index')->with('success', 'User berhasil dihapus');
    }
}
