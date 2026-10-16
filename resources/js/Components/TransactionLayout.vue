<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import FormActionBar from '@/Components/FormActionBar.vue';
import { confirm } from '@/confirm';
import { useLeaveGuard } from '@/leaveGuard';

/**
 * The one layout every transaction form uses, so purchases, sales, receipts, payments and transfers feel the same:
 * numbered groups on the left, a summary beside them, and the sticky bar with one main button.
 *
 * It also looks after three things people would otherwise lose work or money to:
 *  - a check before saving: "Save this purchase?" with who, how much and the amount, so a typing slip is caught
 *    before it is in the books (the client's FileMaker asked the same "are you sure?"),
 *  - a "leave without saving?" question when typed-in data would be thrown away, and
 *  - a short list of what needs fixing, at the top, after a save that was refused.
 */
const props = defineProps({
    title: { type: String, required: true },
    submitLabel: { type: String, required: true },
    processing: { type: Boolean, default: false },
    blocked: { type: Boolean, default: false },
    needsOffice: { type: Boolean, default: false },
    noun: { type: String, default: 'transaction' },
    cancelHref: { type: String, default: '/dashboard' },
    dirty: { type: Boolean, default: false }, // something has been typed that is not saved yet
    errors: { type: Object, default: () => ({}) }, // the form's errors, by field
    // What the check before saving shows: { title, details: [{ label, value, strong }], body }. Leave empty to save at once.
    review: { type: Object, default: null },
});

const emit = defineEmits(['submit']);

useLeaveGuard({ dirty: () => props.dirty, busy: () => props.processing, noun: props.noun });

// ---- The check before saving
async function submit() {
    if (props.processing || props.blocked) return;

    if (props.review) {
        const yes = await confirm({
            title: props.review.title,
            details: props.review.details ?? [],
            body: props.review.body ?? '',
            confirmLabel: props.submitLabel,
            cancelLabel: 'Go back and change',
        });
        if (!yes) return;
    }

    emit('submit');
}

// ---- A short list of what needs fixing
const problems = computed(() => [...new Set(Object.values(props.errors ?? {}).filter(Boolean))]);
const summary = ref(null);

watch(
    () => props.processing,
    async (now, before) => {
        // A save has just ended and left errors behind: bring them into view.
        if (before && !now && problems.value.length) {
            await nextTick();
            summary.value?.scrollIntoView?.({ block: 'center', behavior: 'smooth' });
        }
    },
);
</script>

<template>
    <Head :title="title" />

    <AuthenticatedLayout :title="title" width="5xl">
        <template v-if="$slots.subtitle" #subtitle><slot name="subtitle" /></template>

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="needsOffice" variant="warning">
                <p class="font-semibold">Choose a branch first</p>
                <p class="mt-1">
                    You are looking at all branches. A {{ noun }} belongs to one branch, so pick it from the branch menu at the top of the page, then come back.
                </p>
            </Banner>

            <slot v-else-if="$slots.instead" name="instead" />

            <!-- minmax(0, …) lets a wide table scroll inside its own box instead of pushing the page sideways. -->
            <form v-else class="grid grid-cols-1 gap-6 pb-28 lg:grid-cols-[minmax(0,1fr)_20rem]" @submit.prevent="submit">
                <div v-if="problems.length" ref="summary" class="lg:col-span-2">
                    <Banner variant="error">
                        <p class="font-semibold">
                            {{ problems.length === 1 ? 'One thing needs fixing before this can be saved' : `${problems.length} things need fixing before this can be saved` }}
                        </p>
                        <ul class="mt-1 list-disc ps-5">
                            <li v-for="message in problems" :key="message">{{ message }}</li>
                        </ul>
                    </Banner>
                </div>

                <div class="min-w-0 space-y-6">
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
