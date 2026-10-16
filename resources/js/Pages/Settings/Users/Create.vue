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

    <AuthenticatedLayout
        title="Add a user"
        subtitle="They get a temporary password and choose their own at first login."
        :back="{ href: route('users.index'), label: 'Users' }"
        width="3xl"
    >
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
