<script setup>
import { ref } from 'vue';
import AuthSubmitButton from '@/Components/AuthSubmitButton.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { logOut } from '@/logout';
import { useLeaveGuard } from '@/leaveGuard';

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const showPasswords = ref(false);

useLeaveGuard({ dirty: () => form.isDirty, busy: () => form.processing, noun: 'new password' });

const submit = () => {
    form.put(route('password.force.update'), {
        onFinish: () => {
            form.reset();
            showPasswords.value = false;
        },
    });
};

const fields = [
    { key: 'current_password', label: 'Temporary password', autocomplete: 'current-password', autofocus: true },
    { key: 'password', label: 'New password', autocomplete: 'new-password', hint: 'At least 10 characters, with letters and numbers.' },
    { key: 'password_confirmation', label: 'Type the new password again', autocomplete: 'new-password' },
];
</script>

<template>
    <GuestLayout>
        <Head title="Choose a new password" />

        <header>
            <h1 class="text-3xl font-semibold tracking-tight text-gray-900">Choose your own password</h1>
            <p class="mt-2 text-pretty text-base text-gray-700">Your admin gave you a temporary password. Choose a new one to continue.</p>
        </header>

        <form class="mt-8 space-y-5" @submit.prevent="submit">
            <div v-for="field in fields" :key="field.key">
                <InputLabel :for="field.key" :value="field.label" />
                <div class="relative mt-1.5">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex w-12 items-center justify-center text-gray-500" aria-hidden="true">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="5" y="11" width="14" height="10" rx="2" /><path d="M8 11V7a4 4 0 018 0v4" />
                        </svg>
                    </span>
                    <TextInput
                        :id="field.key"
                        :type="showPasswords ? 'text' : 'password'"
                        class="block w-full rounded-lg ps-12"
                        v-model="form[field.key]"
                        required
                        :autofocus="field.autofocus || undefined"
                        :autocomplete="field.autocomplete"
                        :aria-invalid="form.errors[field.key] ? 'true' : undefined"
                        :aria-describedby="[field.hint ? `${field.key}-hint` : null, form.errors[field.key] ? `${field.key}-error` : null].filter(Boolean).join(' ') || undefined"
                    />
                </div>
                <p v-if="field.hint" :id="`${field.key}-hint`" class="mt-1.5 text-sm text-gray-700">{{ field.hint }}</p>
                <InputError :id="`${field.key}-error`" class="mt-2" :message="form.errors[field.key]" />
            </div>

            <label class="flex min-h-12 cursor-pointer items-center gap-3">
                <input v-model="showPasswords" type="checkbox" class="h-5 w-5 rounded border-gray-400 text-teal-700 shadow-sm focus:ring-teal-600" />
                <span class="text-base text-gray-800">Show what I type</span>
            </label>

            <AuthSubmitButton :processing="form.processing" busy-label="Saving…">Save new password</AuthSubmitButton>
        </form>

        <p class="mt-6 border-t border-gray-200 pt-4 text-center">
            <button
                type="button"
                class="inline-flex min-h-12 items-center rounded-lg px-2 text-base font-medium text-gray-700 underline hover:text-gray-900"
                @click="logOut"
            >
                Log out instead
            </button>
        </p>
    </GuestLayout>
</template>
