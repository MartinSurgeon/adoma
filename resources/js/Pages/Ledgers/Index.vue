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
            <div class="card flex flex-col gap-4 p-4 sm:flex-row sm:items-end">
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
                        <tr v-for="ledger in section.rows" :key="ledger.id" :class="{ 'bg-gray-50': !ledger.is_active }">
                            <td>
                                <Link :href="route('ledgers.statement', ledger.id)" class="inline-flex min-h-12 items-center font-medium text-teal-800 hover:underline">
                                    {{ ledger.name }}
                                </Link>
                                <span v-if="!ledger.is_active" class="ms-2 rounded-full bg-gray-200 px-3 py-1 text-sm font-medium text-gray-900">Switched off</span>
                                <span v-if="ledger.phone" class="block text-sm text-gray-700">{{ ledger.phone }}</span>
                            </td>
                            <td class="hidden sm:table-cell">{{ ledger.office }}</td>
                            <td class="num whitespace-nowrap">{{ ledger.balance }}</td>
                            <td class="text-right">
                                <MoreMenu v-if="ledger.can_edit || ledger.can_toggle" :label="`More actions for ${ledger.name}`">
                                    <MenuItem :href="route('ledgers.statement', ledger.id)">Statement</MenuItem>
                                    <MenuItem v-if="ledger.can_edit" :href="route('ledgers.edit', ledger.id)">Edit</MenuItem>
                                    <MenuItem v-if="ledger.can_toggle" :danger="ledger.is_active" @click="target = ledger">
                                        {{ ledger.is_active ? 'Switch off' : 'Switch on' }}
                                    </MenuItem>
                                </MoreMenu>
                                <span v-else-if="ledger.is_system" class="text-sm text-gray-700">System</span>
                            </td>
                        </tr>
                    </tbody>

                    <tbody v-if="!ledgers.data.length">
                        <tr>
                            <td colspan="4" class="py-10 text-center">
                                <p class="text-base text-gray-700">
                                    {{ hasFilter ? 'No ledgers match your search.' : 'No ledgers yet.' }}
                                </p>
                                <SecondaryButton v-if="hasFilter" type="button" class="mt-3" @click="clearFilters">Clear search</SecondaryButton>
                                <ButtonLink v-else-if="canCreate" :href="route('ledgers.create')" class="mt-3">Add the first ledger</ButtonLink>
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
