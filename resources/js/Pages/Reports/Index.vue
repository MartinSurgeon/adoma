<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import NavIcon from '@/Components/NavIcon.vue';

defineProps({ covers: String });

// The five reports the client asked for, each in one short line. More can be added here later.
const reports = [
    { name: 'reports.purchases', title: 'Purchases', icon: 'purchase', text: 'Gold bought in a period, and what it cost.' },
    { name: 'reports.sales', title: 'Sales', icon: 'sale', text: 'Gold sold in a period, and what it came to.' },
    { name: 'reports.supplier', title: 'Supplier report', icon: 'truck', text: 'Gold from one supplier or customer.' },
    { name: 'reports.ledger', title: 'Ledger report', icon: 'book', text: 'One account\'s entries and running balance.' },
    { name: 'reports.summary', title: 'Balances summary', icon: 'summary', text: 'Opening and closing balances, and the gold in stock.' },
];
</script>

<template>
    <Head title="Reports" />

    <AuthenticatedLayout title="Reports" :subtitle="covers" width="5xl">
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <ul class="grid gap-4 sm:grid-cols-2">
                <li v-for="report in reports" :key="report.name">
                    <Link :href="route(report.name)" class="card card-link group flex h-full items-start gap-4 p-6">
                        <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon :name="report.icon" class="h-6 w-6" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-lg font-semibold text-gray-900">{{ report.title }}</span>
                            <span class="mt-1 block text-base text-gray-700">{{ report.text }}</span>
                        </span>
                        <NavIcon name="chevronRight" class="mt-1 h-5 w-5 shrink-0 text-gray-500 transition-transform group-hover:text-teal-700 motion-safe:group-hover:translate-x-0.5" stroke-width="2" />
                    </Link>
                </li>
            </ul>
        </div>
    </AuthenticatedLayout>
</template>
