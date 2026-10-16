<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import StatCard from '@/Components/StatCard.vue';
import TextInput from '@/Components/TextInput.vue';

/**
 * One page for every report, so they all look and work alike: a period, a few figures, then the tables.
 * Each table can be printed, and downloaded for a spreadsheet.
 */
const props = defineProps({
    report: String,
    title: String,
    covers: String,
    period: Object,
    stats: Array,
    tables: Array,
    limit: Number,
    truncated: Boolean,
    notes: Array,
    person: { type: Object, default: null },
});

const filter = reactive({ from: props.period.from, to: props.period.to, ledger_id: props.person?.id ?? '' });

// Two reports are about one person or account, so they ask which, above the period.
const pickers = {
    supplier: { label: 'Supplier or customer', kinds: 'supplier,customer', ask: 'Choose the supplier or customer above, then the period, to see the gold they supplied.' },
    ledger: { label: 'Ledger', kinds: '', ask: 'Choose the ledger above, then the period, to see its entries and balance.' },
};
const picker = computed(() => pickers[props.report] ?? null);

// Quick periods, worked out on this computer's own calendar.
const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const presets = {
    today: () => { const d = new Date(); return [iso(d), iso(d)]; },
    week: () => { const d = new Date(); const s = new Date(d); s.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return [iso(s), iso(d)]; },
    month: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth(), 1)), iso(d)]; },
    lastmonth: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), d.getMonth() - 1, 1)), iso(new Date(d.getFullYear(), d.getMonth(), 0))]; },
    year: () => { const d = new Date(); return [iso(new Date(d.getFullYear(), 0, 1)), iso(d)]; },
};

function choosePreset(event) {
    const make = presets[event.target.value];
    if (make) [filter.from, filter.to] = make();
    event.target.value = '';
}

const query = (extra = {}) => Object.fromEntries(Object.entries({ ...filter, ...extra }).filter(([, v]) => v));
const show = () => router.get(route(`reports.${props.report}`), query(), { preserveScroll: true });
const downloadHref = computed(() => route(`reports.${props.report}`, query({ export: 'csv' })));
const hasRows = (table) => table.rows.length > 0;
const print = () => window.print();
const select = 'mt-1 block min-h-12 w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout :title="title" :back="{ href: route('reports.index'), label: 'Reports' }" width="6xl">
        <template #subtitle>{{ covers }} · {{ period.label }}<span v-if="person"> · {{ person.name }}</span></template>
        <template v-if="tables.length" #actions>
            <MoreMenu label="Print or download this report">
                <MenuItem @click="print">Print this report</MenuItem>
                <MenuItem :href="downloadHref" external>Download for a spreadsheet</MenuItem>
            </MoreMenu>
        </template>

        <div class="mx-auto max-w-6xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <!-- The period, and for the supplier report, who. -->
            <form class="card grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end print:hidden" @submit.prevent="show">
                <div v-if="picker" class="sm:col-span-2 lg:col-span-5">
                    <LedgerPicker
                        v-model="filter.ledger_id"
                        input-id="person"
                        :label="picker.label"
                        :kinds="picker.kinds"
                        :initial="person ? { id: person.id, name: person.name, kind_label: person.kind_label, balance: '' } : null"
                    />
                </div>

                <div>
                    <InputLabel for="preset" value="Period" />
                    <select id="preset" :class="select" @change="choosePreset">
                        <option value="">Choose a period…</option>
                        <option value="today">Today</option>
                        <option value="week">This week</option>
                        <option value="month">This month</option>
                        <option value="lastmonth">Last month</option>
                        <option value="year">This year</option>
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
                <div class="lg:col-span-2">
                    <PrimaryButton class="w-full sm:w-auto">Show report</PrimaryButton>
                </div>
            </form>

            <p v-if="picker && !person" class="card p-6 text-base text-gray-700 print:hidden">{{ picker.ask }}</p>

            <Banner v-if="truncated" variant="warning">
                This period has more than {{ limit }} rows, so only the first {{ limit }} are listed. The totals still cover all of it.
                Choose a shorter period to see the rest.
            </Banner>

            <ul v-if="stats.length" class="grid grid-cols-1 gap-4 @md:grid-cols-2 @6xl:grid-cols-4">
                <li v-for="stat in stats" :key="stat.label"><StatCard :label="stat.label" :value="stat.value" :unit="stat.unit ?? null" /></li>
            </ul>

            <section v-for="table in tables" :key="table.title" class="card overflow-x-auto">
                <h2 class="px-4 pt-4 text-lg font-semibold text-gray-900">{{ table.title }}</h2>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th v-for="column in table.columns" :key="column.label" scope="col" :class="{ num: column.number }">{{ column.label }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(row, index) in table.rows" :key="index">
                            <tr v-if="row.heading" class="group-row">
                                <th scope="colgroup" :colspan="table.columns.length">{{ row.heading }}</th>
                            </tr>
                            <tr v-else>
                                <td v-for="(cell, i) in row" :key="i" :class="{ num: table.columns[i]?.number, 'whitespace-nowrap': table.columns[i]?.number || ['Date', 'Number'].includes(table.columns[i]?.label) }">{{ cell }}</td>
                            </tr>
                        </template>
                        <tr v-if="!hasRows(table)">
                            <td :colspan="table.columns.length" class="py-8 text-center text-gray-700">Nothing in this period.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="table.totals">
                        <tr>
                            <td v-for="(cell, i) in table.totals" :key="i" :class="{ num: table.columns[i]?.number }">{{ cell }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <ul v-if="notes.length" class="space-y-1 text-base text-gray-700">
                <li v-for="note in notes" :key="note">{{ note }}</li>
            </ul>
            <p v-if="tables.length && report !== 'summary'" class="text-sm text-gray-700">Reversed entries, and the reversals that cancel them, are left out of the lists.</p>
        </div>
    </AuthenticatedLayout>
</template>
