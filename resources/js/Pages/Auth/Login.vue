<script setup>
import { ref } from 'vue';
import AuthSubmitButton from '@/Components/AuthSubmitButton.vue';
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const email = ref(null);
const showPassword = ref(false);
const capsLock = ref(false);

// A password typed with Caps Lock on is the most common reason a right password fails. Say so while it is on.
function checkCaps(event) {
    if (typeof event.getModifierState === 'function') capsLock.value = event.getModifierState('CapsLock');
}

const submit = () => {
    form.post(route('login'), {
        // The password is cleared, and hidden again, so the next one is not typed in plain view at a counter.
        onFinish: () => {
            form.reset('password');
            showPassword.value = false;
        },
        // Back to the email box, where the message is, so the next try starts in the right place.
        onError: () => email.value?.focus(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-gray-900">Welcome back</h1>
            <p class="mt-2 text-pretty text-base text-gray-700">Log in with your work email and password.</p>
        </header>

        <p v-if="status" class="mt-6 flex items-start gap-3 rounded-xl bg-green-50 p-4 text-base text-green-900 ring-1 ring-green-200" role="status">
            <svg class="mt-0.5 h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
            </svg>
            {{ status }}
        </p>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <div class="relative mt-1.5">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-12 items-center justify-center text-gray-500" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 6h16v12H4z" /><path d="M4 7l8 6 8-6" />
                        </svg>
                    </span>
                    <TextInput
                        id="email"
                        ref="email"
                        type="email"
                        class="block w-full rounded-lg ps-12"
                        v-model="form.email"
                        placeholder="you@example.com"
                        required
                        autofocus
                        autocomplete="username"
                        inputmode="email"
                        :aria-invalid="form.errors.email ? 'true' : undefined"
                        :aria-describedby="form.errors.email ? 'email-error' : undefined"
                    />
                </div>
                <InputError id="email-error" class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <div class="relative mt-1.5">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-12 items-center justify-center text-gray-500" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" />
                        </svg>
                    </span>
                    <TextInput
                        id="password"
                        :type="showPassword ? 'text' : 'password'"
                        class="block w-full rounded-lg pe-12 ps-12"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                        :aria-invalid="form.errors.password ? 'true' : undefined"
                        :aria-describedby="form.errors.password ? 'password-error' : undefined"
                        @keydown="checkCaps"
                        @keyup="checkCaps"
                        @blur="capsLock = false"
                    />
                    <button
                        type="button"
                        class="absolute inset-y-0 end-0 flex w-12 items-center justify-center rounded-e-lg text-gray-600 hover:text-gray-900"
                        :aria-label="showPassword ? 'Hide the password' : 'Show the password'"
                        :aria-pressed="showPassword"
                        @click="showPassword = !showPassword"
                    >
                        <svg v-if="showPassword" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A9.8 9.8 0 0112 5c5 0 9 4.5 10 7a12.6 12.6 0 01-3.2 4.3M6.1 6.1C3.9 7.6 2.5 9.8 2 12c1 2.5 5 7 10 7a9.6 9.6 0 004.2-1" />
                        </svg>
                        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 12c1-2.5 5-7 10-7s9 4.5 10 7c-1 2.5-5 7-10 7S3 14.5 2 12z" /><circle cx="12" cy="12" r="3" />
                        </svg>
                    </button>
                </div>
                <p v-if="capsLock" class="mt-2 flex items-center gap-2 text-sm font-medium text-amber-800" role="status">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M12 9v4m0 3h.01M10.3 3.9L2.4 18a2 2 0 001.7 3h15.8a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" />
                    </svg>
                    Caps Lock is on.
                </p>
                <InputError id="password-error" class="mt-2" :message="form.errors.password" />
            </div>

            <label class="flex min-h-12 cursor-pointer items-center gap-3">
                <Checkbox name="remember" v-model:checked="form.remember" />
                <span class="text-base text-gray-800">Keep me logged in on this device</span>
            </label>

            <AuthSubmitButton :processing="form.processing" busy-label="Logging in…">Log in</AuthSubmitButton>
        </form>

        <!-- Help, quiet and below the one main action: what to do about a forgotten password. -->
        <section class="mt-8 rounded-2xl bg-gradient-to-b from-gray-50 to-white p-5 ring-1 ring-gray-200" aria-labelledby="forgot-heading">
            <div class="flex items-start gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-teal-700 shadow-sm ring-1 ring-gray-200" aria-hidden="true">
                    <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.03 5.91c-.56-.1-1.16.03-1.56.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.82c0-.6.24-1.17.66-1.59l6.5-6.5c.4-.4.53-1 .43-1.56A6 6 0 1121.75 8.25z" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <h2 id="forgot-heading" class="text-base font-semibold text-gray-900">Forgot your password?</h2>
                    <p v-if="canResetPassword" class="mt-1 text-pretty text-sm leading-relaxed text-gray-600">
                        <Link :href="route('password.request')" class="inline-flex min-h-12 items-center font-medium text-teal-800 underline hover:text-teal-900">Reset it</Link>
                    </p>
                    <p v-else class="mt-1 text-pretty text-sm leading-relaxed text-gray-600">
                        Ask the owner or an admin of your business for a temporary password. You will choose your own as soon as you log in.
                    </p>
                </div>
            </div>
        </section>

        <!-- Who can get in, said once, plainly. -->
        <p class="mt-6 flex items-start gap-3 text-sm leading-relaxed text-gray-600">
            <svg class="mt-0.5 h-5 w-5 shrink-0 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z" /><path d="M9 12l2 2 4-4" />
            </svg>
            <span><span class="font-semibold text-gray-900">Private to your business.</span> Only staff added by the owner or an admin can log in, and each person sees only their own branches.</span>
        </p>
    </GuestLayout>
</template>
