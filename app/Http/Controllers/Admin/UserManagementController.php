<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $verification = trim((string) $request->query('verification', 'all'));
        $perPage = min(100, max(10, (int) $request->query('per_page', 20)));

        $query = User::query()->latest('id');

        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        }

        if ($verification === 'verified') {
            $query->whereNotNull('email_verified_at');
        } elseif ($verification === 'unverified') {
            $query->whereNull('email_verified_at');
        }

        $users = $query->paginate($perPage)->withQueryString()
            ->through(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->toDateTimeString(),
                'created_at' => $user->created_at?->toDateTimeString(),
                'updated_at' => $user->updated_at?->toDateTimeString(),
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'verification' => in_array($verification, ['all', 'verified', 'unverified'], true) ? $verification : 'all',
                'per_page' => $perPage,
            ],
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
        ]);

        $payload = [
            'name' => trim((string) $validated['name']),
            'email' => strtolower(trim((string) $validated['email'])),
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = (string) $validated['password'];
        }

        $user->update($payload);

        return back()->with('success', 'User updated successfully.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if((int) $request->user()?->id === (int) $user->id, 422, 'You cannot delete your own account.');

        $user->delete();

        return back()->with('success', 'User deleted successfully.');
    }
}

