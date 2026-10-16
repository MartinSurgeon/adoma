<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

defineProps({ users: Array });

const page = usePage();
const success = computed(() => page.props.flash?.success);
const credentials = computed(() => page.props.flash?.credentials);
const copied = ref(false);

async function copyPassword() {
    try {
        await navigator.clipboard.writeText(credentials.value.password);
        copied.value = true;
    } catch {
        copied.value = false; // the password is still on screen to read out
    }
}

// One dialog for both risky actions: { kind: 'toggle' | 'reset', user }
const pending = ref(null);
const working = ref(false);

function confirmAction() {
    const { kind, user } = pending.value;
    working.value = true;
    const options = {
        preserveScroll: true,
        onFinish: () => {
            working.value = false;
            pending.value = null;
        },
    };

    if (kind === 'toggle') router.patch(route('users.toggle', user.id), {}, options);
    else router.post(route('users.reset', user.id), {}, options);
}

const dialog = computed(() => {
    if (!pending.value) return { title: '', label: '', danger: false, body: '' };
    const { kind, user } = pending.value;

    if (kind === 'reset') {
        return {
            title: `Reset the password for ${user.name}?`,
            label: 'Reset password',
            danger: true,
            body: 'They will be signed out and need the new temporary password. You will see it once, on the next screen.',
        };
    }

    return user.is_active
        ? { title: `Switch off ${user.name}?`, label: 'Switch off', danger: true, body: 'They will be signed out everywhere and cannot sign in until you switch them on again.' }
        : { title: `Let ${user.name} sign in again?`, label: 'Switch on', danger: false, body: 'They will be able to sign in with their existing password.' };
});

function accessLabel(user) {
    if (user.tenant_role === 'owner') return 'Owner, all branches';
    if (user.tenant_role === 'admin') return 'Admin, all branches';
    if (!user.offices.length) return 'No branches yet';
    return user.offices.map((o) => `${o.name}: ${o.role}`).join(', ');
}
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout title="Users" subtitle="People who can sign in to your business." width="7xl">
        <template #actions>
            <ButtonLink :href="route('users.create')">Add user</ButtonLink>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>

            <Banner v-if="credentials" variant="warning">
                <p class="font-semibold">Give {{ credentials.name }} these sign-in details now</p>
                <p class="mt-1">
                    The password is shown only once. {{ credentials.name }} must choose their own password at first sign-in.
                </p>
                <dl class="mt-3 grid gap-2 sm:grid-cols-[auto_1fr] sm:gap-x-4">
                    <dt>Email</dt>
                    <dd class="font-mono text-gray-900">{{ credentials.email }}</dd>
                    <dt>Temporary password</dt>
                    <dd class="font-mono text-lg font-semibold text-gray-900">{{ credentials.password }}</dd>
                </dl>
                <SecondaryButton type="button" class="mt-3" @click="copyPassword">{{ copied ? 'Copied' : 'Copy password' }}</SecondaryButton>
            </Banner>

            <div class="card overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col" class="hidden sm:table-cell">Access</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id">
                            <td>
                                <div class="font-medium text-gray-900">{{ user.name }}</div>
                                <div class="text-gray-700">{{ user.email }}</div>
                            </td>
                            <td class="hidden sm:table-cell">{{ accessLabel(user) }}</td>
                            <td>
                                <span
                                    class="inline-flex rounded-full px-3 py-1 text-sm font-medium"
                                    :class="user.is_active ? 'bg-green-100 text-green-900' : 'bg-gray-200 text-gray-900'"
                                >
                                    {{ user.is_active ? 'Active' : 'Switched off' }}
                                </span>
                                <span v-if="user.must_change_password" class="mt-1 block text-sm text-amber-900">
                                    Has not chosen a password yet
                                </span>
                            </td>
                            <td class="text-right">
                                <MoreMenu v-if="user.can_manage" :label="`More actions for ${user.name}`">
                                    <MenuItem :href="route('users.edit', user.id)">Edit</MenuItem>
                                    <MenuItem @click="pending = { kind: 'reset', user }">Reset password</MenuItem>
                                    <MenuItem :danger="user.is_active" @click="pending = { kind: 'toggle', user }">
                                        {{ user.is_active ? 'Switch off' : 'Switch on' }}
                                    </MenuItem>
                                </MoreMenu>
                                <span v-else class="text-sm text-gray-700">Managed elsewhere</span>
                            </td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="4" class="py-8 text-center text-gray-700">No users yet. Add the first one.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmDialog
            :show="pending !== null"
            :title="dialog.title"
            :confirm-label="dialog.label"
            :danger="dialog.danger"
            :processing="working"
            @close="pending = null"
            @confirm="confirmAction"
        >
            <p>{{ dialog.body }}</p>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
