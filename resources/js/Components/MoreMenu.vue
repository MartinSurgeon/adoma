<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';

/**
 * The "More" menu: where the rare actions live, so a screen shows one main action and not five.
 * The list is drawn over the page (not inside a table), so it is never cut off by a scrolling table.
 * Keyboard: Enter or Space opens it, arrow keys move, Escape closes and returns to the button.
 */
const props = defineProps({
    label: { type: String, default: 'More actions' },
});

const open = ref(false);
const trigger = ref(null);
const menu = ref(null);
const position = ref({ top: 0, left: 0 });

async function show() {
    open.value = true;
    await nextTick();
    place();
    menu.value?.querySelector('[role=menuitem]')?.focus();
    document.addEventListener('click', onOutside, true);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
}

function close(returnFocus = false) {
    open.value = false;
    document.removeEventListener('click', onOutside, true);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
    if (returnFocus === true) trigger.value?.focus();
}

// Open below the button, or above it when there is no room, and keep it inside the screen.
function place() {
    const button = trigger.value.getBoundingClientRect();
    const list = menu.value.getBoundingClientRect();
    const roomBelow = window.innerHeight - button.bottom;
    const top = roomBelow >= list.height + 8 ? button.bottom + 4 : Math.max(8, button.top - list.height - 4);
    const left = Math.min(Math.max(8, button.right - list.width), window.innerWidth - list.width - 8);
    position.value = { top, left };
}

function onOutside(event) {
    if (!menu.value?.contains(event.target) && !trigger.value?.contains(event.target)) close();
}

function onKey(event) {
    const items = [...(menu.value?.querySelectorAll('[role=menuitem]') ?? [])];
    const current = items.indexOf(document.activeElement);

    if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        items[(current + 1) % items.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        items[(current - 1 + items.length) % items.length]?.focus();
    } else if (event.key === 'Tab') {
        close();
    }
}

onBeforeUnmount(() => close());
</script>

<template>
    <div class="inline-block">
        <button
            ref="trigger"
            type="button"
            class="inline-flex min-h-12 min-w-12 items-center justify-center gap-2 rounded-md border border-gray-300 bg-white px-3 text-base font-medium text-gray-800 hover:bg-gray-50"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-label="props.label"
            @click="open ? close() : show()"
        >
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" />
            </svg>
            <span class="hidden sm:inline">More</span>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                role="menu"
                class="fixed z-50 min-w-[14rem] overflow-hidden rounded-md bg-white py-1 shadow-lg ring-1 ring-gray-200"
                :style="{ top: position.top + 'px', left: position.left + 'px' }"
                @click="close()"
            >
                <slot />
            </div>
        </Teleport>
    </div>
</template>
