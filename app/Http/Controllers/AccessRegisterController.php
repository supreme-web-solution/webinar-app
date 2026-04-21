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
    public function show(Request $request): Response
    {
        $allPermissions = ['FE', 'Bundle', 'Reseller'];
        $planMapping = [
            'fe' => 'FE',
            'bundle' => 'Bundle',
            'reseller' => 'Reseller',
        ];
        $plan = $request->query('plan');
        $normalizedPlan = isset($planMapping[strtolower($plan)]) ? $planMapping[strtolower($plan)] : null;

        if ($normalizedPlan && in_array($normalizedPlan, $allPermissions)) {
            $permissions = [$normalizedPlan];
        } else {
            $permissions = $allPermissions;
        }

        return Inertia::render('Access/Register', [
            'permissions' => $permissions,
            'selectedPlan' => $normalizedPlan,
        ]);
    }

    public function register(Request $request)
    {
        $allPermissions = ['FE', 'Bundle', 'Reseller'];
        $planMapping = [
            'fe' => 'FE',
            'bundle' => 'Bundle',
            'reseller' => 'Reseller',
        ];
        $plan = $request->query('plan');
        $normalizedPlan = isset($planMapping[strtolower($plan)]) ? $planMapping[strtolower($plan)] : null;
        $allowedPermissions = $normalizedPlan ? [$normalizedPlan] : $allPermissions;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'permission' => ['required', 'in:' . implode(',', $allowedPermissions)],
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
