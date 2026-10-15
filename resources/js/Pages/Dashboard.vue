<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
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
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold leading-tight text-gray-900">Home</h1>
            <p class="mt-1 text-base text-gray-700">{{ where }}</p>
        </template>

        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="waiting > 0" variant="warning">
                <p class="font-semibold">{{ waiting === 1 ? 'Gold is waiting for you to accept' : `${waiting} transfers of gold are waiting for you to accept` }}</p>
                <Link :href="route('transfers.incoming')" class="mt-1 inline-flex min-h-12 items-center font-medium underline">Review and accept</Link>
            </Banner>

            <!-- 1. What you have. The stock total opens the page, in the same card as every other figure. -->
            <section aria-labelledby="have-heading">
                <h2 id="have-heading" class="text-lg font-semibold text-gray-900">What you have</h2>
                <ul class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <li class="col-span-2 lg:col-span-1">
                        <StatCard label="Gold in stock" :value="goldInStock" unit="g" :featured="true" />
                    </li>
                    <li v-for="stat in stats" :key="stat.label">
                        <StatCard :label="stat.label" :value="stat.count" :href="stat.href" />
                    </li>
                </ul>

                <!-- The total is made of separate kinds of gold, kept apart in the stock. -->
                <p v-if="goldByForm.length" class="mt-3 text-base text-gray-700">
                    Of the gold in stock:
                    <template v-for="(part, index) in goldByForm" :key="part.label">
                        <span class="font-semibold tabular-nums text-gray-900">{{ part.label }} {{ part.grams }} g</span><span v-if="index < goldByForm.length - 1"> · </span>
                    </template>
                </p>

                <!-- Looking at every branch: how much gold each one holds, with a way to see the lots. -->
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
                <Link :href="route('stock.index')" class="mt-2 inline-flex min-h-12 items-center text-base font-medium text-teal-800 hover:underline">
                    See the gold lot by lot
                </Link>
            </section>

            <!-- 2. What to do next: one main action, one quieter one, the rest in More. -->
            <section aria-labelledby="next-heading" class="card p-6">
                <h2 id="next-heading" class="text-lg font-semibold text-gray-900">
                    {{ isEmpty && canCreate ? 'Start by adding your first ledger' : 'Record a transaction' }}
                </h2>
                <p class="mt-1 text-base text-gray-700">
                    {{ isEmpty && canCreate
                        ? 'A ledger is an account for a customer, supplier, bank or cost. Add the ones you deal with most.'
                        : 'Gold you bought or sold, or money that came in or went out.' }}
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <template v-if="isEmpty && canCreate">
                        <ButtonLink :href="route('ledgers.create')">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            Add a ledger
                        </ButtonLink>
                        <ButtonLink :href="route('ledgers.index')" variant="secondary">Find a ledger</ButtonLink>
                    </template>

                    <template v-else-if="canRecord">
                        <ButtonLink :href="route('purchases.create')">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3" />
                            </svg>
                            Record a purchase
                        </ButtonLink>
                        <ButtonLink :href="route('sales.create')" variant="secondary">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 15V3m0 0L8 7m4-4l4 4M4 17v3h16v-3" />
                            </svg>
                            Record a sale
                        </ButtonLink>
                        <MoreMenu label="More ways to record">
                            <MenuItem :href="route('receipts.create')">Record a receipt</MenuItem>
                            <MenuItem :href="route('payments.create')">Record a payment</MenuItem>
                            <MenuItem v-if="canTransfer" :href="route('transfers.create')">Send gold to another branch</MenuItem>
                            <MenuItem v-if="canCreate" :href="route('ledgers.create')">Add a ledger</MenuItem>
                            <MenuItem :href="route('ledgers.index')">Find a ledger</MenuItem>
                            <MenuItem :href="route('reports.index')">Reports</MenuItem>
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
                        class="inline-flex min-h-12 items-center text-sm font-medium text-teal-800 hover:underline"
                    >
                        See all transactions &rarr;
                    </Link>
                </div>

                <ul v-if="recent.length" class="mt-4 divide-y divide-gray-100">
                    <li v-for="item in recent" :key="item.id">
                        <Link :href="route('documents.show', item.id)" class="group flex min-h-12 items-center justify-between gap-4 rounded-md py-3.5 px-2 transition hover:bg-gray-50">
                            <div class="flex min-w-0 items-center gap-3">
                                <span
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border text-sm font-semibold"
                                    :class="[
                                        item.status === 'reversed' || item.is_reversal
                                            ? 'border-gray-200 bg-gray-100 text-gray-600'
                                            : item.type === 'purchase' || item.type === 'receipt'
                                              ? 'border-teal-200 bg-teal-50 text-teal-800'
                                              : 'border-amber-200 bg-amber-50 text-amber-800',
                                    ]"
                                    aria-hidden="true"
                                >
                                    {{ item.type_label?.charAt(0) ?? 'T' }}
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

                <div v-else class="mt-6 flex flex-col items-center justify-center rounded-lg border border-dashed border-gray-200 p-8 text-center">
                    <svg class="h-10 w-10 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <p class="mt-2 text-base font-medium text-gray-900">No activity yet</p>
                    <p class="mt-1 text-sm text-gray-600">Once you record purchases, sales, or cash movements, they will show here.</p>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
