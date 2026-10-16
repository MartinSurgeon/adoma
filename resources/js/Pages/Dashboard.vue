<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import NavIcon from '@/Components/NavIcon.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    where: String,
    goldInStock: String,
    goldByForm: { type: Array, default: () => [] },
    stats: Array,
    recent: Array,
    canCreate: Boolean,
    canRecord: Boolean,
    canTransfer: Boolean,
    stockByOffice: { type: Array, default: null }, // owners and admins looking at every branch
});

// Gold other branches have sent that a manager here has not yet accepted.
const waiting = computed(() => Number(usePage().props.tenancy?.incoming_transfers ?? 0));

const isEmpty = computed(() => props.stats.every((s) => s.count === 0) && props.recent.length === 0);

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

// A picture for each count and each kind of transaction, so they are found by shape before they are read.
const statIcon = { Customers: 'users', Suppliers: 'truck', Banks: 'bank', 'Cash accounts': 'cash' };
const typeIcon = { purchase: 'purchase', sale: 'sale', receipt: 'receipt', payment: 'payment', transfer: 'transfer', transfer_in: 'transfer' };

// Teal for what comes in, amber for what goes out, grey for what was undone.
const tone = (item) => {
    if (item.status === 'reversed' || item.is_reversal) return 'bg-gray-100 text-gray-600 ring-gray-200';
    return ['purchase', 'receipt', 'transfer_in'].includes(item.type) ? 'bg-teal-50 text-teal-800 ring-teal-200' : 'bg-amber-50 text-amber-800 ring-amber-200';
};
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout title="Home" :subtitle="where" width="7xl">
        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="waiting > 0" variant="warning">
                <p class="font-semibold">{{ waiting === 1 ? 'Gold is waiting for you to accept' : `${waiting} transfers of gold are waiting for you to accept` }}</p>
                <Link :href="route('transfers.incoming')" class="mt-1 inline-flex min-h-12 items-center font-medium underline">Review and accept</Link>
            </Banner>

            <!-- 1. What you have: the gold first, in the dark card, then the counts. -->
            <section aria-labelledby="have-heading">
                <h2 id="have-heading" class="text-lg font-semibold text-gray-900">What you have</h2>
                <!-- Sized by the room the page has: the gold on its own row, then the four counts, or all five in one row. -->
                <ul class="mt-3 grid grid-cols-2 gap-4 @3xl:grid-cols-4 @6xl:grid-cols-5">
                    <li class="col-span-2 @3xl:col-span-4 @6xl:col-span-1">
                        <StatCard label="Gold in stock" :value="goldInStock" unit="g" featured :href="route('stock.index')">
                            <!-- The total is made of separate kinds of gold, kept apart in the stock. Each kind stays on one
                                 line; the line breaks between kinds when the card is narrow. -->
                            <template v-for="(part, index) in goldByForm" :key="part.label">
                                <span class="whitespace-nowrap">{{ part.label }} <span class="font-semibold tabular-nums text-white">{{ part.grams }}</span></span>
                                <span v-if="index < goldByForm.length - 1" aria-hidden="true"> · </span>
                            </template>
                        </StatCard>
                    </li>
                    <li v-for="stat in stats" :key="stat.label">
                        <StatCard :label="stat.label" :value="stat.count" :href="stat.href" :icon="statIcon[stat.label] ?? 'ledgers'" />
                    </li>
                </ul>

                <!-- Looking at every branch: how much gold each one holds. -->
                <div v-if="stockByOffice && stockByOffice.length" class="card mt-4 overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th scope="col">Gold in stock, by branch</th>
                                <th scope="col" class="num">Grams</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="office in stockByOffice" :key="office.name">
                                <td>{{ office.name }}</td>
                                <td class="num font-semibold">{{ office.grams }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- 2. What to do next: one main action, one quieter one, the rest in More. -->
            <section aria-labelledby="next-heading" class="card p-6">
                <h2 id="next-heading" class="text-lg font-semibold text-gray-900">
                    {{ isEmpty && canCreate ? 'Start by adding your first ledger' : 'Record' }}
                </h2>
                <p v-if="isEmpty && canCreate" class="mt-1 text-base text-gray-700">
                    A ledger is a customer, supplier, bank or cost you deal with.
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <template v-if="isEmpty && canCreate">
                        <ButtonLink :href="route('ledgers.create')">
                            <NavIcon name="plus" class="h-5 w-5" stroke-width="2" />
                            Add a ledger
                        </ButtonLink>
                        <ButtonLink :href="route('ledgers.index')" variant="secondary">Find a ledger</ButtonLink>
                    </template>

                    <template v-else-if="canRecord">
                        <ButtonLink :href="route('purchases.create')">
                            <NavIcon name="purchase" class="h-5 w-5" stroke-width="2" />
                            Purchase
                        </ButtonLink>
                        <ButtonLink :href="route('sales.create')" variant="secondary">
                            <NavIcon name="sale" class="h-5 w-5" stroke-width="2" />
                            Sale
                        </ButtonLink>
                        <MoreMenu label="More to record">
                            <MenuItem :href="route('receipts.create')"><NavIcon name="receipt" class="h-5 w-5 text-teal-800" />Receipt</MenuItem>
                            <MenuItem :href="route('payments.create')"><NavIcon name="payment" class="h-5 w-5 text-teal-800" />Payment</MenuItem>
                            <MenuItem v-if="canTransfer" :href="route('transfers.create')"><NavIcon name="transfer" class="h-5 w-5 text-teal-800" />Transfer</MenuItem>
                        </MoreMenu>
                    </template>

                    <template v-else>
                        <ButtonLink :href="route('ledgers.index')">Find a ledger</ButtonLink>
                    </template>
                </div>
            </section>

            <!-- 3. Recent activity comes last: what just happened. -->
            <section aria-labelledby="recent-heading" class="card p-6">
                <div class="flex items-center justify-between">
                    <h2 id="recent-heading" class="text-lg font-semibold text-gray-900">Recent activity</h2>
                    <Link
                        v-if="recent.length"
                        :href="route('documents.index')"
                        class="-me-2 inline-flex min-h-12 items-center gap-1 rounded-lg px-2 text-base font-medium text-teal-800 hover:bg-teal-50"
                    >
                        See all
                        <NavIcon name="chevronRight" class="h-5 w-5" stroke-width="2" />
                    </Link>
                </div>

                <ul v-if="recent.length" class="-mx-2 mt-3 divide-y divide-gray-100">
                    <li v-for="item in recent" :key="item.id">
                        <Link :href="route('documents.show', item.id)" class="group flex min-h-12 items-center justify-between gap-4 rounded-lg px-2 py-3 transition hover:bg-gray-50">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ring-1 ring-inset" :class="tone(item)" aria-hidden="true">
                                    <NavIcon :name="typeIcon[item.type] ?? 'transactions'" class="h-5 w-5" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-base font-medium text-gray-900 group-hover:text-teal-900">{{ item.party ?? item.type_label }}</span>
                                    <span class="block text-sm text-gray-700">
                                        {{ item.type_label }} · <span class="tabular-nums">{{ item.number }}</span> · {{ day(item.date) }}
                                        <span v-if="item.status === 'reversed'" class="font-medium text-gray-800"> · Reversed</span>
                                        <span v-else-if="item.is_reversal" class="font-medium text-amber-800"> · Reversal</span>
                                    </span>
                                </span>
                            </div>
                            <span class="shrink-0 text-right text-base font-semibold tabular-nums text-gray-900">{{ item.total }}</span>
                        </Link>
                    </li>
                </ul>

                <div v-else class="mt-6 flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 p-8 text-center">
                    <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon name="transactions" class="h-6 w-6" /></span>
                    <p class="mt-3 text-base font-medium text-gray-900">Nothing recorded yet</p>
                    <p class="mt-1 text-base text-gray-700">Purchases, sales and payments will show here.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
