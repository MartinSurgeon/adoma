<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import Pagination from '@/Components/Pagination.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

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

// The list arrives sorted by type, so each run of the same type becomes one titled section.
const sections = computed(() => {
    const result = [];
    for (const ledger of props.ledgers.data) {
        const last = result[result.length - 1];
        if (last && last.kind === ledger.kind) last.rows.push(ledger);
        else result.push({ kind: ledger.kind, label: ledger.kind_label, rows: [ledger] });
    }
    return result;
});

const target = ref(null);
const working = ref(false);

function toggle() {
    working.value = true;
    router.patch(route('ledgers.toggle', target.value.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            working.value = false;
            target.value = null;
        },
    });
}

const select = 'mt-1 block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:w-48';
</script>

<template>
    <Head title="Ledgers" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold leading-tight text-gray-900">Ledgers</h1>
                    <p class="mt-1 text-base text-gray-700">Customers, suppliers, banks, cash accounts and expenses.</p>
                </div>
                <ButtonLink v-if="canCreate" :href="route('ledgers.create')">Add ledger</ButtonLink>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>

            <!-- Find -->
            <div class="card p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <div class="flex-1">
                        <InputLabel for="search" value="Search" />
                        <TextInput id="search" type="search" v-model="filter.q" placeholder="Name or phone" class="mt-1 block w-full" />
                    </div>
                    <div>
                        <InputLabel for="kind" value="Type" />
                        <select id="kind" v-model="filter.kind" :class="select">
                            <option value="">All types</option>
                            <option v-for="kind in kinds" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                        </select>
                    </div>
                    <label class="flex min-h-12 cursor-pointer items-center gap-3 text-base text-gray-800">
                        <input type="checkbox" v-model="filter.show_off" class="h-5 w-5 rounded text-teal-700 focus:ring-teal-600" />
                        Show switched-off
                    </label>
                </div>

                <div v-if="hasFilter" class="mt-3 flex items-center justify-between border-t border-gray-100 pt-3 text-sm">
                    <span class="text-gray-600">Active filters applied</span>
                    <button
                        type="button"
                        @click="clearFilters"
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
                            <th scope="col">Name</th>
                            <th scope="col" class="hidden sm:table-cell">Office</th>
                            <th scope="col" class="num">Balance</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>

                    <tbody v-for="section in sections" :key="section.kind">
                        <tr class="group-row">
                            <th scope="colgroup" colspan="4">{{ section.label }}s</th>
                        </tr>
                        <tr v-for="ledger in section.rows" :key="ledger.id" class="transition-colors hover:bg-gray-50/75" :class="{ 'bg-gray-50/60': !ledger.is_active }">
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Link :href="route('ledgers.statement', ledger.id)" class="inline-flex min-h-12 items-center font-medium text-teal-800 hover:underline">
                                        {{ ledger.name }}
                                    </Link>
                                    <span v-if="!ledger.is_active" class="inline-flex items-center gap-1 rounded-full bg-gray-200 px-2.5 py-0.5 text-sm font-medium text-gray-800">
                                        <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                                        </svg>
                                        Switched off
                                    </span>
                                </div>
                                <span v-if="ledger.phone" class="block text-sm text-gray-700">{{ ledger.phone }}</span>
                            </td>
                            <td class="hidden sm:table-cell text-gray-700">{{ ledger.office }}</td>
                            <td class="num font-semibold whitespace-nowrap text-gray-900">{{ ledger.balance }}</td>
                            <td class="text-right">
                                <MoreMenu v-if="ledger.can_edit || ledger.can_toggle" :label="`More actions for ${ledger.name}`">
                                    <MenuItem :href="route('ledgers.statement', ledger.id)">Statement</MenuItem>
                                    <MenuItem v-if="ledger.can_edit" :href="route('ledgers.edit', ledger.id)">Edit</MenuItem>
                                    <MenuItem v-if="ledger.can_toggle" :danger="ledger.is_active" @click="target = ledger">
                                        {{ ledger.is_active ? 'Switch off' : 'Switch on' }}
                                    </MenuItem>
                                </MoreMenu>
                                <span v-else-if="ledger.is_system" class="text-sm text-gray-600">System</span>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-if="!ledgers.data.length">
                        <tr>
                            <td colspan="4" class="py-12 text-center">
                                <div class="flex flex-col items-center justify-center">
                                    <svg class="h-10 w-10 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                    </svg>
                                    <p class="mt-2 text-base font-medium text-gray-900">{{ hasFilter ? 'No ledgers match your search' : 'No ledgers yet' }}</p>
                                    <p class="mt-1 text-sm text-gray-600">{{ hasFilter ? 'Try clearing your search query or changing the filter.' : 'Ledgers represent your customers, suppliers, banks, and expense accounts.' }}</p>
                                    <SecondaryButton v-if="hasFilter" type="button" class="mt-4" @click="clearFilters">Clear all filters</SecondaryButton>
                                    <ButtonLink v-else-if="canCreate" :href="route('ledgers.create')" class="mt-4">Add the first ledger</ButtonLink>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="ledgers" />
        </div>

        <ConfirmDialog
            :show="target !== null"
            :title="target?.is_active ? `Switch off ${target?.name}?` : `Switch on ${target?.name}?`"
            :confirm-label="target?.is_active ? 'Switch off' : 'Switch on'"
            :danger="target?.is_active"
            :processing="working"
            @close="target = null"
            @confirm="toggle"
        >
            <p v-if="target?.is_active">It stays in reports and keeps its history, but it cannot be used in new transactions. You can switch it on again later.</p>
            <p v-else>It will be available in new transactions again.</p>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
