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
            success: { box: 'bg-green-50 text-green-900 ring-green-600/25', chip: 'bg-green-100 text-green-800', icon: 'M5 13l4 4L19 7', word: 'Done.' },
            error: { box: 'bg-red-50 text-red-900 ring-red-600/25', chip: 'bg-red-100 text-red-800', icon: 'M6 18L18 6M6 6l12 12', word: 'Problem.' },
            warning: {
                box: 'bg-amber-50 text-amber-900 ring-amber-600/30',
                chip: 'bg-amber-100 text-amber-800',
                icon: 'M12 9v4m0 4h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z',
                word: 'Check this.',
            },
            info: { box: 'bg-teal-50 text-teal-900 ring-teal-600/20', chip: 'bg-teal-100 text-teal-800', icon: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', word: 'Note.' },
        })[props.variant],
);
</script>

<template>
    <div
        :role="variant === 'error' || variant === 'warning' ? 'alert' : 'status'"
        class="flex items-start gap-3 rounded-xl p-4 text-base shadow-sm ring-1 ring-inset"
        :class="looks.box"
    >
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="looks.chip" aria-hidden="true">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path :d="looks.icon" />
            </svg>
        </span>
        <div class="min-w-0 pt-0.5">
            <span class="sr-only">{{ looks.word }}</span>
            <slot />
        </div>
    </div>
</template>
