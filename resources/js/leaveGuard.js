import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import { confirm, trackUnsaved } from '@/confirm';

/**
 * Ask before throwing away typed-in work.
 *
 * While a form has unsaved changes, moving to another page (a link, the menu, the browser's back button inside
 * the app) asks "Leave without saving?" first, and closing the tab or reloading gets the browser's own warning.
 * Saving is never stopped: only moves to another page (GET visits) are held.
 *
 *   useLeaveGuard({ dirty: () => form.isDirty, busy: () => form.processing, noun: 'ledger' })
 */
export function useLeaveGuard({ dirty, busy = () => false, noun = 'changes' }) {
    let allowed = false;
    let stopListening = null;
    let stopTracking = null;

    const unsaved = () => dirty() && !busy();

    function warnBeforeUnload(event) {
        if (unsaved()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    onMounted(() => {
        window.addEventListener('beforeunload', warnBeforeUnload);
        stopTracking = trackUnsaved(unsaved);

        stopListening = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (allowed || !unsaved() || visit.method !== 'get') return;

            const target = visit.url.href;
            confirm({
                title: 'Leave without saving?',
                body: `You have typed in details for this ${noun} that are not saved. If you leave now, they will be lost.`,
                confirmLabel: 'Leave and lose it',
                cancelLabel: 'Keep editing',
                danger: true,
            }).then((leave) => {
                if (!leave) return;
                allowed = true;
                router.visit(target);
            });

            return false; // hold the move until the person decides
        });
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', warnBeforeUnload);
        stopListening?.();
        stopTracking?.();
    });
}
