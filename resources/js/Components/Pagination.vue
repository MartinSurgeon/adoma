<script setup>
import { Link } from '@inertiajs/vue3';

// Takes a Laravel paginator (as sent by Inertia) and shows "Showing 1 to 25 of 60" with Previous and Next.
defineProps({ paginator: { type: Object, required: true } });
</script>

<template>
    <nav v-if="paginator.total > 0" class="flex flex-wrap items-center justify-between gap-3 text-base text-gray-700" aria-label="Pages">
        <p>Showing {{ paginator.from }} to {{ paginator.to }} of {{ paginator.total }}</p>

        <div v-if="paginator.last_page > 1" class="flex gap-2">
            <component
                :is="paginator.prev_page_url ? Link : 'span'"
                :href="paginator.prev_page_url"
                preserve-scroll
                class="inline-flex min-h-12 items-center rounded-md border px-4"
                :class="paginator.prev_page_url ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-600'"
            >
                Previous
            </component>
            <component
                :is="paginator.next_page_url ? Link : 'span'"
                :href="paginator.next_page_url"
                preserve-scroll
                class="inline-flex min-h-12 items-center rounded-md border px-4"
                :class="paginator.next_page_url ? 'border-gray-300 text-gray-700 hover:bg-gray-50' : 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-600'"
            >
                Next
            </component>
        </div>
    </nav>
</template>
