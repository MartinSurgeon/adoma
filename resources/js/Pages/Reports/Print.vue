<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * A report on paper, opened from the report's print menu with the same period and branch.
 *
 * "a4": a sheet with the whole table, every column, sideways when the table is wide; the heading row repeats on each
 * page, and the totals come once, at the end. "thermal": a small receipt for an 80mm receipt printer, each entry a few
 * short lines, label on the left and figure on the right.
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
    layout: String, // a4 or thermal
    business: String,
    printed: String,
});

const thermal = computed(() => props.layout === 'thermal');
const sideways = computed(() => Math.max(...props.tables.map((t) => t.columns.length)) > 7);
const page = computed(() => {
    if (thermal.value) return '@page { size: 80mm auto; margin: 4mm; }';
    return sideways.value ? '@page { size: A4 landscape; margin: 10mm; }' : '@page { size: A4; margin: 15mm; }';
});
const paper = computed(() => (thermal.value ? 'w-[80mm] p-3' : sideways.value ? 'w-[297mm] p-[10mm]' : 'w-[210mm] p-[15mm]'));
const bar = computed(() => (thermal.value ? 'max-w-[80mm]' : sideways.value ? 'max-w-[297mm]' : 'max-w-[210mm]'));

const figure = (stat) => [stat.value, stat.unit].filter(Boolean).join(' '); // 1,495.18 g
const hasRows = (table) => table.rows.length > 0;
const isReference = (label) => ['Number', 'No.'].includes(label);
const isDate = (label) => label === 'Date';

// For the receipt: a row's words (date, number, name) on one line, then each figure that is not empty on its own line.
const words = (table, cells) => cells.filter((cell, i) => !table.columns[i]?.number && cell !== '').join(' · ');
const figuresOf = (table, cells) =>
    cells
        .map((value, i) => ({ value, label: table.columns[i]?.label, number: table.columns[i]?.number }))
        .filter((cell) => cell.number && cell.value !== '')
        .map((cell) => ({ ...cell, main: /^Amount/.test(cell.label ?? '') }));
</script>

<template>
    <Head :title="`${title} report`" />

    <!-- The page asks the printer for the right paper. -->
    <component :is="'style'">{{ page }}</component>

    <div class="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
        <div class="mx-auto mb-4 flex items-center justify-between gap-3 print:hidden" :class="bar">
            <button type="button" onclick="history.length > 1 ? history.back() : window.close()" class="btn btn-secondary px-4">
                Back
            </button>
            <button type="button" onclick="window.print()" class="btn btn-primary">
                Print
            </button>
        </div>

        <!-- On a phone the sheet keeps its paper width, and scrolls sideways inside this box. -->
        <div class="overflow-x-auto print:overflow-visible">
            <article class="mx-auto bg-white text-black shadow print:w-auto print:p-0 print:shadow-none" :class="paper">
                <!-- The small receipt -->
                <template v-if="thermal">
                    <header class="text-center text-[12px] leading-snug">
                        <p v-if="business" class="text-base font-bold">{{ business }}</p>
                        <p class="mt-1 font-semibold uppercase">{{ title }} report</p>
                        <p class="mt-1">{{ covers }}</p>
                        <p>{{ period.label }}</p>
                        <p v-if="person" class="font-semibold">{{ person.name }}</p>
                    </header>

                    <div class="receipt text-[12px] leading-snug">
                        <dl v-if="stats.length" class="rule space-y-0.5">
                            <div v-for="stat in stats" :key="stat.label" class="line">
                                <dt>{{ stat.label }}</dt>
                                <dd class="font-semibold">{{ figure(stat) }}</dd>
                            </div>
                        </dl>

                        <section v-for="table in tables" :key="table.title">
                            <h2 v-if="tables.length > 1 || table.title !== title" class="rule text-center font-bold uppercase">{{ table.title }}</h2>
                            <p v-if="!hasRows(table)" class="rule text-center">Nothing in this period.</p>

                            <template v-for="(row, index) in table.rows" :key="index">
                                <h3 v-if="row.heading" class="rule text-center font-bold uppercase">{{ row.heading }}</h3>
                                <div v-else class="rule entry" :class="{ 'font-semibold': row.subtotal }">
                                    <p class="font-semibold">{{ words(table, row.subtotal ?? row) }}</p>
                                    <dl class="mt-0.5">
                                        <div v-for="figure in figuresOf(table, row.subtotal ?? row)" :key="figure.label" class="line">
                                            <dt>{{ figure.label }}</dt>
                                            <dd :class="{ 'font-bold': figure.main }">{{ figure.value }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </template>

                            <div v-if="table.totals && hasRows(table)" class="rule-double entry font-semibold">
                                <p class="font-bold uppercase">{{ words(table, table.totals) }}</p>
                                <dl class="mt-0.5">
                                    <div v-for="figure in figuresOf(table, table.totals)" :key="figure.label" class="line">
                                        <dt>{{ figure.label }}</dt>
                                        <dd :class="{ 'font-bold': figure.main }">{{ figure.value }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </section>

                        <div class="rule space-y-1">
                            <p v-if="truncated">Only the first {{ limit }} rows are listed. The totals cover the whole period.</p>
                            <p v-for="note in notes" :key="note">{{ note }}</p>
                            <p v-if="report !== 'summary'">Reversed entries, and the reversals that cancel them, are left out.</p>
                        </div>

                        <p class="rule text-center">{{ printed }}</p>
                    </div>
                </template>

                <!-- The A4 sheet -->
                <template v-else>
                    <header class="flex items-end justify-between gap-6 border-b-2 border-black pb-3">
                        <div>
                            <p v-if="business" class="text-[14px] font-semibold uppercase tracking-wide">{{ business }}</p>
                            <h1 class="text-2xl font-bold">{{ title }}</h1>
                        </div>
                        <div class="text-right text-[14px]">
                            <p class="font-semibold">{{ covers }}<template v-if="person"> · {{ person.name }}</template></p>
                            <p>{{ period.label }}</p>
                        </div>
                    </header>

                    <dl v-if="stats.length" class="mt-4 flex flex-wrap gap-x-10 gap-y-2 text-[14px]">
                        <div v-for="stat in stats" :key="stat.label">
                            <dt class="text-gray-700">{{ stat.label }}</dt>
                            <dd class="text-lg font-bold tabular-nums">{{ figure(stat) }}</dd>
                        </div>
                    </dl>

                    <p v-if="truncated" class="mt-4 text-[14px] font-semibold">
                        This period has more than {{ limit }} rows, so only the first {{ limit }} are listed. The totals cover all of it.
                    </p>

                    <section v-for="table in tables" :key="table.title" class="mt-6">
                        <h2 v-if="tables.length > 1 || table.title !== title" class="mb-2 text-base font-bold">{{ table.title }}</h2>
                        <table class="sheet" :class="{ tight: table.columns.length > 9 }">
                            <thead>
                                <tr>
                                    <th v-for="column in table.columns" :key="column.label" scope="col" :class="{ num: column.number }">{{ column.label }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(row, index) in table.rows" :key="index">
                                    <tr v-if="row.heading" class="band">
                                        <th scope="colgroup" :colspan="table.columns.length">{{ row.heading }}</th>
                                    </tr>
                                    <tr v-else-if="row.subtotal" class="subtotal">
                                        <td v-for="(cell, i) in row.subtotal" :key="i" :class="{ num: table.columns[i]?.number }">{{ cell }}</td>
                                    </tr>
                                    <tr v-else>
                                        <td
                                            v-for="(cell, i) in row"
                                            :key="i"
                                            :class="{
                                                num: table.columns[i]?.number,
                                                ref: isReference(table.columns[i]?.label),
                                                'whitespace-nowrap': isReference(table.columns[i]?.label) || isDate(table.columns[i]?.label),
                                            }"
                                        >
                                            {{ cell }}
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!hasRows(table)">
                                    <td :colspan="table.columns.length" class="py-4 text-center">Nothing in this period.</td>
                                </tr>
                                <!-- In the body, not a footer: a printed table footer would repeat on every page. -->
                                <tr v-if="table.totals && hasRows(table)" class="total">
                                    <td v-for="(cell, i) in table.totals" :key="i" :class="{ num: table.columns[i]?.number }">{{ cell }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <ul class="mt-4 space-y-0.5 text-[13px]">
                        <li v-for="note in notes" :key="note">{{ note }}</li>
                        <li v-if="report !== 'summary'">Reversed entries, and the reversals that cancel them, are left out of the lists.</li>
                    </ul>

                    <footer class="mt-6 border-t border-gray-400 pt-2 text-[12px] text-gray-700">{{ printed }}</footer>
                </template>
            </article>
        </div>
    </div>
</template>

<style scoped>
/* ---- The A4 sheet: plain ruled lines, like a printed ledger. ---- */
.sheet {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    line-height: 1.35;
}
.sheet.tight {
    font-size: 12px;
}
.sheet th,
.sheet td {
    padding: 5px 6px;
    text-align: left;
    vertical-align: top;
}
.sheet thead th {
    border-top: 1.5px solid #000;
    border-bottom: 1.5px solid #000;
    font-weight: 700;
    white-space: nowrap;
}
.sheet tbody td {
    border-bottom: 1px solid #d1d5db;
}
.sheet .num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.sheet .ref {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.92em;
    letter-spacing: -0.02em;
}
.sheet .band th {
    padding-top: 10px;
    border-bottom: 1px solid #000;
    font-weight: 700;
}
.sheet .subtotal td {
    border-top: 1px solid #000;
    font-weight: 700;
}
.sheet .total td {
    border-top: 1.5px solid #000;
    border-bottom: 3px double #000;
    font-weight: 700;
}
.sheet tr {
    break-inside: avoid;
}
.sheet thead {
    display: table-header-group; /* repeats at the top of each printed page */
}

/* ---- The small receipt: dashed rules between entries, label left and figure right. ---- */
.receipt .rule {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px dashed #000;
}
.receipt .rule-double {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 3px double #000;
}
.receipt .line {
    display: flex;
    justify-content: space-between;
    gap: 8px;
}
.receipt .line dd {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.receipt .entry {
    break-inside: avoid;
}
</style>
