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
    <!-- A tray with the chosen option raised in it, shaded like the main buttons. -->
    <div
        class="mt-1 grid gap-1 rounded-xl bg-gray-100 p-1 ring-1 ring-inset ring-gray-200 sm:inline-flex"
        :style="{ gridTemplateColumns: `repeat(${options.length}, minmax(0, 1fr))` }"
    >
        <label
            v-for="option in options"
            :key="String(option.value)"
            class="flex min-h-12 cursor-pointer items-center justify-center rounded-lg px-2 py-2 text-center text-base font-medium leading-snug transition has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-teal-600 sm:px-6"
            :class="
                model === option.value
                    ? 'bg-gradient-to-b from-teal-700 to-teal-800 font-semibold text-white shadow-md shadow-teal-900/20 ring-1 ring-inset ring-white/10'
                    : 'text-gray-800 hover:bg-white hover:shadow-sm'
            "
        >
            <input v-model="model" type="radio" :name="name" :value="option.value" class="sr-only" />
            {{ option.label }}
        </label>
    </div>
</template>
