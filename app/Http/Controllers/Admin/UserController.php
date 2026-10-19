<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users', ['users' => User::orderBy('name')->paginate(50)]);
    }

    public function update(Request $request, User $user, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', 'in:user,admin'],
            'can_send' => ['nullable', 'boolean'],
            'disabled' => ['nullable', 'boolean'],
        ]);
        abort_if($request->user()->is($user) && ($data['role'] !== 'admin' || ($data['disabled'] ?? false)), 422, 'Vous ne pouvez pas retirer votre propre accès administrateur.');
        $user->update([
            'role' => $data['role'],
            'can_send' => (bool) ($data['can_send'] ?? false),
            'disabled_at' => ($data['disabled'] ?? false) ? now() : null,
        ]);
        $audit->record('admin.user.updated', null, $request->user(), ['target_user_id' => $user->id, 'role' => $user->role, 'can_send' => $user->can_send, 'disabled' => (bool) $user->disabled_at], $request);

        return back()->with('success', 'Droits mis à jour.');
    }
}
