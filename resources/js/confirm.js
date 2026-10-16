import { reactive } from 'vue';

/**
 * Ask before acting: "Log out?", "Save this purchase?", "Work in Central?".
 *
 * confirm() opens the app's own dialog and returns a promise of true (go ahead) or false (stay as you are).
 * The dialog is drawn once in each layout (ConfirmHost), so any page or component can ask without placing its own.
 *
 *   if (await confirm({ title: 'Log out?', confirmLabel: 'Log out' })) router.post(route('logout'));
 *
 * Options: title, body (a sentence), details ([{ label, value, strong }] shown as a short list, for checking figures),
 * confirmLabel, cancelLabel, danger (red button, and the safe choice is the one already selected).
 */
export const asking = reactive({
    open: false,
    title: '',
    body: '',
    details: [],
    confirmLabel: 'OK',
    cancelLabel: 'Cancel',
    danger: false,
});

let answer = null;

export function confirm(options) {
    // One question at a time. A new one answers the old one "no".
    answer?.(false);

    Object.assign(asking, { body: '', details: [], cancelLabel: 'Cancel', danger: false, ...options, open: true });

    return new Promise((resolve) => {
        answer = resolve;
    });
}

export function settle(value) {
    if (!asking.open) return;
    asking.open = false;
    const resolve = answer;
    answer = null;
    resolve?.(value);
}

/**
 * Forms that hold typed-in work register here while they are on screen, so a question about leaving the page
 * (logging out, switching branch) can warn that the work would be lost.
 */
const unsavedChecks = new Set();

export function trackUnsaved(check) {
    unsavedChecks.add(check);
    return () => unsavedChecks.delete(check);
}

export const hasUnsavedWork = () => [...unsavedChecks].some((check) => check());

export const UNSAVED_WARNING = 'What you have typed on this page is not saved yet, and it will be lost.';
