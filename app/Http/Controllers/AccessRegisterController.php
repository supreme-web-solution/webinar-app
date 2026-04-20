<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccessRegisterController extends Controller
{
    public function show(): Response
    {
        $permissions = Permission::whereIn('name', ['FE', 'Bundle', 'Reseller'])->pluck('name');
        return Inertia::render('Access/Register', [
            'permissions' => $permissions,
        ]);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'permission' => 'required|in:FE,Bundle,Reseller',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $user->givePermissionTo($request->permission);
        Auth::login($user);

        return redirect('/dashboard');
    }
}
