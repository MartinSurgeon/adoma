import { router } from '@inertiajs/vue3';
import { confirm } from '@/confirm';

/**
 * The two account actions that cannot be quietly undone, asked first wherever they are offered
 * (the Users list and a person's own page). Each stays on the page it was started from.
 */
export async function resetPassword(user) {
    const yes = await confirm({
        title: `Reset the password for ${user.name}?`,
        body: 'They are signed out at once, and need the new temporary password to sign in. You will see it once, on this page.',
        confirmLabel: 'Reset password',
        cancelLabel: 'Keep it',
        danger: true,
    });

    if (yes) router.post(route('users.reset', user.id), {}, { preserveScroll: true });
}

export async function switchAccount(user) {
    const yes = await confirm(
        user.is_active
            ? {
                  title: `Switch off ${user.name}?`,
                  body: 'They are signed out everywhere and cannot sign in until you switch them on again. Their records stay.',
                  confirmLabel: 'Switch off',
                  cancelLabel: 'Keep them on',
                  danger: true,
              }
            : {
                  title: `Let ${user.name} sign in again?`,
                  body: 'They sign in with the password they had, in the branches and roles they had.',
                  confirmLabel: 'Switch on',
                  cancelLabel: 'Leave off',
              },
    );

    if (yes) router.patch(route('users.toggle', user.id), {}, { preserveScroll: true });
}
