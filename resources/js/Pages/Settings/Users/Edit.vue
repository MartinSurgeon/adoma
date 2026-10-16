<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import CredentialsBanner from '@/Components/CredentialsBanner.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import UserForm from '@/Components/UserForm.vue';
import { sinceWhen } from '@/format';
import { resetPassword, switchAccount } from '@/userActions';

/** One person's details and access, with the account actions (reset password, switch off) in More. */
const props = defineProps({
    user: Object,
    branches: Array,
    branchRoles: Array,
    canAssignAdminRoles: Boolean,
    canGrantBranchManagement: Boolean,
    roleSummaries: Object,
    roleGuide: Array,
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const credentials = computed(() => page.props.flash?.credentials);

const state = computed(() => {
    if (!props.user.is_active) return { label: 'Switched off', badge: 'badge-gray' };
    if (props.user.must_change_password) return { label: 'Not signed in yet', badge: 'badge-amber' };
    return { label: 'Active', badge: 'badge-green' };
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <AuthenticatedLayout :title="user.name" :back="{ href: route('users.index'), label: 'Users' }" width="3xl">
        <template #status>
            <span class="badge" :class="state.badge">{{ state.label }}</span>
        </template>
        <template #subtitle>{{ user.email }} · Last signed in: {{ sinceWhen(user.last_sign_in) }}</template>
        <template #actions>
            <MoreMenu :label="`More for ${user.name}`">
                <MenuItem @click="resetPassword(user)">Reset password</MenuItem>
                <MenuItem :danger="user.is_active" @click="switchAccount(user)">{{ user.is_active ? 'Switch off' : 'Switch on' }}</MenuItem>
            </MoreMenu>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>
            <CredentialsBanner v-if="credentials" :credentials="credentials" />

            <UserForm
                :key="user.id"
                :initial="user"
                method="put"
                :action="route('users.update', user.id)"
                submit-label="Save changes"
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
