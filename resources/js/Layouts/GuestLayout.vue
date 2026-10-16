<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-gray-50 px-4 pt-10 sm:justify-center sm:pt-0">
        <Link href="/" class="inline-flex min-h-12 items-center rounded-md text-3xl font-semibold tracking-tight text-teal-900">
            Adoma<span class="text-amber-700" aria-hidden="true">.</span>
        </Link>

        <main class="mt-6 w-full rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200 sm:max-w-md sm:p-8">
            <slot />
        </main>
    </div>
</template>
