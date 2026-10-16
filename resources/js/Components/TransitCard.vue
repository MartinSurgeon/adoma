<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import NavIcon from '@/Components/NavIcon.vue';

/**
 * Gold on the road between two branches: sent, and not yet accepted. Where it is going, how much and of what kind,
 * what the receiving branch will owe for it, and who sent it when. Opens the transfer, or the page that accepts it.
 */
const props = defineProps({
    transfer: { type: Object, required: true }, // from TransitList on the server
});

// From the viewer's side: gold coming to their branch, or gold their branch sent. An owner sees it in transit.
const state = computed(() => ({ in: 'Arriving', out: 'Sent' })[props.transfer.direction] ?? 'In transit');

const sentAt = computed(() => {
    if (!props.transfer.sent_at) return '';
    const at = new Date(props.transfer.sent_at);
    return `${at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}, ${at.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
});
</script>

<template>
    <component :is="transfer.href ? Link : 'div'" :href="transfer.href ?? undefined" class="@container card flex h-full flex-col p-6" :class="{ 'card-link': transfer.href }">
        <div class="flex items-center justify-between gap-3">
            <span class="ref text-gray-700">{{ transfer.number }}</span>
            <span class="badge badge-teal"><NavIcon name="truck" class="h-4 w-4" />{{ state }}</span>
        </div>

        <p class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-lg font-semibold text-gray-900">
            <span>{{ transfer.from }}</span>
            <NavIcon name="arrowRight" class="h-5 w-5 text-gray-500" />
            <span class="sr-only">to</span>
            <span>{{ transfer.to }}</span>
        </p>

        <!-- Four across only when the card is wide enough for an amount like GH₵ 1,612,000.00; two across otherwise. -->
        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 @[38rem]:grid-cols-4">
            <div>
                <dt class="text-sm text-gray-700">Weight</dt>
                <dd class="mt-0.5 text-base font-semibold tabular-nums text-gray-900">{{ transfer.weight }} g</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-700">Form</dt>
                <dd class="mt-0.5 text-base font-medium text-gray-900">{{ transfer.form ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-700">Lots</dt>
                <dd class="mt-0.5 text-base font-medium tabular-nums text-gray-900">{{ transfer.lots }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-700">Sale value</dt>
                <dd class="mt-0.5 whitespace-nowrap text-base font-medium tabular-nums text-gray-900">{{ transfer.value }}</dd>
            </div>
        </dl>

        <p class="mt-auto pt-4">
            <span class="block border-t border-gray-100 pt-3 text-sm text-gray-700">
                Sent<template v-if="transfer.sent_by"> by {{ transfer.sent_by }}</template> · {{ sentAt }}
            </span>
        </p>
    </component>
</template>
