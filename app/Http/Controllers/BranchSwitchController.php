<?php

namespace App\Http\Controllers;

use App\Http\AfterBranchSwitch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Uri;

class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate(['branch' => ['required']]);
        $user = $request->user();

        if ($data['branch'] === 'all') {
            abort_unless($user->isTenantAdmin(), 403, 'Only admins can view all branches.');
            $request->session()->put('active_branch_id', 'all');

            return $this->backToPage();
        }

        $branch = $user->accessibleBranches()->firstWhere('id', (int) $data['branch']);
        abort_unless($branch, 403, 'You do not have access to this branch.');

        $request->session()->put('active_branch_id', $branch->id);

        return $this->backToPage();
    }

    /**
     * Back to the page you were on, now for the new branch, and on its first page: the new branch's list has pages
     * of its own. A page that cannot come along (the old branch's transaction, or a form your role in the new branch
     * does not allow) sends you on to its list instead: see AfterBranchSwitch.
     */
    private function backToPage(): RedirectResponse
    {
        $page = Uri::of(url()->previous())->withoutQuery(['page']);

        return redirect()->to((string) $page)->with(AfterBranchSwitch::FLAG, true);
    }
}
