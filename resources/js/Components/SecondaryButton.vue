<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <!-- The quieter choice beside the main one (see .btn-secondary in app.css). -->
    <button :type="type" class="btn btn-secondary">
        <slot />
    </button>
</template>
