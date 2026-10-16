<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import UserForm from '@/Components/UserForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    user: Object,
    branches: Array,
    branchRoles: Array,
    canAssignAdminRoles: Boolean,
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <AuthenticatedLayout :title="`Edit ${user.name}`" :back="{ href: route('users.index'), label: 'Users' }" width="3xl">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <UserForm
                :initial="user"
                method="put"
                :action="route('users.update', user.id)"
                submit-label="Save changes"
                :branches="branches"
                :branch-roles="branchRoles"
                :can-assign-admin-roles="canAssignAdminRoles"
            />
        </div>
    </AuthenticatedLayout>
</template>
