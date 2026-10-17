<?php

namespace App\Console\Commands;

use App\Domain\Gold\FormulaSet;
use App\Domain\Ledgers\ChartOfAccounts;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * The first run on a new server: the business, its branches, and the owner's account.
 *
 *     php artisan adoma:setup
 *
 * It asks for each thing in turn (or takes them as options, for a script), creates them together, and shows the
 * owner's temporary password once. The owner chooses their own at first sign-in, then adds everyone else in the app.
 * It refuses to run on a server that already has a business, so it can never make a second one by mistake.
 */
class SetupBusiness extends Command
{
    protected $signature = 'adoma:setup
        {--business= : The business name}
        {--branch=* : A branch as "Name:CODE", the first one is the head office (repeat for more)}
        {--owner-name= : The owner\'s full name}
        {--owner-email= : The owner\'s email address, used to sign in}';

    protected $description = 'Create the business, its branches and the owner account on a new server';

    public function handle(): int
    {
        if (Tenant::query()->exists()) {
            $this->error('This server already has a business. Add branches and people inside Adoma instead.');

            return self::FAILURE;
        }

        $business = $this->option('business') ?: text('Business name', required: true, validate: fn ($v) => mb_strlen(trim($v)) > 60 ? 'Use 60 characters or fewer.' : null);
        $branches = $this->branches();
        $ownerName = $this->option('owner-name') ?: text('Owner\'s full name', required: true);
        $ownerEmail = strtolower(trim($this->option('owner-email') ?: text('Owner\'s email address (to sign in)', required: true)));

        $problem = $this->check($business, $branches, $ownerName, $ownerEmail);
        if ($problem !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $password = Str::password(12, symbols: false);

        DB::transaction(function () use ($business, $branches, $ownerName, $ownerEmail, $password) {
            $tenant = Tenant::create(['name' => trim($business), 'status' => 'active']);
            ChartOfAccounts::seedFor($tenant);
            FormulaSet::createDefaultFor($tenant);

            foreach ($branches as $index => [$name, $code]) {
                // A branch brings its own account (for trading with other branches) and voucher numbers.
                $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => $name, 'code' => $code, 'is_head_office' => $index === 0]);

                // And a cash account, so receipts and payments work from the first day. It can be renamed later.
                Ledger::create([
                    'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_shared' => false,
                    'group_id' => ChartOfAccounts::groupFor($tenant->id, LedgerKind::Cash)->id,
                    'kind' => LedgerKind::Cash, 'name' => "Cash {$name}",
                ]);
            }

            $owner = new User();
            $owner->forceFill([
                'name' => trim($ownerName),
                'email' => $ownerEmail,
                'password' => $password,
                'tenant_id' => $tenant->id,
                'tenant_role' => Role::Owner->value,
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ])->save();
        });

        $this->newLine();
        $this->info("{$business} is set up, with ".count($branches).' '.(count($branches) === 1 ? 'branch' : 'branches').'.');
        $this->table(['Branch', 'Code', ''], collect($branches)->map(fn ($b, $i) => [$b[0], $b[1], $i === 0 ? 'Head office' : ''])->all());
        $this->newLine();
        $this->line('Sign in as the owner with:');
        $this->line("  Email:              {$ownerEmail}");
        $this->line("  Temporary password: <options=bold>{$password}</>");
        $this->newLine();
        $this->warn('The password is shown only this once. The owner chooses their own at first sign-in.');

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string}> [name, CODE], head office first */
    private function branches(): array
    {
        $given = $this->option('branch');

        if ($given !== []) {
            return array_map(function (string $entry) {
                [$name, $code] = array_pad(explode(':', $entry, 2), 2, '');

                return [trim($name), strtoupper(trim($code))];
            }, $given);
        }

        $branches = [];
        do {
            $first = $branches === [];
            $name = text($first ? 'Head office branch name' : 'Branch name', required: true);
            $code = strtoupper(text('Its code (2 to 5 letters or numbers, e.g. KUM)', required: true,
                validate: fn ($v) => preg_match('/^[A-Za-z0-9]{2,5}$/', trim($v)) ? null : 'Use 2 to 5 letters or numbers.'));
            $branches[] = [trim($name), trim($code)];
        } while (confirm('Add another branch?', default: false));

        return $branches;
    }

    /** Everything checked before anything is written. */
    private function check(string $business, array $branches, string $ownerName, string $ownerEmail): ?string
    {
        if ($branches === []) {
            return 'Give at least one branch.';
        }

        foreach ($branches as [$name, $code]) {
            if ($name === '' || mb_strlen($name) > 60) {
                return 'Each branch needs a name of 60 characters or fewer.';
            }
            if (! preg_match('/^[A-Z0-9]{2,5}$/', $code)) {
                return "The code for {$name} must be 2 to 5 letters or numbers, such as KUM.";
            }
        }

        if (count(array_unique(array_column($branches, 1))) !== count($branches)) {
            return 'Two branches have the same code.';
        }
        if (count(array_unique(array_map('mb_strtolower', array_column($branches, 0)))) !== count($branches)) {
            return 'Two branches have the same name.';
        }

        $email = Validator::make(['email' => $ownerEmail, 'name' => $ownerName], [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'max:255'],
        ], ['email.unique' => 'Someone already uses this email address.']);

        return $email->fails() ? $email->errors()->first() : null;
    }
}
