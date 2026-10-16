<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import MenuItem from '@/Components/MenuItem.vue';
import NavIcon from '@/Components/NavIcon.vue';
import PopoverMenu from '@/Components/PopoverMenu.vue';
import { useNavigation } from '@/navigation';
import { adminGroupOpen } from '@/sidebar';

/**
 * The sidebar's contents: the brand, one "New" menu for recording, the places to go, and the admin pages folded away.
 * Used twice: in the sidebar on wide screens (open, or folded to icons), and in the menu drawer on phones.
 */
const props = defineProps({
    collapsed: { type: Boolean, default: false }, // icons only, with the name shown on hover or focus
});

const emit = defineEmits(['navigate']);

const { newItems, mainItems, adminItems, recording } = useNavigation();

const adminActive = computed(() => adminItems.value.some((item) => item.active));
const adminShown = computed(() => adminGroupOpen.value);

onMounted(() => {
    // Arriving on an admin page opens its group, so the person can see where they are.
    if (adminActive.value) adminGroupOpen.value = true;
});

// Folded to icons, each icon says its name beside it on hover or keyboard focus. The tip is drawn over the page,
// so the sidebar's own scrolling never cuts it off.
const tip = ref(null);

function showTip(event, text) {
    if (!props.collapsed) return;
    const box = event.currentTarget.getBoundingClientRect();
    tip.value = { text, top: box.top + box.height / 2, left: box.right + 12 };
}

const hideTip = () => {
    tip.value = null;
};

const tipFor = (item) => (item.badge ? `${item.label} · ${item.badge} waiting` : item.label);

function go() {
    hideTip();
    emit('navigate');
}

const linkClass = (item) => [
    'group relative flex min-h-12 items-center rounded-md text-base font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
    props.collapsed ? 'mx-auto h-12 w-12 justify-center' : 'gap-3 px-3',
    item.active ? 'bg-teal-800 text-white' : 'text-teal-50 hover:bg-teal-800/60 hover:text-white',
];
</script>

<template>
    <div class="flex h-full flex-col">
        <!-- The brand, and room for the drawer's close button. -->
        <div class="flex h-16 shrink-0 items-center" :class="collapsed ? 'justify-center px-2' : 'justify-between gap-2 pe-2 ps-6'">
            <Link
                :href="route('dashboard')"
                class="inline-flex min-h-12 items-center rounded-md text-xl font-semibold tracking-tight text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                :aria-label="collapsed ? 'Adoma, home' : undefined"
                @click="go"
            >
                <span v-if="collapsed" class="flex h-12 w-12 items-center justify-center rounded-lg bg-teal-800 text-xl font-bold" aria-hidden="true">A<span class="text-amber-400">.</span></span>
                <template v-else>Adoma<span class="text-amber-400" aria-hidden="true">.</span></template>
            </Link>
            <slot name="brand-end" />
        </div>

        <!-- Recording starts here, on every page: one button, then the kind of transaction. -->
        <div v-if="newItems.length" class="shrink-0 pb-3 pt-1" :class="collapsed ? 'px-4' : 'px-3'" @mouseenter="showTip($event, 'New')" @mouseleave="hideTip">
            <PopoverMenu
                :block="!collapsed"
                :placement="collapsed ? 'right-start' : 'bottom-start'"
                :label="collapsed ? 'New' : null"
                menu-class="w-80 max-w-[calc(100vw-1rem)]"
                :button-class="[
                    'flex min-h-12 items-center rounded-md bg-white text-base font-semibold text-teal-900 shadow-sm hover:bg-teal-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
                    collapsed ? 'mx-auto h-12 w-12 justify-center' : 'w-full gap-3 px-3',
                    recording ? 'ring-2 ring-amber-400' : '',
                ]"
                @open="hideTip"
            >
                <template #button="{ open }">
                    <NavIcon name="plus" class="h-6 w-6 shrink-0" stroke-width="2.2" />
                    <template v-if="!collapsed">
                        <span>New</span>
                        <NavIcon name="chevronDown" class="ms-auto h-5 w-5 transition-transform" :class="{ 'rotate-180': open }" />
                    </template>
                </template>

                <p class="px-4 pb-1 pt-2 text-sm font-semibold text-gray-700">Record a new…</p>
                <MenuItem v-for="item in newItems" :key="item.key" :href="item.href" :current="item.active" @click="go">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-teal-50 text-teal-800">
                        <NavIcon :name="item.icon" class="h-5 w-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="block font-medium">{{ item.label }}</span>
                        <span class="block text-sm text-gray-700">{{ item.hint }}</span>
                    </span>
                </MenuItem>
            </PopoverMenu>
        </div>

        <nav class="flex-1 overflow-y-auto pb-4" :class="collapsed ? 'px-4' : 'px-3'" aria-label="Main" @scroll="hideTip">
            <ul class="space-y-1">
                <li v-for="item in mainItems" :key="item.key">
                    <Link
                        :href="item.href"
                        :aria-current="item.active ? 'page' : undefined"
                        :class="linkClass(item)"
                        @click="go"
                        @mouseenter="showTip($event, tipFor(item))"
                        @mouseleave="hideTip"
                        @focus="showTip($event, tipFor(item))"
                        @blur="hideTip"
                    >
                        <span v-if="collapsed && item.active" class="absolute -left-4 bottom-2 top-2 w-1 rounded-r bg-amber-400" aria-hidden="true"></span>
                        <NavIcon :name="item.icon" :class="collapsed ? 'h-6 w-6' : 'h-5 w-5'" class="shrink-0" />
                        <span :class="collapsed ? 'sr-only' : 'truncate'">{{ item.label }}</span>

                        <template v-if="item.badge">
                            <span v-if="collapsed" class="absolute right-1.5 top-1.5 h-3 w-3 rounded-full bg-amber-400 ring-2 ring-teal-900" aria-hidden="true"></span>
                            <span v-else class="ms-auto rounded-full bg-amber-400 px-2.5 py-0.5 text-sm font-semibold text-teal-950" aria-hidden="true">{{ item.badge }}</span>
                            <span class="sr-only">, {{ item.badge }} waiting</span>
                        </template>
                        <span v-else-if="item.active && !collapsed" class="ms-auto h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
                    </Link>
                </li>
            </ul>

            <!-- Admin pages: few people, rare visits, so they are folded away until wanted. -->
            <template v-if="adminItems.length">
                <div v-if="collapsed" class="mx-1 my-3 border-t border-teal-800" role="presentation"></div>
                <button
                    v-else
                    type="button"
                    class="mt-4 flex min-h-12 w-full items-center gap-3 rounded-md px-3 text-base font-medium text-teal-100 hover:bg-teal-800/60 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400"
                    :aria-expanded="adminShown"
                    aria-controls="nav-admin"
                    @click="adminGroupOpen = !adminShown"
                >
                    <NavIcon name="settings" class="h-5 w-5 shrink-0" />
                    Admin
                    <NavIcon name="chevronDown" class="ms-auto h-5 w-5 transition-transform" :class="{ 'rotate-180': adminShown }" />
                </button>

                <ul v-show="collapsed || adminShown" id="nav-admin" class="space-y-1" :class="collapsed ? '' : 'mt-1 border-s border-teal-800 ms-5 ps-2'">
                    <li v-for="item in adminItems" :key="item.key">
                        <Link
                            :href="item.href"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="linkClass(item)"
                            @click="go"
                            @mouseenter="showTip($event, item.label)"
                            @mouseleave="hideTip"
                            @focus="showTip($event, item.label)"
                            @blur="hideTip"
                        >
                            <span v-if="collapsed && item.active" class="absolute -left-4 bottom-2 top-2 w-1 rounded-r bg-amber-400" aria-hidden="true"></span>
                            <NavIcon :name="item.icon" :class="collapsed ? 'h-6 w-6' : 'h-5 w-5'" class="shrink-0" />
                            <span :class="collapsed ? 'sr-only' : 'truncate'">{{ item.label }}</span>
                            <span v-if="item.active && !collapsed" class="ms-auto h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
                        </Link>
                    </li>
                </ul>
            </template>
        </nav>

        <slot name="footer" />

        <Teleport to="body">
            <div
                v-if="tip"
                class="pointer-events-none fixed z-[70] -translate-y-1/2 whitespace-nowrap rounded-md bg-gray-900 px-3 py-2 text-sm font-medium text-white shadow-lg print:hidden"
                :style="{ top: tip.top + 'px', left: tip.left + 'px' }"
                aria-hidden="true"
            >
                {{ tip.text }}
            </div>
        </Teleport>
    </div>
</template>
