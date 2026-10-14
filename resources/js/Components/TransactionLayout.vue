<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import FormActionBar from '@/Components/FormActionBar.vue';

/**
 * The one layout every transaction form uses, so purchases, sales, receipts and payments feel the same:
 * numbered groups on the left, a summary beside them, and the sticky bar with one main button.
 */
defineProps({
    title: { type: String, required: true },
    submitLabel: { type: String, required: true },
    processing: { type: Boolean, default: false },
    blocked: { type: Boolean, default: false },
    needsOffice: { type: Boolean, default: false },
    noun: { type: String, default: 'transaction' },
    cancelHref: { type: String, default: '/dashboard' },
});

defineEmits(['submit']);
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold leading-tight text-gray-900">{{ title }}</h1>
            <p v-if="$slots.subtitle" class="mt-1 text-base text-gray-700"><slot name="subtitle" /></p>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="needsOffice" variant="warning">
                <p class="font-semibold">Choose an office first</p>
                <p class="mt-1">
                    You are looking at all offices. A {{ noun }} belongs to one office, so pick it from the office menu at the top of the page, then come back.
                </p>
            </Banner>

            <slot v-else-if="$slots.instead" name="instead" />

            <form v-else class="grid gap-6 pb-28 lg:grid-cols-[1fr_20rem]" @submit.prevent="$emit('submit')">
                <div class="space-y-6">
                    <slot />
                </div>

                <aside class="lg:sticky lg:top-24 lg:self-start" aria-live="polite">
                    <slot name="summary" />
                </aside>

                <FormActionBar :cancel-href="cancelHref" :submit-label="submitLabel" :processing="processing" :disabled="blocked" />
            </form>
        </div>
    </AuthenticatedLayout>
</template>
