<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    where: String,
    stats: Array,
    recent: Array,
    canCreate: Boolean,
    activity: { type: Array, default: null },
});

const isEmpty = computed(() => props.stats.every((s) => s.count === 0) && props.recent.length === 0);

const ago = (iso) =>
    new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <Head title="Home" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-900">Home</h1>
            <p class="mt-1 text-sm text-gray-600">{{ where }}</p>
        </template>

        <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- What you have. Most important first. -->
            <section aria-labelledby="have-heading">
                <h2 id="have-heading" class="text-base font-semibold text-gray-900">What you have</h2>
                <ul class="mt-3 grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <li v-for="stat in stats" :key="stat.label">
                        <Link
                            :href="stat.href"
                            class="block min-h-[44px] rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-100 transition hover:ring-teal-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                        >
                            <p class="text-sm text-gray-600">{{ stat.label }}</p>
                            <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">{{ stat.count }}</p>
                        </Link>
                    </li>
                </ul>
            </section>

            <!-- Next step: one main action, one quieter one. -->
            <section aria-labelledby="next-heading" class="rounded-lg bg-white p-6 shadow-sm">
                <h2 id="next-heading" class="text-base font-semibold text-gray-900">
                    {{ isEmpty ? 'Start by adding your first ledger' : 'What would you like to do?' }}
                </h2>
                <p class="mt-1 text-sm text-gray-600">
                    {{ isEmpty
                        ? 'A ledger is an account for a customer, supplier, bank or cost. Add the ones you deal with most.'
                        : 'Add a new customer, supplier or account, or look up one you already have.' }}
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <Link v-if="canCreate" :href="route('ledgers.create')">
                        <PrimaryButton class="min-h-[44px]" type="button">Add ledger</PrimaryButton>
                    </Link>
                    <Link
                        :href="route('ledgers.index')"
                        class="inline-flex min-h-[44px] items-center rounded-md border border-gray-300 px-5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Find a ledger
                    </Link>
                </div>
            </section>

            <div class="grid gap-8 lg:grid-cols-2">
                <!-- Recently added -->
                <section v-if="recent.length" aria-labelledby="recent-heading" class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 id="recent-heading" class="text-base font-semibold text-gray-900">Recently added</h2>
                    <ul class="mt-3 divide-y divide-gray-100">
                        <li v-for="item in recent" :key="item.id" class="flex items-baseline justify-between gap-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900">{{ item.name }}</p>
                                <p class="text-xs text-gray-600">{{ item.kind_label }} · {{ item.office }}</p>
                            </div>
                            <p class="shrink-0 text-xs text-gray-500">{{ ago(item.added) }}</p>
                        </li>
                    </ul>
                    <Link :href="route('ledgers.index')" class="mt-3 inline-block text-sm font-medium text-teal-700 hover:underline">
                        See all ledgers
                    </Link>
                </section>

                <!-- Recent activity, for owners and admins -->
                <section v-if="activity && activity.length" aria-labelledby="activity-heading" class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 id="activity-heading" class="text-base font-semibold text-gray-900">Recent activity</h2>
                    <ul class="mt-3 divide-y divide-gray-100">
                        <li v-for="log in activity" :key="log.id" class="py-3">
                            <p class="text-sm text-gray-900">
                                <span class="font-semibold">{{ log.actor }}</span> {{ log.description }}
                            </p>
                            <p class="text-xs text-gray-500">{{ ago(log.when) }}</p>
                        </li>
                    </ul>
                    <Link :href="route('activity.index')" class="mt-3 inline-block text-sm font-medium text-teal-700 hover:underline">
                        See all activity
                    </Link>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
