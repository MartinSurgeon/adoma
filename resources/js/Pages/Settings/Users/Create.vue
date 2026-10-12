<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserForm from '@/Components/UserForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    branches: Array,
    branchRoles: Array,
    canAssignAdminRoles: Boolean,
});
</script>

<template>
    <Head title="Add a user" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Add a user</h1>
            <p class="mt-1 text-sm text-gray-600">
                You will get a temporary password to give them. They choose their own when they first sign in.
            </p>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <UserForm
                :initial="{ name: '', email: '', tenant_role: null, offices: [] }"
                method="post"
                :action="route('users.store')"
                submit-label="Create account"
                :branches="branches"
                :branch-roles="branchRoles"
                :can-assign-admin-roles="canAssignAdminRoles"
            />
        </div>
    </AuthenticatedLayout>
</template>
