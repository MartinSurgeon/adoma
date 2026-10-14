<script setup>
/** One group of three to six related fields, with a heading that says what the group is for. */
defineProps({
    title: { type: String, required: true },
    help: { type: String, default: null },
});
</script>

<template>
    <section class="card p-6">
        <h2 class="text-lg font-semibold text-gray-900">{{ title }}</h2>
        <p v-if="help" class="mt-1 text-base text-gray-700">{{ help }}</p>
        <div class="mt-4">
            <slot />
        </div>
    </section>
</template>
