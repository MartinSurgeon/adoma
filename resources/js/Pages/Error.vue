<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import NavIcon from '@/Components/NavIcon.vue';

/**
 * An error, as a page of the app: what happened in plain words, and a way on. The words come from the server
 * (app/Http/ErrorPage.php), which knows what was missing and where you are working.
 *
 * Signed in, it sits in the app's frame, so the menu and the branch switcher stay at hand: a transaction that
 * belongs to another branch is one switch away. Otherwise (signed out, or an account that is switched off)
 * it stands on its own.
 */
const props = defineProps({
    status: { type: Number, required: true },
    title: { type: String, required: true },
    message: { type: String, required: true },
    primary: { type: Object, required: true }, // { label, href }, { label, reload: true } or { label, logout: true }
    secondary: { type: Object, default: null }, // { label, href }
});

const page = usePage();
const framed = computed(() => Boolean(page.props.auth?.user && page.props.tenancy?.tenant));

const icon = computed(() => ({ 403: 'lock', 404: 'search' })[props.status] ?? 'alert');

const reload = () => window.location.reload();

// For an account that has been switched off: nothing else works, so no "Are you sure?" first.
const logOut = () => router.post(route('logout'));

function act() {
    if (props.primary.logout) logOut();
    else reload();
}
</script>

<template>
    <Head :title="title" />

    <component :is="framed ? AuthenticatedLayout : 'div'" :class="framed ? undefined : 'flex min-h-screen flex-col bg-gray-50'">
        <div class="mx-auto flex w-full max-w-xl flex-1 flex-col justify-center px-4 py-10 sm:px-6 sm:py-16">
            <Link v-if="!framed" href="/" class="mb-6 inline-flex min-h-12 items-center gap-3 self-center rounded-lg px-1 text-2xl font-semibold tracking-tight text-teal-900">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-amber-200 via-amber-400 to-amber-600 text-lg font-bold text-teal-950 shadow-sm" aria-hidden="true">A</span>
                <span>Adoma<span class="text-amber-700" aria-hidden="true">.</span></span>
            </Link>

            <section class="card p-6 text-center sm:p-10" aria-labelledby="error-title">
                <span class="icon-chip mx-auto h-14 w-14 rounded-xl" aria-hidden="true"><NavIcon :name="icon" class="h-7 w-7" /></span>
                <p class="mt-5 text-sm font-medium text-gray-600">Error {{ status }}</p>
                <h1 id="error-title" class="mt-1 text-2xl font-semibold text-gray-900">{{ title }}</h1>
                <p class="mx-auto mt-3 max-w-md text-pretty text-base text-gray-700">{{ message }}</p>

                <!-- One main way on, and at most one quieter one. On a phone the main one comes first. -->
                <div class="mt-8 flex flex-col-reverse gap-3 sm:flex-row sm:justify-center">
                    <ButtonLink v-if="secondary" :href="secondary.href" variant="secondary">{{ secondary.label }}</ButtonLink>
                    <button v-if="primary.reload || primary.logout" type="button" class="btn btn-primary" @click="act">{{ primary.label }}</button>
                    <ButtonLink v-else :href="primary.href">{{ primary.label }}</ButtonLink>
                </div>
            </section>
        </div>
    </component>
</template>
