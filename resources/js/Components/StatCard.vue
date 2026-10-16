<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/** One figure on the dashboard or a report. With an href the whole card is an accessible link. */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    unit: { type: String, default: null },
    href: { type: String, default: null },
    featured: { type: Boolean, default: false },
});

// An amount comes as "GH₵ 1,511,334.00". The currency is shown smaller in front (or above, in a narrow card),
// and the number itself never breaks over two lines.
const parts = computed(() => {
    const text = String(props.value);
    const match = text.match(/^(GH₵)\s?(.*)$/);
    return match ? { currency: match[1], number: match[2] } : { currency: null, number: text };
});
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="@container card group relative block h-full min-h-12 p-6 transition duration-150 ease-in-out"
        :class="[
            featured
                ? 'ring-1 ring-amber-300 bg-gradient-to-br from-amber-50/60 via-white to-white shadow-sm'
                : 'hover:ring-teal-300 hover:shadow-sm',
            href ? 'cursor-pointer' : '',
        ]"
    >
        <div class="flex items-start justify-between gap-2">
            <p class="text-base font-medium text-gray-700">{{ label }}</p>
            <span
                v-if="featured"
                class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-800"
                aria-hidden="true"
                title="Gold stock"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" />
                </svg>
            </span>
            <svg
                v-else-if="href"
                class="mt-1 h-4 w-4 shrink-0 text-gray-500 transition-transform group-hover:translate-x-0.5 group-hover:text-teal-700"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
            </svg>
        </div>

        <!-- The size follows the card's own width: smaller in a narrow card, so a long amount still fits. -->
        <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-900 @[18rem]:text-3xl">
            <span v-if="parts.currency" class="me-1 text-base font-medium text-gray-700">{{ parts.currency }}</span> <span class="whitespace-nowrap">{{ parts.number }}<span v-if="unit" class="ms-1 text-base font-normal text-gray-700">{{ unit }}</span></span>
        </p>
    </component>
</template>
