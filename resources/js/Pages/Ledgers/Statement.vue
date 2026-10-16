<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

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

    <AuthenticatedLayout
        :title="ledger.name"
        :subtitle="`${ledger.kind_label} · showing ${covers}`"
        :back="{ href: route('ledgers.index'), label: 'Ledgers' }"
        width="5xl"
    >
        <template #actions>
            <SecondaryButton type="button" onclick="window.print()">Print statement</SecondaryButton>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <form @submit.prevent="apply" class="card flex flex-col gap-4 p-4 sm:flex-row sm:items-end print:hidden">
                <div>
                    <InputLabel for="from" value="From" />
                    <TextInput id="from" type="date" v-model="filter.from" class="mt-1 block w-full" />
                </div>
                <div>
                    <InputLabel for="to" value="To" />
                    <TextInput id="to" type="date" v-model="filter.to" class="mt-1 block w-full" />
                </div>
                <PrimaryButton>Show</PrimaryButton>
            </form>

            <Banner v-if="truncated" variant="warning">
                This period has more than {{ limit }} entries. Only the first {{ limit }} are shown. Choose a shorter period to see the rest.
            </Banner>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Entry</th>
                            <th scope="col" class="num">Debit</th>
                            <th scope="col" class="num">Credit</th>
                            <th scope="col" class="num">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-gray-50">
                            <td colspan="4">Balance brought forward</td>
                            <td class="num">{{ opening }}</td>
                        </tr>
                        <tr v-for="row in rows" :key="row.id">
                            <td class="whitespace-nowrap">{{ day(row.date) }}</td>
                            <td>
                                <Link :href="route('documents.show', row.document_id)" class="inline-flex min-h-12 items-center font-medium text-teal-800 hover:underline">{{ row.number }}</Link>
                                <span class="ms-2 text-sm text-gray-700">{{ row.type_label }}</span>
                                <span v-if="row.note" class="block text-sm text-gray-700">{{ row.note }}</span>
                            </td>
                            <td class="num">{{ row.debit }}</td>
                            <td class="num">{{ row.credit }}</td>
                            <td class="num font-semibold">{{ row.balance }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="5" class="py-8 text-center text-gray-700">No entries in this period.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2">Totals</td>
                            <td class="num">{{ totals.debit }}</td>
                            <td class="num">{{ totals.credit }}</td>
                            <td class="num">{{ closing }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- The amount owed comes last, and is the largest thing on the page. -->
            <div class="card p-6">
                <p class="text-base text-gray-700">Now</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ closing_words }}</p>
                <p class="mt-2 text-sm text-gray-700">Dr means debit, Cr means credit. A customer who owes you has a debit balance.</p>
            </div>

            <Link :href="route('ledgers.index')" class="inline-flex min-h-12 items-center text-base font-medium text-teal-800 hover:underline print:hidden">Back to ledgers</Link>
        </div>
    </AuthenticatedLayout>
</template>
