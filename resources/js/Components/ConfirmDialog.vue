<script setup>
import { nextTick, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * A proper dialog for anything that changes or undoes something: a title that says what will happen,
 * the words of what it means, and two clear buttons. It replaces the browser's own confirm box.
 *
 * "details" is a short list of what is about to be saved (who, how much, the amount last and largest),
 * so the question is a real check and not a click people learn to ignore.
 *
 * Where the keyboard starts: in the first box when the dialog asks for something (a reason), on the safe
 * choice when the action is dangerous, and on the main button otherwise, so Enter goes ahead.
 */
const props = defineProps({
    show: { type: Boolean, default: false },
    title: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    danger: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    cancelLabel: { type: String, default: 'Cancel' },
    details: { type: Array, default: () => [] }, // [{ label, value, strong }]
});

defineEmits(['confirm', 'close']);

const body = ref(null);
const cancelButton = ref(null);
const confirmButton = ref(null);

watch(
    () => props.show,
    async (open) => {
        if (!open) return;
        await nextTick();
        await nextTick();
        const field = body.value?.querySelector('input:not([type=hidden]), textarea, select');
        const target = field ?? (props.danger ? cancelButton.value?.$el : confirmButton.value?.$el);
        target?.focus();
    },
);
</script>

<template>
    <Modal :show="show" max-width="md" @close="$emit('close')">
        <form class="p-6" @submit.prevent="$emit('confirm')">
            <h2 class="text-lg font-semibold text-gray-900">{{ title }}</h2>

            <div ref="body" class="mt-3 space-y-4 text-base text-gray-700">
                <dl v-if="details.length" class="divide-y divide-gray-200 rounded-xl bg-gray-50 px-4 ring-1 ring-inset ring-gray-200">
                    <div v-for="row in details" :key="row.label" class="flex items-baseline justify-between gap-4 py-2.5">
                        <dt class="text-base text-gray-700">{{ row.label }}</dt>
                        <dd
                            class="text-right font-medium text-gray-900"
                            :class="row.strong ? 'text-xl font-semibold tabular-nums' : 'text-base'"
                        >
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
                <slot />
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <SecondaryButton ref="cancelButton" class="w-full sm:w-auto" @click="$emit('close')">{{ cancelLabel }}</SecondaryButton>
                <component :is="danger ? DangerButton : PrimaryButton" ref="confirmButton" type="submit" class="w-full sm:w-auto" :disabled="processing">
                    {{ processing ? 'Working…' : confirmLabel }}
                </component>
            </div>
        </form>
    </Modal>
</template>
