<script setup>
import NavIcon from '@/Components/NavIcon.vue';

/**
 * What kind of transaction a row is: its icon and name, and a word when it is not simply saved
 * ("Reversed", "Reversal", "Waiting" for a transfer the other branch has not accepted yet).
 */
defineProps({
    row: { type: Object, required: true }, // { type, type_label, status, is_reversal, waiting }
});

const icons = {
    purchase: 'purchase',
    sale: 'sale',
    receipt: 'receipt',
    payment: 'payment',
    transfer: 'transfer',
    transfer_in: 'transfer',
    journal: 'transactions',
};
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
        <span class="inline-flex items-center gap-2 whitespace-nowrap">
            <NavIcon :name="icons[row.type] ?? 'transactions'" class="h-5 w-5 shrink-0 text-gray-600" />
            {{ row.type_label }}
        </span>
        <span v-if="row.status === 'reversed'" class="badge badge-gray">Reversed</span>
        <span v-else-if="row.is_reversal" class="badge badge-amber">Reversal</span>
        <span v-else-if="row.waiting" class="badge badge-teal">Waiting</span>
    </span>
</template>
