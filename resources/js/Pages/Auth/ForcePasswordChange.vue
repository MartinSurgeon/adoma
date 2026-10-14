<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.put(route('password.force.update'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Choose a new password" />

        <h1 class="text-lg font-semibold text-gray-900">Choose your own password</h1>
        <p class="mb-4 mt-1 text-base text-gray-700">
            Your admin gave you a temporary password. Choose a new one to continue.
        </p>

        <form @submit.prevent="submit" class="space-y-4">
            <div>
                <InputLabel for="current_password" value="Temporary password" />
                <TextInput
                    id="current_password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.current_password"
                    required
                    autofocus
                    autocomplete="current-password"
                />
                <InputError class="mt-2" :message="form.errors.current_password" />
            </div>

            <div>
                <InputLabel for="password" value="New password" />
                <TextInput
                    id="password"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="new-password"
                />
                <p class="mt-1 text-base text-gray-700">At least 10 characters, with letters and numbers.</p>
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Type the new password again" />
                <TextInput
                    id="password_confirmation"
                    type="password"
                    class="mt-1 block w-full"
                    v-model="form.password_confirmation"
                    required
                    autocomplete="new-password"
                />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <div class="flex items-center justify-between pt-2">
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-base text-gray-700 underline hover:text-gray-900"
                >
                    Sign out
                </Link>
                <PrimaryButton class="min-h-12" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                    Save new password
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
