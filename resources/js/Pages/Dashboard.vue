<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    where: String,
    goldInStock: String,
    stats: Array,
    recent: Array,
    canCreate: Boolean,
    canRecord: Boolean,
});

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
            <!-- 1. What you have. The stock total opens the page, in the same card as every other figure. -->
            <section aria-labelledby="have-heading">
                <h2 id="have-heading" class="text-lg font-semibold text-gray-900">What you have</h2>
                <ul class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <li class="col-span-2 lg:col-span-1">
                        <StatCard label="Gold in stock" :value="goldInStock" unit="g" />
                    </li>
                    <li v-for="stat in stats" :key="stat.label">
                        <StatCard :label="stat.label" :value="stat.count" :href="stat.href" />
                    </li>
                </ul>
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
                        <ButtonLink :href="route('ledgers.create')">Add a ledger</ButtonLink>
                        <ButtonLink :href="route('ledgers.index')" variant="secondary">Find a ledger</ButtonLink>
                    </template>

                    <template v-else-if="canRecord">
                        <ButtonLink :href="route('purchases.create')">Record a purchase</ButtonLink>
                        <ButtonLink :href="route('sales.create')" variant="secondary">Record a sale</ButtonLink>
                        <MoreMenu label="More ways to record">
                            <MenuItem :href="route('receipts.create')">Record a receipt</MenuItem>
                            <MenuItem :href="route('payments.create')">Record a payment</MenuItem>
                            <MenuItem v-if="canCreate" :href="route('ledgers.create')">Add a ledger</MenuItem>
                            <MenuItem :href="route('ledgers.index')">Find a ledger</MenuItem>
                        </MoreMenu>
                    </template>

                    <template v-else>
                        <ButtonLink :href="route('ledgers.index')">Find a ledger</ButtonLink>
                    </template>
                </div>
            </section>

            <!-- 3. Recent activity comes last: what just happened. -->
            <section aria-labelledby="recent-heading" class="card p-6">
                <h2 id="recent-heading" class="text-lg font-semibold text-gray-900">Recent activity</h2>

                <ul v-if="recent.length" class="mt-3 divide-y divide-gray-200">
                    <li v-for="item in recent" :key="item.id">
                        <Link :href="route('documents.show', item.id)" class="flex min-h-12 items-center justify-between gap-4 py-3 hover:bg-gray-50">
                            <span class="min-w-0">
                                <span class="block truncate text-base font-medium text-gray-900">{{ item.party ?? item.type_label }}</span>
                                <span class="block text-sm text-gray-700">
                                    {{ item.type_label }} · {{ item.number }} · {{ day(item.date) }}
                                    <span v-if="item.status === 'reversed'" class="font-medium"> · Reversed</span>
                                    <span v-else-if="item.is_reversal" class="font-medium"> · Reversal</span>
                                </span>
                            </span>
                            <span class="shrink-0 text-right text-base font-semibold tabular-nums text-gray-900">{{ item.total }}</span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="mt-3 text-base text-gray-700">Nothing has been recorded yet.</p>

                <Link
                    v-if="recent.length"
                    :href="route('documents.index')"
                    class="mt-3 inline-flex min-h-12 items-center text-base font-medium text-teal-800 hover:underline"
                >
                    See all transactions
                </Link>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
