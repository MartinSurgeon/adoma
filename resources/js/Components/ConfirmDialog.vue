<script setup>
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * A proper dialog for anything that changes or undoes something: a title that says what will happen,
 * the words of what it means, and two clear buttons. It replaces the browser's own confirm box.
 */
defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    danger: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
});

defineEmits(['confirm', 'close']);
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <form class="p-6" @submit.prevent="$emit('confirm')">
            <h2 class="text-lg font-semibold text-gray-900">{{ title }}</h2>

            <div class="mt-3 space-y-4 text-base text-gray-700">
                <slot />
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <SecondaryButton class="w-full sm:w-auto" @click="$emit('close')">Cancel</SecondaryButton>
                <component :is="danger ? DangerButton : PrimaryButton" type="submit" class="w-full sm:w-auto" :disabled="processing">
                    {{ processing ? 'Working…' : confirmLabel }}
                </component>
            </div>
        </form>
    </Modal>
</template>
