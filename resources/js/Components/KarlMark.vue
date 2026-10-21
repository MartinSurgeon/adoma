<script setup>
/**
 * Karl, the app's mascot: a bar of gold with a jeweller's loupe over one eye, who checks every gram.
 * Drawn here rather than loaded as a picture, so it is sharp at any size and needs no internet.
 * The same drawing is in docs/brand/karl-mark.svg and public/favicon.svg.
 *
 * Decorative by default (the word "Karl" sits beside it); give a title when it stands alone.
 */
defineProps({
    title: { type: String, default: null },
});
</script>

<template>
    <svg viewBox="4.5 6 55 55" :role="title ? 'img' : undefined" :aria-hidden="title ? undefined : 'true'" focusable="false">
        <title v-if="title">{{ title }}</title>
        <g stroke="#6b4206" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round">
            <path d="M17 13.5 H47 L54.5 26 H9.5 Z" fill="#fbdc86" />
            <path d="M9.5 26 H54.5 L58.5 53.5 H5.5 Z" fill="#f2b632" />
        </g>
        <path d="M20 17.5 H30" stroke="#fff6d6" stroke-width="2.4" stroke-linecap="round" />
        <circle cx="22" cy="38" r="3.1" fill="#123c37" />
        <circle cx="23.1" cy="36.9" r="1" fill="#ffffff" />
        <path d="M48 34.5 L57 31.5" stroke="#123c37" stroke-width="2.2" stroke-linecap="round" />
        <circle cx="41" cy="37" r="8" fill="#e8f7f3" stroke="#123c37" stroke-width="3.2" />
        <circle cx="41" cy="37.6" r="3.9" fill="#123c37" />
        <circle cx="42.4" cy="36.2" r="1.25" fill="#ffffff" />
        <path d="M36.2 32.6 A6 6 0 0 1 40 30.8" stroke="#ffffff" stroke-width="1.6" stroke-linecap="round" fill="none" opacity="0.9" />
        <path d="M24.5 46.6 Q30 50.8 35.5 46.6" stroke="#123c37" stroke-width="2.4" stroke-linecap="round" fill="none" />
        <ellipse cx="17" cy="45" rx="2.6" ry="1.6" fill="#e08a1e" opacity="0.55" />
    </svg>
</template>
