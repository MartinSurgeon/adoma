<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

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
</script>

<template>
    <Head title="Transactions" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold leading-tight text-gray-900">Transactions</h1>
                    <p class="mt-1 text-sm text-gray-600">Receipts and payments, newest first.</p>
                </div>
                <Link v-if="canRecord" :href="route('receipts.create')">
                    <PrimaryButton type="button" class="min-h-[44px]">Record a receipt</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <div class="grid gap-3 rounded-lg bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
                <div class="sm:col-span-2 lg:col-span-1">
                    <label for="q" class="block text-sm font-medium text-gray-700">Search</label>
                    <input id="q" type="search" v-model="filter.q" placeholder="Number or name" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600" />
                </div>
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                    <select id="type" v-model="filter.type" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">All types</option>
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="from" class="block text-sm font-medium text-gray-700">From</label>
                    <input id="from" type="date" v-model="filter.from" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600" />
                </div>
                <div>
                    <label for="to" class="block text-sm font-medium text-gray-700">To</label>
                    <input id="to" type="date" v-model="filter.to" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600" />
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" v-model="filter.status" class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">Any</option>
                        <option value="posted">Saved</option>
                        <option value="reversed">Reversed</option>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Number</th>
                            <th scope="col" class="px-4 py-3">Date</th>
                            <th scope="col" class="px-4 py-3">Who</th>
                            <th scope="col" class="px-4 py-3">Office</th>
                            <th scope="col" class="px-4 py-3 text-right">Amount (GH₵)</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="d in documents.data" :key="d.id" class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <Link :href="route('documents.show', d.id)" class="inline-flex min-h-[44px] items-center font-medium text-teal-800 hover:underline">{{ d.number }}</Link>
                                <span class="block text-xs text-gray-500">{{ d.type_label }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ day(d.date) }}</td>
                            <td class="px-4 py-3 text-gray-900">{{ d.party }}</td>
                            <td class="px-4 py-3 text-gray-700">{{ d.office }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-900">{{ d.total }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="d.status === 'reversed' ? 'bg-gray-200 text-gray-800' : d.is_reversal ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800'"
                                >
                                    {{ d.status === 'reversed' ? 'Reversed' : d.is_reversal ? 'Reversal' : 'Saved' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!documents.data.length">
                            <td colspan="6" class="px-4 py-10 text-center">
                                <p class="text-gray-600">{{ hasFilter ? 'No transactions match your search.' : 'No transactions yet.' }}</p>
                                <button v-if="hasFilter" type="button" @click="clear" class="mt-3 min-h-[44px] rounded-md border border-gray-300 px-4 text-gray-700 hover:bg-gray-50">Clear search</button>
                                <Link v-else-if="canRecord" :href="route('receipts.create')" class="mt-3 inline-block">
                                    <PrimaryButton type="button" class="min-h-[44px]">Record the first receipt</PrimaryButton>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="documents" />
        </div>
    </AuthenticatedLayout>
</template>
