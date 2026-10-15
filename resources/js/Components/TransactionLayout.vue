<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import FormActionBar from '@/Components/FormActionBar.vue';

/**
 * The one layout every transaction form uses, so purchases, sales, receipts and payments feel the same:
 * numbered groups on the left, a summary beside them, and the sticky bar with one main button.
 *
 * It also looks after two things people would otherwise lose work to:
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
});

defineEmits(['submit']);

// ---- Leaving with unsaved data
const leaving = ref(null); // the address the person was heading to
let allowLeave = false;
let removeListener = null;

function warnBeforeUnload(event) {
    if (props.dirty && !props.processing) {
        event.preventDefault();
        event.returnValue = '';
    }
}

onMounted(() => {
    window.addEventListener('beforeunload', warnBeforeUnload);
    removeListener = router.on('before', (event) => {
        const visit = event.detail.visit;

        // Saving is a POST. Only moving to another page by link is a "leave".
        if (allowLeave || !props.dirty || props.processing || visit.method !== 'get') return;

        leaving.value = visit.url.href;
        return false; // hold the move until the person decides
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', warnBeforeUnload);
    removeListener?.();
});

function leave() {
    const target = leaving.value;
    allowLeave = true;
    leaving.value = null;
    router.visit(target);
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

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-2xl font-semibold leading-tight text-gray-900">{{ title }}</h1>
            <p v-if="$slots.subtitle" class="mt-1 text-base text-gray-700"><slot name="subtitle" /></p>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="needsOffice" variant="warning">
                <p class="font-semibold">Choose a branch first</p>
                <p class="mt-1">
                    You are looking at all branches. A {{ noun }} belongs to one branch, so pick it from the branch menu at the top of the page, then come back.
                </p>
            </Banner>

            <slot v-else-if="$slots.instead" name="instead" />

            <form v-else class="grid gap-6 pb-28 lg:grid-cols-[1fr_20rem]" @submit.prevent="$emit('submit')">
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

                <div class="space-y-6">
                    <slot />
                </div>

                <aside class="lg:sticky lg:top-24 lg:self-start" aria-live="polite">
                    <slot name="summary" />
                </aside>

                <FormActionBar :cancel-href="cancelHref" :submit-label="submitLabel" :processing="processing" :disabled="blocked" />
            </form>
        </div>

        <ConfirmDialog
            :show="leaving !== null"
            title="Leave without saving?"
            confirm-label="Leave and lose it"
            cancel-label="Keep editing"
            danger
            @close="leaving = null"
            @confirm="leave"
        >
            <p>You have typed in details for this {{ noun }} that are not saved. If you leave now, they will be lost.</p>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
