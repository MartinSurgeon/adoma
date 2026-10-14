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
            <div class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5">
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
                        <tr v-for="d in documents.data" :key="d.id">
                            <td>
                                <Link :href="route('documents.show', d.id)" class="inline-flex min-h-12 items-center font-medium text-teal-800 hover:underline">{{ d.number }}</Link>
                                <span class="block text-sm text-gray-700">{{ d.type_label }}</span>
                            </td>
                            <td>{{ day(d.date) }}</td>
                            <td>{{ d.party }}</td>
                            <td>{{ d.office }}</td>
                            <td>
                                <span
                                    class="inline-flex rounded-full px-3 py-1 text-sm font-medium"
                                    :class="d.status === 'reversed' ? 'bg-gray-200 text-gray-900' : d.is_reversal ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-900'"
                                >
                                    {{ d.status === 'reversed' ? 'Reversed' : d.is_reversal ? 'Reversal' : 'Saved' }}
                                </span>
                            </td>
                            <td class="num font-semibold">{{ d.total }}</td>
                        </tr>
                        <tr v-if="!documents.data.length">
                            <td colspan="6" class="py-10 text-center">
                                <p class="text-base text-gray-700">{{ hasFilter ? 'No transactions match your search.' : 'No transactions yet.' }}</p>
                                <SecondaryButton v-if="hasFilter" type="button" class="mt-3" @click="clear">Clear search</SecondaryButton>
                                <ButtonLink v-else-if="canRecord" :href="route('purchases.create')" class="mt-3">Record the first purchase</ButtonLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="documents" />
        </div>
    </AuthenticatedLayout>
</template>
