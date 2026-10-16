<script setup>
/**
 * Two or three choices side by side, one always chosen (radio buttons that look like joined buttons).
 * Put it inside a <fieldset> whose <legend> says what is being chosen.
 *
 * On a phone it fills the width and long words wrap inside their button, never past its edge.
 * The chosen one is filled; the keyboard focus gets a ring, even though the round radio itself is hidden.
 */
defineProps({
    options: { type: Array, required: true }, // [{ value, label }]
    name: { type: String, required: true },
});

const model = defineModel();
</script>

<template>
    <div class="mt-1 grid overflow-hidden rounded-md border border-gray-300 sm:inline-flex" :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }">
        <label
            v-for="(option, index) in options"
            :key="String(option.value)"
            class="flex min-h-12 cursor-pointer items-center justify-center px-4 py-2 text-center text-base font-medium leading-snug has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-inset has-[:focus-visible]:ring-amber-400 sm:px-6"
            :class="[
                model === option.value ? 'bg-teal-700 text-white' : 'bg-white text-gray-800 hover:bg-gray-50',
                index > 0 ? 'border-s border-gray-300' : '',
            ]"
        >
            <input v-model="model" type="radio" :name="name" :value="option.value" class="sr-only" />
            {{ option.label }}
        </label>
    </div>
</template>
