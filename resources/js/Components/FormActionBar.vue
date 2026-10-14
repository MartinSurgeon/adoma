<script setup>
import ButtonLink from '@/Components/ButtonLink.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

/**
 * The bar under every form: one main button and one quiet way out, always in reach.
 * It sticks to the bottom of the screen above the phone's navigation bar, the main button grows to
 * fill the width on a phone, and both are 48px tall.
 */
defineProps({
    cancelHref: { type: String, required: true },
    submitLabel: { type: String, required: true },
    processing: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    narrow: { type: Boolean, default: false },
});
</script>

<template>
    <div class="fixed inset-x-0 bottom-[57px] z-20 border-t border-gray-200 bg-white px-4 py-3 lg:bottom-0 lg:left-64">
        <div class="mx-auto flex items-center justify-end gap-3" :class="narrow ? 'max-w-3xl' : 'max-w-5xl'">
            <ButtonLink :href="cancelHref" variant="secondary" class="shrink-0">Cancel</ButtonLink>
            <PrimaryButton class="flex-1 sm:min-w-[12rem] sm:flex-none" :disabled="processing || disabled">
                {{ processing ? 'Saving…' : submitLabel }}
            </PrimaryButton>
        </div>
    </div>
</template>
