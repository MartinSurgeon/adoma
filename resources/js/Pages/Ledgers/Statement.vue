<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

const props = defineProps({
    ledger: Object,
    covers: String,
    filters: Object,
    opening: String,
    rows: Array,
    totals: Object,
    closing: String,
    closing_words: String,
    truncated: Boolean,
    limit: Number,
});

const filter = reactive({ ...props.filters });

function apply() {
    const query = Object.fromEntries(Object.entries(filter).filter(([, v]) => v));
    router.get(route('ledgers.statement', props.ledger.id), query, { preserveScroll: true });
}

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head :title="`Statement: ${ledger.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold leading-tight text-gray-900">{{ ledger.name }}</h1>
                    <p class="mt-1 text-sm text-gray-600">{{ ledger.kind_label }} · showing {{ covers }}</p>
                </div>
                <button type="button" onclick="window.print()" class="min-h-[44px] rounded-md border border-gray-300 bg-white px-4 text-sm text-gray-700 hover:bg-gray-50 print:hidden">
                    Print statement
                </button>
            </div>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <form @submit.prevent="apply" class="flex flex-col gap-3 rounded-lg bg-white p-4 shadow-sm sm:flex-row sm:items-end print:hidden">
                <div>
                    <label for="from" class="block text-sm font-medium text-gray-700">From</label>
                    <input id="from" type="date" v-model="filter.from" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600" />
                </div>
                <div>
                    <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                    <input id="to" type="date" v-model="filter.to" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600" />
                </div>
                <button type="submit" class="min-h-[44px] rounded-md bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800">Show</button>
            </form>

            <div v-if="truncated" role="note" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                This period has more than {{ limit }} entries. Only the first {{ limit }} are shown. Choose a shorter period to see the rest.
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Date</th>
                            <th scope="col" class="px-4 py-3">Entry</th>
                            <th scope="col" class="px-4 py-3 text-right">Debit</th>
                            <th scope="col" class="px-4 py-3 text-right">Credit</th>
                            <th scope="col" class="px-4 py-3 text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr class="bg-gray-50 text-gray-700">
                            <td class="px-4 py-3" colspan="4">Balance brought forward</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ opening }}</td>
                        </tr>
                        <tr v-for="row in rows" :key="row.id">
                            <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ day(row.date) }}</td>
                            <td class="px-4 py-3">
                                <Link :href="route('documents.show', row.document_id)" class="font-medium text-teal-800 hover:underline">{{ row.number }}</Link>
                                <span class="ms-2 text-xs text-gray-500">{{ row.type_label }}</span>
                                <span v-if="row.note" class="block text-xs text-gray-600">{{ row.note }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ row.debit }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ row.credit }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ row.balance }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="px-4 py-8 text-center text-gray-600">No entries in this period.</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-gray-300 bg-gray-50 font-semibold text-gray-900">
                        <tr>
                            <td class="px-4 py-3" colspan="2">Totals</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ totals.debit }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ totals.credit }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ closing }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="text-sm text-gray-700">
                <span class="font-medium">Now:</span> {{ closing_words }}
                <span class="block text-xs text-gray-500">Dr means debit, Cr means credit. A customer who owes you has a debit balance.</span>
            </p>

            <Link :href="route('ledgers.index')" class="inline-block text-sm text-teal-800 underline print:hidden">Back to ledgers</Link>
        </div>
    </AuthenticatedLayout>
</template>
