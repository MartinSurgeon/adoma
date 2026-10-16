<script setup>
import { Link } from '@inertiajs/vue3';

// Takes a Laravel paginator (as sent by Inertia) and shows "1 to 25 of 60" with Previous and Next.
defineProps({ paginator: { type: Object, required: true } });
</script>

<template>
    <nav v-if="paginator.total > 0" class="flex flex-wrap items-center justify-between gap-3 text-base text-gray-700" aria-label="Pages">
        <p>{{ paginator.from }} to {{ paginator.to }} of {{ paginator.total }}</p>

        <div v-if="paginator.last_page > 1" class="flex gap-2">
            <component
                :is="paginator.prev_page_url ? Link : 'span'"
                :href="paginator.prev_page_url"
                preserve-scroll
                class="btn btn-secondary px-4"
                :class="{ 'pointer-events-none opacity-50': !paginator.prev_page_url }"
                :aria-disabled="paginator.prev_page_url ? undefined : 'true'"
            >
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6" /></svg>
                Previous
            </component>
            <component
                :is="paginator.next_page_url ? Link : 'span'"
                :href="paginator.next_page_url"
                preserve-scroll
                class="btn btn-secondary px-4"
                :class="{ 'pointer-events-none opacity-50': !paginator.next_page_url }"
                :aria-disabled="paginator.next_page_url ? undefined : 'true'"
            >
                Next
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
            </component>
        </div>
    </nav>
</template>
