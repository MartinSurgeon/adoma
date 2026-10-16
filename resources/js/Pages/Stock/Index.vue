<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
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
    onTheWay: { type: Object, default: () => ({ sent: null, incoming: null }) },
});

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Stock" />

    <AuthenticatedLayout title="Stock" :subtitle="`${where} · the gold held, oldest first.`" width="7xl">
        <template v-if="canTransfer" #actions>
            <ButtonLink :href="route('transfers.create')">Send gold to another branch</ButtonLink>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <ul class="grid grid-cols-2 gap-4 @3xl:grid-cols-3">
                <li class="col-span-2 @3xl:col-span-1"><StatCard label="Gold in stock" :value="totals.grams" unit="g" /></li>
                <li :class="canSeeCost ? 'col-span-2 @md:col-span-1' : 'col-span-2 @3xl:col-span-1'"><StatCard label="Lots" :value="totals.lots" /></li>
                <li v-if="canSeeCost" class="col-span-2 @md:col-span-1"><StatCard label="What it cost" :value="totals.cost" /></li>
            </ul>

            <!-- Gold sent and not yet accepted is in nobody's stock, so say where it is. -->
            <Banner v-if="onTheWay.incoming" variant="info">
                {{ onTheWay.incoming.grams }} g from other branches is waiting for you to accept.
                <Link :href="route('transfers.incoming')" class="font-medium underline">Review it</Link>
            </Banner>
            <Banner v-if="onTheWay.sent" variant="info">
                {{ onTheWay.sent.grams }} g you sent is on its way, waiting for the other branch to accept. It is not in your stock until it comes back.
            </Banner>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Came from</th>
                            <th v-if="showOffice" scope="col">Branch</th>
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
                                No gold in stock. A purchase, or gold sent from another branch, adds some.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <Pagination :paginator="lots" />
        </div>
    </AuthenticatedLayout>
</template>
