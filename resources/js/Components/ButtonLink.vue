<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * A link that looks like a button, so a button is never wrapped inside a link.
 * Primary is the one main action on a screen. Secondary is the quieter one. Both share the button styles in app.css.
 */
const props = defineProps({
    href: { type: String, required: true },
    variant: { type: String, default: 'primary' }, // primary or secondary
    external: { type: Boolean, default: false },   // opens in a new tab
});

const classes = computed(() => ['btn', props.variant === 'primary' ? 'btn-primary' : 'btn-secondary']);
</script>

<template>
    <a v-if="external" :href="href" target="_blank" rel="noopener" :class="classes"><slot /></a>
    <Link v-else :href="href" :class="classes"><slot /></Link>
</template>
