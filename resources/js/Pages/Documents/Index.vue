<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import NavIcon from '@/Components/NavIcon.vue';
import Pagination from '@/Components/Pagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import TransactionsTable from '@/Components/TransactionsTable.vue';

const props = defineProps({ documents: Object, filters: Object, types: Array, canRecord: Boolean, showOffice: Boolean });

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

const select = 'mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
</script>

<template>
    <Head title="Transactions" />

    <AuthenticatedLayout title="Transactions" subtitle="Newest first." width="7xl">
        <template v-if="canRecord" #actions>
            <ButtonLink :href="route('purchases.create')">New purchase</ButtonLink>
            <ButtonLink :href="route('sales.create')" variant="secondary">New sale</ButtonLink>
            <MoreMenu label="More to record">
                <MenuItem :href="route('receipts.create')">New receipt</MenuItem>
                <MenuItem :href="route('payments.create')">New payment</MenuItem>
            </MoreMenu>
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

            <div class="table-card">
                <TransactionsTable :rows="documents.data" :show-office="showOffice" when="date">
                    <template #empty>
                        <div class="flex flex-col items-center justify-center px-6 py-12 text-center">
                            <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon name="transactions" class="h-6 w-6" /></span>
                            <p class="mt-3 text-base font-medium text-gray-900">{{ hasFilter ? 'Nothing matches your search' : 'No transactions yet' }}</p>
                            <p class="mt-1 text-base text-gray-700">{{ hasFilter ? 'Try other dates, another type, or fewer words.' : 'They will show here once recorded.' }}</p>
                            <SecondaryButton v-if="hasFilter" type="button" class="mt-4" @click="clear">Clear filters</SecondaryButton>
                            <ButtonLink v-else-if="canRecord" :href="route('purchases.create')" class="mt-4">Record the first purchase</ButtonLink>
                        </div>
                    </template>
                </TransactionsTable>
            </div>

            <Pagination :paginator="documents" />
        </div>
    </AuthenticatedLayout>
</template>
