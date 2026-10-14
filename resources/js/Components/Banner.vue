<script setup>
import { computed } from 'vue';

/**
 * A message with an icon and words, never colour alone.
 * Green is success, red is a problem, amber is a warning, teal is a note.
 */
const props = defineProps({
    variant: { type: String, default: 'info' }, // success, error, warning or info
});

const looks = computed(
    () =>
        ({
            success: { box: 'border-green-300 bg-green-50 text-green-900', icon: 'M5 13l4 4L19 7', word: 'Done.' },
            error: { box: 'border-red-300 bg-red-50 text-red-900', icon: 'M6 18L18 6M6 6l12 12', word: 'Problem.' },
            warning: {
                box: 'border-amber-300 bg-amber-50 text-amber-900',
                icon: 'M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
                word: 'Check this.',
            },
            info: { box: 'border-teal-200 bg-teal-50 text-teal-900', icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', word: 'Note.' },
        })[props.variant],
);
</script>

<template>
    <div
        :role="variant === 'error' || variant === 'warning' ? 'alert' : 'status'"
        class="flex items-start gap-3 rounded-md border p-4 text-base"
        :class="looks.box"
    >
        <svg class="mt-0.5 h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path :d="looks.icon" />
        </svg>
        <div class="min-w-0">
            <span class="sr-only">{{ looks.word }}</span>
            <slot />
        </div>
    </div>
</template>
