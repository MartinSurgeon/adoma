<script setup>
import { computed } from 'vue';

/**
 * One group of three to six related fields, with a heading that says what the group is for.
 * A heading that starts with a step number ("1. Who") shows the number in a round badge, so the steps read at a glance.
 */
const props = defineProps({
    title: { type: String, required: true },
    help: { type: String, default: null },
});

const step = computed(() => {
    const match = props.title.match(/^(\d+)\.\s+(.*)$/);
    return match ? { number: match[1], text: match[2] } : null;
});
</script>

<template>
    <section class="card p-6">
        <h2 class="flex items-center gap-3 text-lg font-semibold text-gray-900">
            <span
                v-if="step"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-b from-teal-700 to-teal-800 text-base font-semibold text-white shadow-sm shadow-teal-900/20 ring-1 ring-inset ring-white/10"
            >
                {{ step.number }}<span class="sr-only">.</span>
            </span>
            {{ step ? step.text : title }}
        </h2>
        <p v-if="help" class="mt-1 text-base text-gray-700">{{ help }}</p>
        <div class="mt-5">
            <slot />
        </div>
    </section>
</template>
