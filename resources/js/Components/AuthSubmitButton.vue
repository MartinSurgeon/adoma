<script setup>
/**
 * The one button on the sign-in screens: full width, with a spinner and different words while it works,
 * and an arrow that says "this takes you in".
 */
defineProps({
    processing: { type: Boolean, default: false },
    busyLabel: { type: String, default: 'Working…' },
});
</script>

<template>
    <button
        type="submit"
        class="group flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-gradient-to-b from-teal-700 to-teal-800 px-6 text-base font-semibold text-white shadow-lg shadow-teal-900/20 ring-1 ring-inset ring-white/10 transition hover:from-teal-800 hover:to-teal-900 active:to-teal-950 disabled:cursor-wait disabled:opacity-80"
        :disabled="processing"
        :aria-busy="processing"
    >
        <svg v-if="processing" class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        <span>{{ processing ? busyLabel : '' }}<slot v-if="!processing" /></span>
        <svg
            v-if="!processing"
            class="h-5 w-5 transition-transform motion-safe:group-hover:translate-x-0.5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M5 12h14m-6-6l6 6-6 6" />
        </svg>
    </button>
</template>
