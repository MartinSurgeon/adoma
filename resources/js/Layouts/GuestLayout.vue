<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-gray-50 px-4 pt-10 sm:justify-center sm:pt-0">
        <Link href="/" class="text-3xl font-semibold tracking-tight text-teal-900">
            Adoma<span class="text-amber-500">.</span>
        </Link>

        <div class="mt-6 w-full overflow-hidden rounded-lg bg-white px-6 py-6 shadow-md sm:max-w-md">
            <slot />
        </div>
    </div>
</template>
