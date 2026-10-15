<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

defineProps({ covers: String });

// The four reports the client asked for. More can be added here later.
const reports = [
    { name: 'reports.purchases', title: 'Purchases', text: 'All the gold bought in a period: who from, how much, and what it came to.' },
    { name: 'reports.sales', title: 'Sales', text: 'All the gold sold in a period: who to, how much, and what it came to.' },
    { name: 'reports.supplier', title: 'Supplier report', text: 'The gold one supplier or customer supplied to you in a period.' },
    { name: 'reports.ledger', title: 'Ledger report', text: 'One account\'s entries in a period, with its running balance: a customer, supplier, bank or cash account.' },
    { name: 'reports.summary', title: 'Balances summary', text: 'Every account\'s opening balance and closing balance for a period, and the gold in stock.' },
];
</script>

<template>
    <Head title="Reports" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold leading-tight text-gray-900">Reports</h1>
            <p class="mt-1 text-base text-gray-700">{{ covers }} · choose a report, then the period it should cover.</p>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <ul class="grid gap-4 sm:grid-cols-2">
                <li v-for="report in reports" :key="report.name">
                    <Link
                        :href="route(report.name)"
                        class="card block h-full p-6 transition hover:ring-teal-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                    >
                        <h2 class="text-lg font-semibold text-gray-900">{{ report.title }}</h2>
                        <p class="mt-1 text-base text-gray-700">{{ report.text }}</p>
                    </Link>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
