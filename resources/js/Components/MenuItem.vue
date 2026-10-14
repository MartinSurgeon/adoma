<script setup>
import { Link } from '@inertiajs/vue3';

/** One entry in a More menu. With an href it is a link. Without, it is a button for an action or a dialog. */
defineProps({
    href: { type: String, default: null },
    external: { type: Boolean, default: false },
    danger: { type: Boolean, default: false },
});
</script>

<template>
    <a v-if="href && external" :href="href" target="_blank" rel="noopener" role="menuitem" class="menu-item" :class="danger ? 'text-red-800' : 'text-gray-900'"><slot /></a>
    <Link v-else-if="href" :href="href" role="menuitem" class="menu-item" :class="danger ? 'text-red-800' : 'text-gray-900'"><slot /></Link>
    <button v-else type="button" role="menuitem" class="menu-item w-full text-left" :class="danger ? 'text-red-800' : 'text-gray-900'"><slot /></button>
</template>

<style scoped>
.menu-item {
    display: flex;
    min-height: 3rem;
    align-items: center;
    padding: 0 1rem;
    font-size: 1rem;
}
.menu-item:hover,
.menu-item:focus {
    background-color: rgb(249 250 251);
}
</style>
