<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * One entry in a menu. With an href it is a link (method "post" makes it a button that posts, as for signing out).
 * Without an href it is a button for an action or a dialog.
 */
defineProps({
    href: { type: String, default: null },
    method: { type: String, default: 'get' },
    external: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
    current: { type: Boolean, default: false }, // the page this entry leads to is the one open now
});
</script>

<template>
    <a v-if="href && external" :href="href" target="_blank" rel="noopener" role="menuitem" class="menu-item" :class="danger ? 'text-red-800' : 'text-gray-900'"><slot /></a>
    <Link
        v-else-if="href && method !== 'get'"
        :href="href"
        :method="method"
        as="button"
        type="button"
        role="menuitem"
        class="menu-item w-full text-left"
        :class="danger ? 'text-red-800' : 'text-gray-900'"
    ><slot /></Link>
    <Link
        v-else-if="href"
        :href="href"
        role="menuitem"
        class="menu-item"
        :class="[danger ? 'text-red-800' : 'text-gray-900', { 'is-current': current }]"
        :aria-current="current ? 'page' : undefined"
    ><slot /></Link>
    <button v-else type="button" role="menuitem" class="menu-item w-full text-left" :class="danger ? 'text-red-800' : 'text-gray-900'"><slot /></button>
</template>

<style scoped>
.menu-item {
    display: flex;
    min-height: 3rem;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem; /* the menu has a little padding, so each entry is a rounded row inside it */
    font-size: 1rem;
}
.menu-item:focus {
    outline: none;
}
/* Shaded under the pointer, and outlined for the keyboard. Opening a menu with a click or a tap does not shade
   the first entry, so it never looks already chosen. */
.menu-item:hover,
.menu-item:focus-visible {
    background-color: rgb(240 253 250); /* teal-50 */
}
.menu-item:focus-visible {
    box-shadow: inset 0 0 0 2px rgb(13 148 136); /* teal-600 */
}
.menu-item.is-current {
    background-color: rgb(240 253 250);
    font-weight: 600;
}
</style>
