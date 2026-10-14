<script setup>
import { Link } from '@inertiajs/vue3';

/** One figure on the dashboard. Every stat card looks the same. With an href the whole card is a link. */
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    unit: { type: String, default: null },
    href: { type: String, default: null },
});
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="card block min-h-12 p-6 transition"
        :class="href ? 'hover:ring-teal-300' : ''"
    >
        <p class="text-base text-gray-700">{{ label }}</p>
        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">
            {{ value }}<span v-if="unit" class="ms-1 text-base font-normal text-gray-700">{{ unit }}</span>
        </p>
    </component>
</template>
