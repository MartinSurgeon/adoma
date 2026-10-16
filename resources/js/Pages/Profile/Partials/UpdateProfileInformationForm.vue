<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps({
    mustVerifyEmail: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section aria-labelledby="details-heading">
        <header>
            <h2 id="details-heading" class="text-lg font-semibold text-gray-900">Your details</h2>
            <p class="mt-1 text-base text-gray-700">Your name shows on the transactions you record. You sign in with this email.</p>
        </header>

        <form @submit.prevent="form.patch(route('profile.update'))" class="mt-6 space-y-6">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" type="text" class="mt-1 block w-full" v-model="form.name" required autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="mt-2 text-base text-gray-800">
                    Your email address is not confirmed yet.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="inline-flex min-h-12 items-center rounded-md font-medium text-teal-800 underline hover:text-teal-900"
                    >
                        Send the confirmation email again
                    </Link>
                </p>

                <p v-show="status === 'verification-link-sent'" class="mt-2 text-base font-medium text-green-800">
                    A new confirmation link has been sent to your email address.
                </p>
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save details' }}</PrimaryButton>

                <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0" leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
                    <p v-if="form.recentlySuccessful" class="inline-flex items-center gap-2 text-base font-medium text-green-800" role="status">
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                        </svg>
                        Saved
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
