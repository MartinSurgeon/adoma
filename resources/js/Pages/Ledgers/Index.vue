<script setup>
import { computed, reactive, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    ledgers: Object,
    filters: Object,
    kinds: Array,
    canCreate: Boolean,
});

const page = usePage();
const success = computed(() => page.props.flash?.success);

const filter = reactive({ ...props.filters });
const hasFilter = computed(() => filter.q || filter.kind || filter.show_off);

let timer = null;
function applyFilters() {
    router.get(
        route('ledgers.index'),
        { q: filter.q || undefined, kind: filter.kind || undefined, show_off: filter.show_off ? 1 : undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
// Search as people type, after a short pause, so the list does not jump on every key.
watch(() => filter.q, () => {
    clearTimeout(timer);
    timer = setTimeout(applyFilters, 300);
});
watch(() => [filter.kind, filter.show_off], applyFilters);

function clearFilters() {
    filter.q = '';
    filter.kind = '';
    filter.show_off = false;
}

function toggle(ledger) {
    const message = ledger.is_active
        ? `Switch off ${ledger.name}? It stays in reports but cannot be used in new transactions.`
        : `Switch on ${ledger.name}?`;

    if (confirm(message)) {
        router.patch(route('ledgers.toggle', ledger.id), {}, { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Ledgers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold leading-tight text-gray-800">Ledgers</h1>
                    <p class="mt-1 text-sm text-gray-600">Customers, suppliers, banks, cash accounts and expenses.</p>
                </div>
                <Link v-if="canCreate" :href="route('ledgers.create')">
                    <PrimaryButton class="min-h-[44px]" type="button">Add ledger</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                {{ success }}
            </div>

            <!-- Find -->
            <div class="flex flex-col gap-3 rounded-lg bg-white p-4 shadow-sm sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                    <input
                        id="search"
                        type="search"
                        v-model="filter.q"
                        placeholder="Name or phone"
                        class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                    />
                </div>
                <div>
                    <label for="kind" class="block text-sm font-medium text-gray-700">Type</label>
                    <select
                        id="kind"
                        v-model="filter.kind"
                        class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:w-48"
                    >
                        <option value="">All types</option>
                        <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                    </select>
                </div>
                <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" v-model="filter.show_off" class="rounded text-teal-700 focus:ring-teal-600" />
                    Show switched-off
                </label>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="px-4 py-3">Type</th>
                            <th scope="col" class="px-4 py-3">Group</th>
                            <th scope="col" class="px-4 py-3">Office</th>
                            <th scope="col" class="px-4 py-3">Balance</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="ledger in ledgers.data" :key="ledger.id" :class="{ 'bg-gray-50 text-gray-500': !ledger.is_active }">
                            <td class="px-4 py-3">
                                <div class="font-medium" :class="ledger.is_active ? 'text-gray-900' : 'text-gray-500'">
                                    {{ ledger.name }}
                                    <span v-if="!ledger.is_active" class="ms-2 rounded-full bg-gray-200 px-2 py-0.5 text-xs font-medium text-gray-700">Switched off</span>
                                </div>
                                <div v-if="ledger.phone" class="text-gray-500">{{ ledger.phone }}</div>
                            </td>
                            <td class="px-4 py-3">{{ ledger.kind_label }}</td>
                            <td class="px-4 py-3">{{ ledger.group }}</td>
                            <td class="px-4 py-3">{{ ledger.office }}</td>
                            <td class="whitespace-nowrap px-4 py-3 tabular-nums text-gray-800">{{ ledger.balance }}</td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <Link
                                        :href="route('ledgers.statement', ledger.id)"
                                        class="inline-flex min-h-[44px] items-center rounded-md border border-gray-300 px-3 text-gray-700 hover:bg-gray-50"
                                    >
                                        Statement
                                    </Link>
                                    <Link
                                        v-if="ledger.can_edit"
                                        :href="route('ledgers.edit', ledger.id)"
                                        class="inline-flex min-h-[44px] items-center rounded-md border border-gray-300 px-3 text-gray-700 hover:bg-gray-50"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        v-if="ledger.can_toggle"
                                        type="button"
                                        @click="toggle(ledger)"
                                        class="min-h-[44px] rounded-md border px-3"
                                        :class="ledger.is_active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                                    >
                                        {{ ledger.is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                    <span v-if="ledger.is_system" class="self-center text-xs text-gray-400">Managed by the system</span>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="!ledgers.data.length">
                            <td colspan="6" class="px-4 py-10 text-center">
                                <p class="text-gray-600">
                                    {{ hasFilter ? 'No ledgers match your search.' : 'No ledgers yet.' }}
                                </p>
                                <button v-if="hasFilter" type="button" @click="clearFilters" class="mt-3 min-h-[44px] rounded-md border border-gray-300 px-4 text-gray-700 hover:bg-gray-50">
                                    Clear search
                                </button>
                                <Link v-else-if="canCreate" :href="route('ledgers.create')" class="mt-3 inline-block">
                                    <PrimaryButton class="min-h-[44px]" type="button">Add the first ledger</PrimaryButton>
                                </Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="ledgers" />
        </div>
    </AuthenticatedLayout>
</template>
