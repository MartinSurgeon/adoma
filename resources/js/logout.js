import { router } from '@inertiajs/vue3';
import { UNSAVED_WARNING, confirm, hasUnsavedWork } from '@/confirm';

/** Log out, after asking. Used by the account menu and by the first-login password screen. */
export async function logOut() {
    const unsaved = hasUnsavedWork();

    const yes = await confirm({
        title: 'Log out?',
        body: unsaved ? UNSAVED_WARNING : 'You will need your email and password to log in again.',
        confirmLabel: 'Log out',
        cancelLabel: 'Stay logged in',
        danger: unsaved,
    });

    if (yes) router.post(route('logout'));
}
