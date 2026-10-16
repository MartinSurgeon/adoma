<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import BranchStockCard from '@/Components/BranchStockCard.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import NavIcon from '@/Components/NavIcon.vue';
import StatCard from '@/Components/StatCard.vue';
import TransactionsTable from '@/Components/TransactionsTable.vue';
import TransitCard from '@/Components/TransitCard.vue';

const props = defineProps({
    where: String,
    goldInStock: String,
    goldByForm: { type: Array, default: () => [] },
    stats: Array,
    recent: Array,
    canCreate: Boolean,
    canRecord: Boolean,
    canTransfer: Boolean,
    branches: { type: Array, default: null }, // owners and admins looking at every branch
    transit: { type: Array, default: () => [] }, // gold sent and not yet accepted
});

// Gold other branches have sent that a manager here has not yet accepted.
const waiting = computed(() => Number(usePage().props.tenancy?.incoming_transfers ?? 0));

const isEmpty = computed(() => props.stats.every((s) => s.count === 0) && props.recent.length === 0);

// Looking at every branch, the branch column says where each transaction was recorded.
const allBranches = computed(() => props.branches !== null);

// A picture for each count, so they are found by shape before they are read.
const statIcon = { Customers: 'users', Suppliers: 'truck', Banks: 'bank', 'Cash accounts': 'cash' };
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout title="Home" :subtitle="where" width="7xl">
        <div class="mx-auto max-w-7xl space-y-10 px-4 py-8 sm:px-6 lg:px-8">
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
            </section>

            <!-- 2. Looking at every branch: each branch's gold, raw and refined, and what is on its way to it. -->
            <section v-if="branches && branches.length" aria-labelledby="branches-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <h2 id="branches-heading" class="text-lg font-semibold text-gray-900">Branches</h2>
                    <p class="text-sm text-gray-700">Gold arriving counts once it is accepted.</p>
                </div>
                <ul class="mt-3 grid gap-4 @3xl:grid-cols-2 @6xl:grid-cols-3">
                    <li v-for="branch in branches" :key="branch.id"><BranchStockCard :branch="branch" /></li>
                </ul>
            </section>

            <!-- 3. Gold on the road: sent and not yet accepted. -->
            <section v-if="transit.length" id="in-transit" aria-labelledby="transit-heading">
                <h2 id="transit-heading" class="text-lg font-semibold text-gray-900">{{ allBranches ? 'In transit' : 'On the way' }}</h2>
                <ul class="mt-3 grid gap-4 @4xl:grid-cols-2">
                    <li v-for="transfer in transit" :key="transfer.id"><TransitCard :transfer="transfer" /></li>
                </ul>
            </section>

            <!-- 4. What to do next: one main action, one quieter one, the rest in More. Only for someone who can record
                 here (or who should start by adding a ledger): looking at every branch, or read-only, there is nothing to record. -->
            <section v-if="canRecord || (isEmpty && canCreate)" aria-labelledby="next-heading" class="card p-6">
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

                    <template v-else>
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
                </div>
            </section>

            <!-- 5. The latest transactions, last recorded first. -->
            <section aria-labelledby="latest-heading" class="table-card">
                <div class="table-title">
                    <h2 id="latest-heading" class="text-lg font-semibold text-gray-900">Latest transactions</h2>
                    <Link
                        v-if="recent.length"
                        :href="route('documents.index')"
                        class="-me-2 inline-flex min-h-12 items-center gap-1 rounded-lg px-2 text-base font-medium text-teal-800 hover:bg-teal-50"
                    >
                        See all transactions
                        <NavIcon name="arrowRight" class="h-5 w-5" stroke-width="2" />
                    </Link>
                </div>

                <TransactionsTable :rows="recent" :show-office="allBranches" when="recorded">
                    <template #empty>
                        <div class="mx-6 mb-6 flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 p-8 text-center">
                            <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon name="transactions" class="h-6 w-6" /></span>
                            <p class="mt-3 text-base font-medium text-gray-900">Nothing recorded yet</p>
                            <p class="mt-1 text-base text-gray-700">Purchases, sales and payments will show here.</p>
                        </div>
                    </template>
                </TransactionsTable>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
