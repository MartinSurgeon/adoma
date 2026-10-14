<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * A link that looks like a button, so a button is never wrapped inside a link.
 * Primary is the one main action on a screen. Secondary is the quieter one.
 */
const props = defineProps({
    href: { type: String, required: true },
    variant: { type: String, default: 'primary' }, // primary or secondary
    external: { type: Boolean, default: false },   // opens in a new tab, for printing
});

const classes = computed(() => [
    'inline-flex min-h-12 items-center justify-center rounded-md px-6 text-base transition duration-150 ease-in-out',
    props.variant === 'primary'
        ? 'border border-transparent bg-teal-700 font-semibold text-white hover:bg-teal-800 active:bg-teal-900'
        : 'border border-gray-300 bg-white font-medium text-gray-800 hover:bg-gray-50',
]);
</script>

<template>
    <a v-if="external" :href="href" target="_blank" rel="noopener" :class="classes"><slot /></a>
    <Link v-else :href="href" :class="classes"><slot /></Link>
</template>
