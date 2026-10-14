<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import OfficeSwitcher from '@/Components/OfficeSwitcher.vue';

const page = usePage();
const tenancy = computed(() => page.props.tenancy);
const isAdmin = computed(() => Boolean(tenancy.value?.can_view_all));
const canRecord = computed(() => Boolean(tenancy.value?.can_record));
const canTransfer = computed(() => Boolean(tenancy.value?.can_transfer));

// Icons are 24 x 24 outlines. Each page that exists gets one entry; more arrive with each phase.
const icons = {
    home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    purchase: 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3',
    sale: 'M12 15V3m0 0L8 7m4-4l4 4M4 17v3h16v-3',
    receipt: 'M12 4v10m0 0l-4-4m4 4l4-4M5 20h14',
    payment: 'M12 20V10m0 0l-4 4m4-4l4 4M5 4h14',
    transactions: 'M5 6h14M5 12h14M5 18h9',
    transfer: 'M4 8h13m0 0l-3-3m3 3l-3 3M20 16H7m0 0l3-3m-3 3l3 3',
    stock: 'M3 9l9-5 9 5v8l-9 5-9-5V9zM3 9l9 5 9-5M12 14v8',
    ledgers: 'M5 4h12a2 2 0 012 2v14H7a2 2 0 01-2-2V4zM9 8h6M9 12h6',
    users: 'M16 20v-1a4 4 0 00-4-4H8a4 4 0 00-4 4v1M10 11a3 3 0 100-6 3 3 0 000 6zM20 20v-1a3 3 0 00-2-2.8M15 5.2a3 3 0 010 5.6',
    activity: 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
};

// Predictable and shallow: one level, grouped the way the work is done.
const sections = computed(() =>
    [
        {
            title: null,
            items: [{ label: 'Home', href: route('dashboard'), active: route().current('dashboard'), icon: 'home', show: true, mobile: true }],
        },
        {
            title: 'New',
            items: [
                { label: 'Purchase', href: route('purchases.create'), active: route().current('purchases.*'), icon: 'purchase', show: canRecord.value, mobile: true },
                { label: 'Sale', href: route('sales.create'), active: route().current('sales.*'), icon: 'sale', show: canRecord.value, mobile: true },
                { label: 'Receipt', href: route('receipts.create'), active: route().current('receipts.*'), icon: 'receipt', show: canRecord.value, mobile: true },
                { label: 'Payment', href: route('payments.create'), active: route().current('payments.*'), icon: 'payment', show: canRecord.value, mobile: true },
                // Managers only. On a phone it moves into the account menu, so the bottom bar stays at five.
                { label: 'Transfer', href: route('transfers.create'), active: route().current('transfers.*'), icon: 'transfer', show: canTransfer.value, mobile: false },
            ],
        },
        {
            title: 'Find',
            items: [
                { label: 'Transactions', href: route('documents.index'), active: route().current('documents.*'), icon: 'transactions', show: Boolean(tenancy.value), mobile: false },
                { label: 'Ledgers', href: route('ledgers.index'), active: route().current('ledgers.*'), icon: 'ledgers', show: Boolean(tenancy.value), mobile: false },
                { label: 'Stock', href: route('stock.index'), active: route().current('stock.*'), icon: 'stock', show: Boolean(tenancy.value), mobile: false },
            ],
        },
        {
            title: 'Settings',
            items: [
                { label: 'Users', href: route('users.index'), active: route().current('users.*'), icon: 'users', show: isAdmin.value, mobile: false },
                { label: 'Activity', href: route('activity.index'), active: route().current('activity.*'), icon: 'activity', show: isAdmin.value, mobile: false },
            ],
        },
    ]
        .map((section) => ({ ...section, items: section.items.filter((item) => item.show) }))
        .filter((section) => section.items.length > 0),
);

// A phone's bottom bar holds five at most. The settings pages move into the account menu there.
// People who cannot record anything get the Find pages in the bar instead, so it is never nearly empty.
const phoneItems = computed(() =>
    sections.value
        .flatMap((section) => section.items)
        .filter((item) => item.mobile || (!canRecord.value && ['Transactions', 'Ledgers'].includes(item.label)))
        .slice(0, 5),
);
const phoneSettings = computed(() =>
    sections.value
        .flatMap((section) => section.items)
        .filter((item) => !phoneItems.value.some((shown) => shown.label === item.label)),
);
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-teal-900 focus:shadow"
        >
            Skip to content
        </a>

        <!-- Sidebar, large screens -->
        <aside class="fixed inset-y-0 left-0 z-30 hidden w-64 flex-col bg-teal-900 lg:flex" aria-label="Main">
            <div class="flex h-16 items-center px-6">
                <Link :href="route('dashboard')" class="inline-flex min-h-12 items-center text-xl font-semibold tracking-tight text-white">
                    Adoma<span class="text-amber-400">.</span>
                </Link>
            </div>

            <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
                <div v-for="(section, index) in sections" :key="index">
                    <p v-if="section.title" class="px-3 pb-2 text-sm font-semibold uppercase tracking-wider text-teal-100">
                        {{ section.title }}
                    </p>
                    <ul class="space-y-1">
                        <li v-for="item in section.items" :key="item.label">
                            <Link
                                :href="item.href"
                                :aria-current="item.active ? 'page' : undefined"
                                class="group flex min-h-12 items-center gap-3 rounded-md px-3 text-base font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                :class="item.active ? 'bg-teal-800 text-white' : 'text-teal-50 hover:bg-teal-800/60 hover:text-white'"
                            >
                                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path :d="icons[item.icon]" />
                                </svg>
                                {{ item.label }}
                                <span v-if="item.active" class="ms-auto h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>
        </aside>

        <div class="lg:pl-64">
            <!-- Top bar -->
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-gray-200 bg-white px-4 sm:px-6 lg:px-8">
                <Link :href="route('dashboard')" class="inline-flex min-h-12 items-center text-lg font-semibold text-teal-900 lg:hidden">
                    Adoma<span class="text-amber-700" aria-hidden="true">.</span>
                </Link>
                <span class="hidden text-base text-gray-700 lg:block">{{ tenancy?.tenant }}</span>

                <div class="flex items-center gap-2 sm:gap-3">
                    <OfficeSwitcher />

                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="inline-flex min-h-12 items-center gap-2 rounded-md px-3 text-base font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                            >
                                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-100 text-sm font-semibold text-teal-900" aria-hidden="true">
                                    {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                                </span>
                                <span class="hidden max-w-[10rem] truncate sm:inline">{{ $page.props.auth.user.name }}</span>
                                <svg class="h-4 w-4 text-gray-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                                <span class="sr-only">Account menu</span>
                            </button>
                        </template>

                        <template #content>
                            <div class="border-b border-gray-100 px-4 py-2 text-base text-gray-700">{{ $page.props.auth.user.email }}</div>
                            <div v-if="phoneSettings.length" class="lg:hidden">
                                <DropdownLink v-for="item in phoneSettings" :key="item.label" :href="item.href">{{ item.label }}</DropdownLink>
                            </div>
                            <DropdownLink :href="route('profile.edit')">My profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">Log out</DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </header>

            <!-- Page heading -->
            <div v-if="$slots.header" class="border-b border-gray-200 bg-white">
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </div>

            <main id="main" class="pb-24 lg:pb-10">
                <slot />
            </main>
        </div>

        <!-- Bottom bar, phones and tablets -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white lg:hidden" aria-label="Main">
            <ul class="grid" :style="{ gridTemplateColumns: `repeat(${phoneItems.length}, minmax(0, 1fr))` }">
                <li v-for="item in phoneItems" :key="item.label">
                    <Link
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        class="flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600"
                        :class="item.active ? 'text-teal-800' : 'text-gray-700'"
                    >
                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="item.active ? 2.2 : 1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path :d="icons[item.icon]" />
                        </svg>
                        {{ item.label }}
                        <span v-if="item.active" class="h-1 w-6 rounded-full bg-amber-400" aria-hidden="true"></span>
                    </Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
