<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import Pagination from '@/Components/Pagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ documents: Object, filters: Object, types: Array, canRecord: Boolean });

const filter = reactive({ ...props.filters });
const hasFilter = computed(() => Object.values(filter).some(Boolean));

let timer = null;
function apply() {
    const query = Object.fromEntries(Object.entries(filter).filter(([, v]) => v));
    router.get(route('documents.index'), query, { preserveState: true, preserveScroll: true, replace: true });
}
watch(() => filter.q, () => {
    clearTimeout(timer);
    timer = setTimeout(apply, 300);
});
watch(() => [filter.type, filter.status, filter.from, filter.to], apply);

const clear = () => Object.assign(filter, { q: '', type: '', status: '', from: '', to: '' });
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

const select = 'mt-1 block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
</script>

<template>
    <Head title="Transactions" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold leading-tight text-gray-900">Transactions</h1>
                    <p class="mt-1 text-base text-gray-700">Everything recorded, newest first.</p>
                </div>
                <div v-if="canRecord" class="flex flex-wrap items-center gap-3">
                    <ButtonLink :href="route('purchases.create')">Record a purchase</ButtonLink>
                    <ButtonLink :href="route('sales.create')" variant="secondary">Record a sale</ButtonLink>
                    <MoreMenu label="More ways to record">
                        <MenuItem :href="route('receipts.create')">Record a receipt</MenuItem>
                        <MenuItem :href="route('payments.create')">Record a payment</MenuItem>
                    </MoreMenu>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <div class="card p-4">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <div class="sm:col-span-2 lg:col-span-1">
                        <InputLabel for="q" value="Search" />
                        <TextInput id="q" type="search" v-model="filter.q" placeholder="Number or name" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="type" value="Type" />
                        <select id="type" v-model="filter.type" :class="select">
                            <option value="">All types</option>
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel for="from" value="From" />
                        <TextInput id="from" type="date" v-model="filter.from" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="to" value="To" />
                        <TextInput id="to" type="date" v-model="filter.to" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="status" value="Status" />
                        <select id="status" v-model="filter.status" :class="select">
                            <option value="">Any</option>
                            <option value="posted">Saved</option>
                            <option value="reversed">Reversed</option>
                        </select>
                    </div>
                </div>

                <div v-if="hasFilter" class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                    <span class="text-gray-600">Active filters applied</span>
                    <button
                        type="button"
                        @click="clear"
                        class="inline-flex items-center gap-1 font-medium text-teal-800 hover:text-teal-900 hover:underline"
                    >
                        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                        </svg>
                        Clear filters
                    </button>
                </div>
            </div>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Number</th>
                            <th scope="col">Date</th>
                            <th scope="col">Who</th>
                            <th scope="col">Office</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="num">Amount (GH₵)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="d in documents.data" :key="d.id" class="transition-colors hover:bg-gray-50/75">
                            <td>
                                <Link :href="route('documents.show', d.id)" class="inline-flex min-h-12 items-center font-semibold text-teal-800 hover:underline">{{ d.number }}</Link>
                                <span class="block text-sm text-gray-700">{{ d.type_label }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ day(d.date) }}</td>
                            <td>{{ d.party }}</td>
                            <td>{{ d.office }}</td>
                            <td>
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium"
                                    :class="d.status === 'reversed' ? 'bg-gray-200 text-gray-800' : d.is_reversal ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-900'"
                                >
                                    <svg v-if="d.status === 'reversed'" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else-if="d.is_reversal" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                    </svg>
                                    <svg v-else class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                    </svg>
                                    {{ d.status === 'reversed' ? 'Reversed' : d.is_reversal ? 'Reversal' : 'Saved' }}
                                </span>
                            </td>
                            <td class="num font-semibold text-gray-900">{{ d.total }}</td>
                        </tr>
                        <tr v-if="!documents.data.length">
                            <td colspan="6" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <svg class="h-10 w-10 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m5.231 13.481L15 17.25m-4.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9zm3.75 11.625a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                    </svg>
                                    <p class="mt-2 text-base font-medium text-gray-900">{{ hasFilter ? 'No transactions match your search' : 'No transactions yet' }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ hasFilter ? 'Try adjusting your dates, status, or search query.' : 'Transactions will appear here once recorded.' }}</p>
                                    <SecondaryButton v-if="hasFilter" type="button" class="mt-4" @click="clear">Clear all filters</SecondaryButton>
                                    <ButtonLink v-else-if="canRecord" :href="route('purchases.create')" class="mt-4">Record the first purchase</ButtonLink>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="documents" />
        </div>
    </AuthenticatedLayout>
</template>
