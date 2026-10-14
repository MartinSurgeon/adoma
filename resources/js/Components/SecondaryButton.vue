<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-12 items-center justify-center rounded-md border border-gray-300 bg-white px-6 text-base font-medium text-gray-800 transition duration-150 ease-in-out hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
    >
        <slot />
    </button>
</template>
