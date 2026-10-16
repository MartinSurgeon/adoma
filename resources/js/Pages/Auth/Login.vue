<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
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

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h1 class="text-2xl font-semibold text-gray-900">Log in</h1>
        <p class="mt-1 text-base text-gray-700">Use the email and password your admin gave you.</p>

        <p v-if="status" class="mt-4 rounded-md bg-green-50 px-4 py-3 text-base font-medium text-green-800" role="status">
            {{ status }}
        </p>

        <form class="mt-6 space-y-5" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" type="password" class="mt-1 block w-full" v-model="form.password" required autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <label class="flex min-h-12 cursor-pointer items-center gap-3">
                <Checkbox name="remember" v-model:checked="form.remember" />
                <span class="text-base text-gray-800">Keep me logged in on this computer</span>
            </label>

            <PrimaryButton class="w-full" :disabled="form.processing">
                {{ form.processing ? 'Logging in…' : 'Log in' }}
            </PrimaryButton>

            <p class="text-center text-base text-gray-700">
                Forgot your password?
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="inline-flex min-h-12 items-center font-medium text-teal-800 underline hover:text-teal-900"
                >
                    Reset it
                </Link>
                <template v-else>Ask your admin to reset it.</template>
            </p>
        </form>
    </GuestLayout>
</template>
