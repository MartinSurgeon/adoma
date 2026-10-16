<script setup>
import { nextTick, onBeforeUnmount, ref } from 'vue';

/**
 * A button that opens a short list of choices: the "New" menu, the account menu, every "More" menu.
 *
 * The list is drawn over the page (not inside the thing that holds the button), so a scrolling table or the
 * sidebar never cuts it off. It opens where there is room: below, above, or beside the button.
 * Keyboard: Enter or Space opens it and moves to the first choice, the arrow keys, Home and End move,
 * Escape closes it and goes back to the button, and Tab closes it.
 */
const props = defineProps({
    // The button's name for screen readers when it shows only an icon. Leave empty when it shows words.
    label: { type: String, default: null },
    // bottom-start, bottom-end, right-start, or top (above, centred): where to try first.
    placement: { type: String, default: 'bottom-end' },
    buttonClass: { type: [String, Array, Object], default: '' },
    menuClass: { type: [String, Array, Object], default: 'min-w-[14rem]' },
    block: { type: Boolean, default: false }, // fill the width it is given, as in the phone's bottom bar
});

const emit = defineEmits(['open', 'close']);

const id = `menu-${Math.random().toString(36).slice(2, 10)}`;

const open = ref(false);
const trigger = ref(null);
const menu = ref(null);
const position = ref({ top: 0, left: 0 });

async function show() {
    open.value = true;
    emit('open');
    await nextTick();
    place();
    menu.value?.querySelector('[role=menuitem]')?.focus();
    document.addEventListener('click', onOutside, true);
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', onScroll, true);
}

function close(returnFocus = false) {
    if (!open.value) return;
    open.value = false;
    emit('close');
    document.removeEventListener('click', onOutside, true);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', onScroll, true);
    if (returnFocus === true) trigger.value?.focus();
}

// Scrolling the page moves the button away from the list, so the list closes. Scrolling the list itself does not.
function onScroll(event) {
    if (!menu.value?.contains(event.target)) close();
}

function place() {
    const gap = 6;
    const edge = 8;
    const button = trigger.value.getBoundingClientRect();
    const list = menu.value.getBoundingClientRect();
    const fitsBelow = window.innerHeight - button.bottom >= list.height + gap + edge;
    const clampLeft = (left) => Math.min(Math.max(edge, left), window.innerWidth - list.width - edge);
    const clampTop = (top) => Math.min(Math.max(edge, top), window.innerHeight - list.height - edge);

    let top;
    let left;

    if (props.placement === 'right-start') {
        left = button.right + gap;
        top = clampTop(button.top);
        // No room on the right (a narrow window): fall back to below the button.
        if (left + list.width > window.innerWidth - edge) {
            left = clampLeft(button.left);
            top = fitsBelow ? button.bottom + gap : clampTop(button.top - list.height - gap);
        }
    } else if (props.placement === 'top') {
        left = clampLeft(button.left + button.width / 2 - list.width / 2);
        top = clampTop(button.top - list.height - gap);
    } else {
        left = clampLeft(props.placement === 'bottom-start' ? button.left : button.right - list.width);
        top = fitsBelow ? button.bottom + gap : clampTop(button.top - list.height - gap);
    }

    position.value = { top, left };
}

function onOutside(event) {
    if (!menu.value?.contains(event.target) && !trigger.value?.contains(event.target)) close();
}

function onKey(event) {
    const items = [...(menu.value?.querySelectorAll('[role=menuitem]') ?? [])];
    const current = items.indexOf(document.activeElement);
    const go = (index) => {
        event.preventDefault();
        items[(index + items.length) % items.length]?.focus();
    };

    if (event.key === 'Escape') {
        event.preventDefault();
        close(true);
    } else if (event.key === 'ArrowDown') {
        go(current + 1);
    } else if (event.key === 'ArrowUp') {
        go(current - 1);
    } else if (event.key === 'Home') {
        go(0);
    } else if (event.key === 'End') {
        go(items.length - 1);
    } else if (event.key === 'Tab') {
        close();
    }
}

onBeforeUnmount(() => close());

defineExpose({ close });
</script>

<template>
    <div :class="block ? 'block w-full' : 'inline-block'">
        <button
            ref="trigger"
            type="button"
            :class="buttonClass"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-controls="open ? id : undefined"
            :aria-label="label || undefined"
            @click="open ? close() : show()"
        >
            <slot name="button" :open="open" />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                :id="id"
                ref="menu"
                role="menu"
                class="fixed z-[60] max-h-[calc(100vh-1rem)] overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-200"
                :class="menuClass"
                :style="{ top: position.top + 'px', left: position.left + 'px' }"
                @click="close()"
            >
                <slot />
            </div>
        </Teleport>
    </div>
</template>
