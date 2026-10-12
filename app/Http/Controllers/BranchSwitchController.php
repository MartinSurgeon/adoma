<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch' => ['required']]);
        $user = $request->user();

        if ($data['branch'] === 'all') {
            abort_unless($user->isTenantAdmin(), 403, 'Only admins can view all offices.');
            $request->session()->put('active_branch_id', 'all');

            return back();
        }

        $branch = $user->accessibleBranches()->firstWhere('id', (int) $data['branch']);
        abort_unless($branch, 403, 'You do not have access to this office.');

        $request->session()->put('active_branch_id', $branch->id);

        return back();
    }
}
