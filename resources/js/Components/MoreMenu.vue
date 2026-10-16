<script setup>
import PopoverMenu from '@/Components/PopoverMenu.vue';

/**
 * The "More" menu: where the rare actions live, so a screen shows one main action and not five.
 * It is a PopoverMenu with the same button everywhere: three dots, and the word "More" where there is room.
 */
defineProps({
    label: { type: String, default: 'More actions' },
});
</script>

<template>
    <PopoverMenu
        :label="label"
        placement="bottom-end"
        button-class="btn btn-secondary btn-square sm:px-4"
    >
        <template #button>
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <circle cx="5" cy="12" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="19" cy="12" r="1.8" />
            </svg>
            <span class="hidden sm:inline">More</span>
        </template>
        <slot />
    </PopoverMenu>
</template>
