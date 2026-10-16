<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserForm from '@/Components/UserForm.vue';

defineProps({
    branches: Array,
    branchRoles: Array,
    canAssignAdminRoles: Boolean,
    canGrantBranchManagement: Boolean,
    roleSummaries: Object,
    roleGuide: Array,
});
</script>

<template>
    <Head title="Add a user" />

    <AuthenticatedLayout
        title="Add a user"
        subtitle="They get a temporary password and choose their own at first sign-in."
        :back="{ href: route('users.index'), label: 'Users' }"
        width="3xl"
    >
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <UserForm
                :initial="{ name: '', email: '', tenant_role: null, can_manage_branches: false, offices: [] }"
                method="post"
                :action="route('users.store')"
                submit-label="Create account"
                :branches="branches"
                :branch-roles="branchRoles"
                :can-assign-admin-roles="canAssignAdminRoles"
                :can-grant-branch-management="canGrantBranchManagement"
                :role-summaries="roleSummaries"
                :role-guide="roleGuide"
            />
        </div>
    </AuthenticatedLayout>
</template>
