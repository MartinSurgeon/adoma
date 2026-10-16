<script setup>
import { Link } from '@inertiajs/vue3';
import ConfirmHost from '@/Components/ConfirmHost.vue';
import GoldBarsArt from '@/Components/GoldBarsArt.vue';

/**
 * The frame for signing in and choosing a password.
 *
 * Wide screens: the brand and a drawing of gold on the left, the form on the right.
 * Phones: the brand in a band at the top, and the form in a card that rises over it, so the form is in view at once.
 * The drawing is part of the app's own code, so the page looks the same with no internet.
 */
const year = new Date().getFullYear();

const points = [
    'Gold valued with your own formulas, lot by lot',
    'Each branch\'s stock and books kept apart',
    'Every change recorded, with who made it',
];
</script>

<template>
    <div class="flex min-h-screen flex-col bg-gray-50 lg:flex-row lg:bg-white">
        <!-- Wide screens: the brand side. -->
        <!-- Exactly the height of the window: the drawing takes whatever room the words leave, so nothing is cut off. -->
        <aside
            class="relative hidden overflow-hidden bg-gradient-to-br from-teal-900 via-teal-950 to-emerald-950 text-white lg:sticky lg:top-0 lg:flex lg:h-screen lg:w-[52%] lg:flex-col lg:p-10 xl:w-[55%] xl:p-14"
            aria-label="About Adoma"
        >
            <div class="auth-dots pointer-events-none absolute inset-0" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -right-40 -top-40 h-[28rem] w-[28rem] rounded-full bg-amber-400/10 blur-3xl" aria-hidden="true"></div>
            <div class="pointer-events-none absolute -bottom-48 -left-24 h-[26rem] w-[26rem] rounded-full bg-teal-400/10 blur-3xl" aria-hidden="true"></div>

            <Link href="/" class="relative inline-flex min-h-12 shrink-0 items-center gap-3 self-start rounded-lg focus-visible:outline-amber-400">
                <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-amber-200 via-amber-400 to-amber-600 text-xl font-bold text-teal-950 shadow-lg shadow-black/20" aria-hidden="true">A</span>
                <span class="text-2xl font-semibold tracking-tight">Adoma<span class="text-amber-400" aria-hidden="true">.</span></span>
            </Link>

            <div class="relative mx-auto min-h-0 w-full max-w-[34rem] flex-1 py-6">
                <GoldBarsArt class="h-full w-full drop-shadow-2xl" />
            </div>

            <div class="relative max-w-xl shrink-0">
                <p class="text-balance text-4xl font-semibold leading-tight tracking-tight xl:text-[2.75rem]">
                    Every gram and every cedi,
                    <span class="bg-gradient-to-r from-amber-200 via-amber-300 to-amber-400 bg-clip-text text-transparent">in one place.</span>
                </p>
                <p class="mt-4 text-pretty text-lg text-teal-100">Purchases, sales, stock and books for all your branches.</p>

                <ul class="mt-6 space-y-3 xl:mt-8">
                    <li v-for="point in points" :key="point" class="flex items-start gap-3 text-base text-teal-50">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-amber-400/15 text-amber-300 ring-1 ring-amber-300/30" aria-hidden="true">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                            </svg>
                        </span>
                        {{ point }}
                    </li>
                </ul>
            </div>
        </aside>

        <!-- The form side. -->
        <div class="flex flex-1 flex-col lg:min-h-screen lg:w-[48%] xl:w-[45%]">
            <!-- Phones and tablets: the brand in a band at the top. -->
            <div class="relative overflow-hidden bg-gradient-to-br from-teal-900 via-teal-950 to-emerald-950 px-6 pb-20 pt-6 text-white lg:hidden">
                <div class="auth-dots pointer-events-none absolute inset-0" aria-hidden="true"></div>
                <!-- In the band's open corner, clear of the card that rises over the bottom of the band. -->
                <GoldBarsArt class="pointer-events-none absolute right-0 top-2 h-auto w-36 sm:right-8 sm:w-44" aria-hidden="true" />
                <Link href="/" class="relative inline-flex min-h-12 items-center gap-3 rounded-lg focus-visible:outline-amber-400">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-200 via-amber-400 to-amber-600 text-lg font-bold text-teal-950 shadow-lg shadow-black/20" aria-hidden="true">A</span>
                    <span class="text-2xl font-semibold tracking-tight">Adoma<span class="text-amber-400" aria-hidden="true">.</span></span>
                </Link>
                <p class="relative mt-2 max-w-[11rem] text-base text-teal-100 sm:max-w-xs">Gold trading and accounting</p>
            </div>

            <main class="flex flex-1 items-start justify-center px-4 sm:px-6 lg:items-center lg:px-12 xl:px-16">
                <div class="relative -mt-12 w-full max-w-md rounded-2xl bg-white p-6 shadow-xl shadow-gray-900/5 ring-1 ring-gray-200 sm:p-8 lg:mt-0 lg:max-w-[26rem] lg:rounded-none lg:p-0 lg:shadow-none lg:ring-0">
                    <slot />
                </div>
            </main>

            <footer class="px-6 pb-6 pt-8 text-center text-sm text-gray-600 lg:px-12 lg:text-left xl:px-16">
                © {{ year }} Adoma · Gold trading and accounting
            </footer>
        </div>

        <ConfirmHost />
    </div>
</template>
