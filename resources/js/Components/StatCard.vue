<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import GoldBarsArt from '@/Components/GoldBarsArt.vue';
import NavIcon from '@/Components/NavIcon.vue';

/**
 * One figure on the dashboard or a report. With an href the whole card is a link.
 *
 * "featured" is the one figure that matters most on a page (the gold in stock): a dark teal card with the gold bars,
 * like the sign-in screen. Anything in the default slot is a short line under the figure, such as what it is made of.
 * With an icon, the icon sits on top and the label gets a line of its own, so a long label never meets the arrow.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    unit: { type: String, default: null },
    href: { type: String, default: null },
    featured: { type: Boolean, default: false },
    icon: { type: String, default: null }, // a NavIcon name
});

// An amount comes as "GH₵ 1,511,334.00". The currency is shown smaller in front (or above, in a narrow card),
// and the number itself never breaks over two lines.
const parts = computed(() => {
    const text = String(props.value);
    const match = text.match(/^(GH₵)\s?(.*)$/);
    return match ? { currency: match[1], number: match[2] } : { currency: null, number: text };
});

const withIcon = computed(() => Boolean(props.icon) && !props.featured);
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        class="@container group relative block h-full p-6"
        :class="[featured ? 'card-feature' : 'card', href ? 'card-link' : '']"
    >
        <!-- The featured card's texture and gold, behind the words, and only where there is room for them. -->
        <template v-if="featured">
            <div class="auth-dots pointer-events-none absolute inset-0" aria-hidden="true"></div>
            <GoldBarsArt class="pointer-events-none absolute -bottom-6 -right-4 hidden h-auto w-44 @[26rem]:block @[40rem]:w-52" aria-hidden="true" />
        </template>

        <div class="relative flex items-start justify-between gap-3">
            <span v-if="withIcon" class="icon-chip" aria-hidden="true"><NavIcon :name="icon" class="h-5 w-5" /></span>
            <p v-else class="inline-flex items-center gap-1 text-base font-medium" :class="featured ? 'text-teal-100' : 'text-gray-700'">
                {{ label }}
                <NavIcon
                    v-if="href && featured"
                    name="chevronRight"
                    class="h-5 w-5 text-amber-300 transition-transform motion-safe:group-hover:translate-x-0.5"
                    stroke-width="2"
                />
            </p>
            <span
                v-if="href && !featured"
                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-gray-500 transition group-hover:bg-teal-50 group-hover:text-teal-700"
                aria-hidden="true"
            >
                <NavIcon name="chevronRight" class="h-5 w-5 transition-transform motion-safe:group-hover:translate-x-0.5" stroke-width="2" />
            </span>
        </div>

        <p v-if="withIcon" class="relative mt-3 text-base font-medium text-gray-700">{{ label }}</p>

        <!-- The size follows the card's own width: smaller in a narrow card, so a long amount still fits. -->
        <p
            class="relative font-semibold tabular-nums tracking-tight"
            :class="[withIcon ? 'mt-1' : 'mt-3', featured ? 'text-3xl text-white @[18rem]:text-4xl' : 'text-2xl text-gray-900 @[18rem]:text-3xl']"
        >
            <span v-if="parts.currency" class="me-1 text-base font-medium" :class="featured ? 'text-teal-100' : 'text-gray-700'">{{ parts.currency }}</span>
            <span class="whitespace-nowrap">{{ parts.number }}<span v-if="unit" class="ms-1 text-base font-medium" :class="featured ? 'text-amber-300' : 'text-gray-700'">{{ unit }}</span></span>
        </p>

        <div v-if="$slots.default" class="relative mt-2 text-base" :class="featured ? 'text-teal-100' : 'text-gray-700'">
            <slot />
        </div>
    </component>
</template>
