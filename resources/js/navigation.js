import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Every way around the app, in one place, so the sidebar, the phone's bottom bar and the "New" menu always agree.
 *
 * Kept short on purpose. Recording is one "New" menu (as the client's FileMaker had), not five links, so the sidebar
 * holds six places to go plus two admin pages folded away. People see what they may use and nothing else.
 */
export function useNavigation() {
    const page = usePage();
    const tenancy = computed(() => page.props.tenancy);
    const canRecord = computed(() => Boolean(tenancy.value?.can_record));
    const canTransfer = computed(() => Boolean(tenancy.value?.can_transfer));
    const isAdmin = computed(() => Boolean(tenancy.value?.can_view_all));
    const signedIn = computed(() => Boolean(tenancy.value));
    const incoming = computed(() => Number(tenancy.value?.incoming_transfers ?? 0));

    const is = (...names) => names.some((name) => route().current(name));

    // What can be recorded. Each has two or three words under it, so nobody has to guess which one they need.
    const newItems = computed(() =>
        [
            { key: 'purchase', label: 'Purchase', hint: 'Buy gold', href: route('purchases.create'), icon: 'purchase', active: is('purchases.*'), show: canRecord.value },
            { key: 'sale', label: 'Sale', hint: 'Sell gold', href: route('sales.create'), icon: 'sale', active: is('sales.*'), show: canRecord.value },
            { key: 'receipt', label: 'Receipt', hint: 'Money in', href: route('receipts.create'), icon: 'receipt', active: is('receipts.*'), show: canRecord.value },
            { key: 'payment', label: 'Payment', hint: 'Money out', href: route('payments.create'), icon: 'payment', active: is('payments.*'), show: canRecord.value },
            { key: 'transfer', label: 'Transfer', hint: 'Gold to a branch', href: route('transfers.create'), icon: 'transfer', active: is('transfers.create'), show: canTransfer.value },
        ].filter((item) => item.show),
    );

    // Places to go, in the order people need them most.
    const mainItems = computed(() =>
        [
            { key: 'home', label: 'Home', href: route('dashboard'), icon: 'home', active: is('dashboard'), show: true },
            { key: 'transactions', label: 'Transactions', href: route('documents.index'), icon: 'transactions', active: is('documents.*'), show: signedIn.value },
            { key: 'stock', label: 'Stock', href: route('stock.index'), icon: 'stock', active: is('stock.*'), show: signedIn.value },
            // Gold other branches have sent, waiting for a manager here. The badge says how many.
            { key: 'incoming', label: 'Incoming gold', href: route('transfers.incoming'), icon: 'inbox', active: is('transfers.incoming'), show: canTransfer.value, badge: incoming.value },
            { key: 'ledgers', label: 'Ledgers', href: route('ledgers.index'), icon: 'ledgers', active: is('ledgers.*'), show: signedIn.value },
            { key: 'reports', label: 'Reports', href: route('reports.index'), icon: 'reports', active: is('reports.*'), show: signedIn.value },
        ].filter((item) => item.show),
    );

    // Rarely needed, so folded away until wanted.
    const adminItems = computed(() =>
        [
            { key: 'users', label: 'Users', href: route('users.index'), icon: 'users', active: is('users.*'), show: isAdmin.value },
            { key: 'activity', label: 'Activity', href: route('activity.index'), icon: 'activity', active: is('activity.*'), show: isAdmin.value },
        ].filter((item) => item.show),
    );

    const recording = computed(() => newItems.value.some((item) => item.active));

    return { tenancy, canRecord, canTransfer, isAdmin, incoming, newItems, mainItems, adminItems, recording };
}
