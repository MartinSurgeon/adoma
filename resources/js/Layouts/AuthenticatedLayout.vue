<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';
import ConfirmHost from '@/Components/ConfirmHost.vue';
import MenuItem from '@/Components/MenuItem.vue';
import NavIcon from '@/Components/NavIcon.vue';
import OfficeSwitcher from '@/Components/OfficeSwitcher.vue';
import PopoverMenu from '@/Components/PopoverMenu.vue';
import SidebarNav from '@/Components/SidebarNav.vue';
import { logOut } from '@/logout';
import { useNavigation } from '@/navigation';
import { sidebarCollapsed } from '@/sidebar';
import KarlMark from '@/Components/KarlMark.vue';

/**
 * The frame around every signed-in page.
 *
 * Wide screens: a sidebar that can fold to icons (the menu button at the top left, remembered per browser).
 * Phones and tablets: a bar of five at the bottom, and the full menu in a drawer from the same menu button.
 *
 * The page's title sits right above its content and lines up with it: pass the same width the content uses.
 */
const props = defineProps({
    title: { type: String, default: null },
    subtitle: { type: String, default: null },
    back: { type: Object, default: null }, // { href, label }: the page this one belongs to
    width: { type: String, default: '7xl' }, // 3xl, 5xl, 6xl or 7xl: the same as the page's content
});

const page = usePage();
const user = computed(() => page.props.auth.user);
const { tenancy, newItems, mainItems, incoming } = useNavigation();

const widths = { '3xl': 'max-w-3xl', '5xl': 'max-w-5xl', '6xl': 'max-w-6xl', '7xl': 'max-w-7xl' };
const container = computed(() => widths[props.width] ?? widths['7xl']);

// A one-time note from the server about what just happened, such as where switching branch took you.
const notice = computed(() => page.props.flash?.notice);

// ---- Wide or narrow: the menu button folds the sidebar on a wide screen and opens the drawer on a narrow one.
const wideQuery = window.matchMedia('(min-width: 1024px)');
const isWide = ref(wideQuery.matches);

const menuButton = ref(null);
const drawerOpen = ref(false);
const drawer = ref(null);

const menuLabel = computed(() => {
    if (!isWide.value) return 'Open menu';
    return sidebarCollapsed.value ? 'Expand menu' : 'Collapse menu';
});

function pressMenu() {
    if (isWide.value) {
        sidebarCollapsed.value = !sidebarCollapsed.value;
    } else {
        openDrawer();
    }
}

async function openDrawer() {
    drawerOpen.value = true;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onDrawerKey);
    await nextTick();
    drawer.value?.querySelector('[data-autofocus]')?.focus();
}

function closeDrawer(returnFocus = true) {
    if (!drawerOpen.value) return;
    drawerOpen.value = false;
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onDrawerKey);
    if (returnFocus) menuButton.value?.focus();
}

// Inside the drawer, Tab goes round its own links and Escape closes it.
function onDrawerKey(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeDrawer();
        return;
    }

    if (event.key !== 'Tab' || !drawer.value) return;

    const focusable = [...drawer.value.querySelectorAll('a[href], button:not([disabled]), select, input, [tabindex]:not([tabindex="-1"])')];
    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (!drawer.value.contains(document.activeElement)) {
        event.preventDefault();
        first?.focus();
    } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}

function onWideChange(event) {
    isWide.value = event.matches;
    if (event.matches) closeDrawer(false);
}

onMounted(() => wideQuery.addEventListener('change', onWideChange));
onBeforeUnmount(() => {
    wideQuery.removeEventListener('change', onWideChange);
    closeDrawer(false);
});

// ---- The phone's bottom bar: five at most, the most used. "New" sits in the middle for anyone who records.
const phoneItems = computed(() => {
    const keys = newItems.value.length
        ? ['home', 'transactions', 'new', 'stock', 'ledgers']
        : ['home', 'transactions', 'stock', 'ledgers', 'reports'];

    return keys
        .map((key) => (key === 'new' ? { key: 'new' } : mainItems.value.find((item) => item.key === key)))
        .filter(Boolean);
});

const initial = computed(() => (user.value?.name ?? '?').trim().charAt(0).toUpperCase());
</script>

<template>
    <div class="min-h-screen bg-gray-50">
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[80] focus:rounded-md focus:bg-white focus:px-4 focus:py-3 focus:text-teal-900 focus:shadow"
        >
            Skip to content
        </a>

        <!-- Sidebar, wide screens. Folds to icons; the width is shared with the page through --sidebar-width. -->
        <div
            class="fixed inset-y-0 left-0 z-30 hidden flex-col bg-gradient-to-b from-teal-900 to-teal-950 print:hidden lg:flex motion-safe:transition-[width] motion-safe:duration-200"
            style="width: var(--sidebar-width)"
        >
            <SidebarNav :collapsed="sidebarCollapsed" />
        </div>

        <!-- The menu drawer, phones and tablets. -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition-opacity duration-200 motion-reduce:transition-none"
                enter-from-class="opacity-0"
                leave-active-class="transition-opacity duration-150 motion-reduce:transition-none"
                leave-to-class="opacity-0"
            >
                <div v-if="drawerOpen" id="menu-drawer" class="fixed inset-0 z-50 print:hidden lg:hidden" role="dialog" aria-modal="true" aria-label="Menu">
                    <div class="absolute inset-0 bg-gray-900/50" aria-hidden="true" @click="closeDrawer()"></div>
                    <div ref="drawer" class="absolute inset-y-0 left-0 flex w-80 max-w-[85vw] flex-col bg-gradient-to-b from-teal-900 to-teal-950 shadow-2xl">
                        <SidebarNav @navigate="closeDrawer(false)">
                            <template #brand-end>
                                <button
                                    type="button"
                                    data-autofocus
                                    class="inline-flex h-12 w-12 items-center justify-center rounded-lg text-teal-50 hover:bg-white/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                                    aria-label="Close menu"
                                    @click="closeDrawer()"
                                >
                                    <NavIcon name="close" class="h-6 w-6" />
                                </button>
                            </template>
                        </SidebarNav>
                    </div>
                </div>
            </Transition>
        </Teleport>

        <div class="lg:ps-[var(--sidebar-width)] motion-safe:transition-[padding] motion-safe:duration-200 print:!ps-0">
            <!-- Top bar: the menu button, then where you are working and who you are. -->
            <header class="sticky top-0 z-20 flex h-16 items-center gap-1 border-b border-gray-200 bg-white px-2 print:hidden sm:gap-2 sm:px-4 lg:px-6">
                <button
                    ref="menuButton"
                    type="button"
                    class="relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-gray-700 hover:bg-gray-100 hover:text-gray-900"
                    :aria-label="menuLabel"
                    :title="menuLabel"
                    :aria-expanded="isWide ? !sidebarCollapsed : drawerOpen"
                    :aria-controls="isWide ? undefined : 'menu-drawer'"
                    @click="pressMenu"
                >
                    <NavIcon name="menu" class="h-6 w-6" />
                    <!-- On a phone the incoming-gold count lives in the drawer, so the button carries a dot. -->
                    <span v-if="incoming > 0" class="absolute right-2 top-2 h-3 w-3 rounded-full bg-amber-500 ring-2 ring-white lg:hidden" aria-hidden="true"></span>
                </button>

                <Link :href="route('dashboard')" class="inline-flex min-h-12 shrink-0 items-center gap-2 rounded-lg px-1 text-lg font-semibold text-teal-900 lg:hidden">
                    <KarlMark class="hidden h-9 w-9 shrink-0 min-[400px]:block" />
                    <span>Karl<span class="text-amber-700" aria-hidden="true">.</span></span>
                </Link>

                <div class="ms-auto flex min-w-0 items-center gap-1 sm:gap-3">
                    <OfficeSwitcher />

                    <PopoverMenu
                        placement="bottom-end"
                        menu-class="w-72 max-w-[calc(100vw-1rem)]"
                        button-class="inline-flex min-h-12 min-w-12 items-center gap-2 rounded-lg px-1.5 text-base font-medium text-gray-800 hover:bg-gray-100 sm:px-2"
                    >
                        <template #button="{ open }">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-100 text-base font-semibold text-teal-900" aria-hidden="true">{{ initial }}</span>
                            <span class="hidden max-w-[10rem] truncate md:inline">{{ user.name }}</span>
                            <span class="sr-only md:hidden">{{ user.name }}</span>
                            <NavIcon name="chevronDown" class="hidden h-4 w-4 text-gray-700 transition-transform sm:block" :class="{ 'rotate-180': open }" />
                        </template>

                        <div class="border-b border-gray-100 px-4 py-3">
                            <p class="truncate text-base font-semibold text-gray-900">{{ user.name }}</p>
                            <p class="truncate text-sm text-gray-700">{{ user.email }}</p>
                            <p v-if="tenancy?.tenant" class="mt-1 truncate text-sm text-gray-700">{{ tenancy.tenant }}</p>
                        </div>
                        <MenuItem :href="route('profile.edit')" :current="route().current('profile.*')">
                            <NavIcon name="profile" class="h-5 w-5 shrink-0 text-gray-700" />
                            My profile
                        </MenuItem>
                        <!-- Asks first (see logout.js). -->
                        <MenuItem @click="logOut">
                            <NavIcon name="logout" class="h-5 w-5 shrink-0 text-gray-700" />
                            Log out
                        </MenuItem>
                    </PopoverMenu>
                </div>
            </header>

            <main id="main" tabindex="-1" class="@container pb-32 focus:outline-none lg:pb-12">
                <div v-if="notice" class="mx-auto px-4 pt-6 sm:px-6 lg:px-8 print:hidden" :class="container">
                    <Banner variant="info"><p>{{ notice }}</p></Banner>
                </div>

                <!-- The page's title, lined up with its content. -->
                <div v-if="title || $slots.header" class="mx-auto px-4 sm:px-6 lg:px-8" :class="[container, back ? 'pt-3' : 'pt-6']">
                    <Link
                        v-if="back"
                        :href="back.href"
                        class="-ms-2 mb-1 inline-flex min-h-12 items-center gap-2 rounded-lg px-2 text-base font-medium text-teal-800 hover:bg-teal-50 hover:text-teal-900 print:hidden"
                    >
                        <NavIcon name="back" class="h-5 w-5" />
                        {{ back.label }}
                    </Link>

                    <slot name="header">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <h1 class="break-words text-2xl font-semibold leading-tight text-gray-900">{{ title }}</h1>
                                    <slot name="status" />
                                </div>
                                <p v-if="subtitle || $slots.subtitle" class="mt-1 text-base text-gray-700">
                                    <slot name="subtitle">{{ subtitle }}</slot>
                                </p>
                            </div>
                            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-3 print:hidden">
                                <slot name="actions" />
                            </div>
                        </div>
                    </slot>
                </div>

                <slot />
            </main>
        </div>

        <!-- Bottom bar, phones and tablets: five at most, "New" in the middle. -->
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)] print:hidden lg:hidden" aria-label="Quick links">
            <!-- Each takes the room its word needs plus an equal share of the rest, so "Transactions" is never cut. -->
            <ul class="flex">
                <li v-for="item in phoneItems" :key="item.key" class="min-w-0 flex-auto">
                    <PopoverMenu
                        v-if="item.key === 'new'"
                        block
                        placement="top"
                        menu-class="w-80 max-w-[calc(100vw-1rem)]"
                        button-class="flex min-h-[56px] w-full flex-col items-center justify-center gap-1 px-1 text-sm font-semibold text-teal-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600"
                    >
                        <template #button>
                            <span
                                class="flex h-8 w-12 items-center justify-center rounded-full bg-gradient-to-b from-teal-700 to-teal-800 text-white shadow-md shadow-teal-900/25 ring-1 ring-inset ring-white/10"
                                aria-hidden="true"
                            >
                                <NavIcon name="plus" class="h-6 w-6" stroke-width="2.2" />
                            </span>
                            New
                        </template>

                        <MenuItem v-for="choice in newItems" :key="choice.key" :href="choice.href" :current="choice.active">
                            <span class="icon-chip" aria-hidden="true"><NavIcon :name="choice.icon" class="h-5 w-5" /></span>
                            <span class="min-w-0">
                                <span class="block font-medium">{{ choice.label }}</span>
                                <span class="block text-sm text-gray-700">{{ choice.hint }}</span>
                            </span>
                        </MenuItem>
                    </PopoverMenu>

                    <Link
                        v-else
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        class="relative flex min-h-[56px] flex-col items-center justify-center gap-1 px-1 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-teal-600"
                        :class="item.active ? 'text-teal-800' : 'text-gray-700'"
                    >
                        <NavIcon :name="item.icon" class="h-6 w-6" :stroke-width="item.active ? 2.2 : 1.8" />
                        <span class="whitespace-nowrap">{{ item.label }}</span>
                        <span v-if="item.active" class="absolute inset-x-0 top-0 mx-auto h-1 w-10 rounded-b-full bg-amber-400" aria-hidden="true"></span>
                    </Link>
                </li>
            </ul>
        </nav>

        <!-- The one "Are you sure?" dialog every page and menu asks through (see confirm.js). -->
        <ConfirmHost />
    </div>
</template>
