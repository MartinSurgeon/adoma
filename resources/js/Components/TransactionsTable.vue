<script setup>
import { Link, router } from '@inertiajs/vue3';
import EntryType from '@/Components/EntryType.vue';
import NavIcon from '@/Components/NavIcon.vue';

/**
 * Transactions as a table: when, branch, reference, what kind, who, the gold and the amount.
 * Used for the latest transactions on the home page and for the Transactions page, so both read the same.
 *
 * Where there is room it is a table; in a narrow space (a phone) each transaction is a short card instead,
 * with the same facts. A whole row opens the transaction; its reference is the link for the keyboard.
 */
const props = defineProps({
    rows: { type: Array, required: true }, // from DocumentRows on the server
    showOffice: { type: Boolean, default: false }, // only when looking at more than one branch
    // "recorded": when each was entered (home page). "date": the date it belongs to (Transactions page).
    when: { type: String, default: 'recorded' },
});

const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

function recorded(iso) {
    if (!iso) return '—';
    const at = new Date(iso);
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(now.getDate() - 1);

    if (sameDay(at, now)) return at.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true });
    if (sameDay(at, yesterday)) return 'Yesterday';
    return at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', ...(at.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}) });
}

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

// Recorded on a different day from the one it belongs to (entered late): say which day it belongs to.
function datedOtherDay(row) {
    if (props.when !== 'recorded' || !row.recorded_at) return null;
    const at = new Date(row.recorded_at);
    const belongs = new Date(`${row.date}T00:00:00`);
    return sameDay(at, belongs) ? null : day(row.date);
}

const whenText = (row) => (props.when === 'recorded' ? recorded(row.recorded_at) : day(row.date));

function open(event, row) {
    // A click on the link itself is the link's job.
    if (event.target.closest('a')) return;
    router.visit(route('documents.show', row.id));
}

const amountClass = (row) => (row.status === 'reversed' ? 'text-gray-500 line-through decoration-gray-400' : 'text-gray-900');
</script>

<template>
    <div class="@container">
        <slot v-if="!rows.length" name="empty" />

        <!-- Room enough: a table. -->
        <div v-if="rows.length" class="hidden overflow-x-auto @3xl:block">
            <table class="data-table data-table-compact">
                <thead>
                    <tr>
                        <th scope="col">{{ when === 'recorded' ? 'When' : 'Date' }}</th>
                        <th v-if="showOffice" scope="col">Branch</th>
                        <th scope="col">Ref</th>
                        <th scope="col">Entry</th>
                        <th scope="col">Account</th>
                        <th scope="col" class="num">Weight (g)</th>
                        <th scope="col" class="num">Amount (GH₵)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id" class="row-link group" @click="open($event, row)">
                        <td class="whitespace-nowrap text-gray-700">
                            {{ whenText(row) }}
                            <span v-if="datedOtherDay(row)" class="block text-sm text-gray-600">for {{ datedOtherDay(row) }}</span>
                        </td>
                        <td v-if="showOffice" class="whitespace-nowrap">{{ row.office }}</td>
                        <td class="whitespace-nowrap">
                            <Link :href="route('documents.show', row.id)" class="ref inline-flex min-h-12 items-center font-medium text-teal-800 underline-offset-4 group-hover:underline">
                                {{ row.number }}
                            </Link>
                        </td>
                        <td><EntryType :row="row" /></td>
                        <td class="min-w-[10rem]">{{ row.party ?? '—' }}</td>
                        <td class="num" :class="row.weight ? '' : 'text-gray-500'">{{ row.weight ?? '—' }}</td>
                        <td class="num font-semibold" :class="amountClass(row)">{{ row.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- A narrow space: one short card per transaction. -->
        <ul v-if="rows.length" class="divide-y divide-gray-100 @3xl:hidden">
            <li v-for="row in rows" :key="row.id">
                <Link :href="route('documents.show', row.id)" class="flex min-h-12 items-start justify-between gap-4 px-4 py-3.5 transition-colors hover:bg-teal-50/40 sm:px-6">
                    <span class="min-w-0">
                        <span class="block truncate text-base font-medium text-gray-900">{{ row.party ?? row.type_label }}</span>
                        <span class="mt-0.5 block text-sm text-gray-700">
                            <EntryType :row="row" />
                        </span>
                        <span class="mt-0.5 block text-sm text-gray-600">
                            <span class="ref">{{ row.number }}</span> · {{ whenText(row) }}<template v-if="showOffice"> · {{ row.office }}</template>
                        </span>
                    </span>
                    <span class="shrink-0 text-right">
                        <span class="block text-base font-semibold tabular-nums" :class="amountClass(row)">{{ row.total }}</span>
                        <span v-if="row.weight" class="mt-0.5 block text-sm tabular-nums text-gray-700">{{ row.weight }} g</span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
