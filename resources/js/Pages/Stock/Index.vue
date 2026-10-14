<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import Pagination from '@/Components/Pagination.vue';
import StatCard from '@/Components/StatCard.vue';

defineProps({
    where: String,
    showOffice: Boolean,
    lots: Object,
    totals: Object,
    canSeeCost: Boolean,
    canTransfer: Boolean,
});

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Stock" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold leading-tight text-gray-900">Stock</h1>
                    <p class="mt-1 text-base text-gray-700">{{ where }} · the gold held, oldest first.</p>
                </div>
                <ButtonLink v-if="canTransfer" :href="route('transfers.create')">Send gold to another office</ButtonLink>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <ul class="grid grid-cols-2 gap-4 lg:grid-cols-3">
                <li class="col-span-2 lg:col-span-1"><StatCard label="Gold in stock" :value="totals.grams" unit="g" /></li>
                <li><StatCard label="Lots" :value="totals.lots" /></li>
                <li v-if="canSeeCost"><StatCard label="What it cost" :value="totals.cost" /></li>
            </ul>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Came from</th>
                            <th v-if="showOffice" scope="col">Office</th>
                            <th scope="col">Form</th>
                            <th scope="col" class="num">Karat</th>
                            <th scope="col" class="num">Weight in (g)</th>
                            <th scope="col" class="num">Left (g)</th>
                            <th v-if="canSeeCost" scope="col" class="num">Cost of what is left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="lot in lots.data" :key="lot.id">
                            <td class="whitespace-nowrap">{{ day(lot.date) }}</td>
                            <td>
                                <Link v-if="lot.origin_id" :href="route('documents.show', lot.origin_id)" class="inline-flex min-h-12 items-center font-medium text-teal-800 hover:underline">
                                    {{ lot.origin_number }}
                                </Link>
                                <span class="block text-sm text-gray-700">{{ lot.origin_kind }} {{ lot.origin_name }}</span>
                            </td>
                            <td v-if="showOffice">{{ lot.office }}</td>
                            <td>{{ lot.form }}</td>
                            <td class="num">{{ lot.karat }}</td>
                            <td class="num">{{ lot.weight }}</td>
                            <td class="num font-semibold">{{ lot.remaining }}</td>
                            <td v-if="canSeeCost" class="num">{{ lot.cost }}</td>
                        </tr>
                        <tr v-if="!lots.data.length">
                            <td :colspan="4 + (showOffice ? 1 : 0) + 2 + (canSeeCost ? 1 : 0)" class="py-10 text-center text-gray-700">
                                No gold in stock. A purchase, or gold sent from another office, adds some.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="lots" />
        </div>
    </AuthenticatedLayout>
</template>
