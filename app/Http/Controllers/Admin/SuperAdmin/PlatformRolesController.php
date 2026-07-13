<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Enums\RevenueRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\UpdatePlatformRolesRequest;
use App\Models\PlatformRoleHolder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PlatformRolesController extends Controller
{
    /**
     * Set the platform-wide Founder and Operator holders. The Operator is the
     * fallback overseer for any restaurant without one; the Founder earns the
     * passive founder share everywhere.
     */
    public function update(UpdatePlatformRolesRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        PlatformRoleHolder::assign(RevenueRole::Founder, User::findOrFail($validated['founder_id']));
        PlatformRoleHolder::assign(RevenueRole::Operator, User::findOrFail($validated['operator_id']));

        return redirect()
            ->route('admin.super.earnings')
            ->with('success', 'Platform roles updated.');
    }
}
