<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

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

function toggle(user) {
    const message = user.is_active
        ? `Switch off ${user.name}? They will be signed out everywhere and cannot sign in.`
        : `Let ${user.name} sign in again?`;

    if (confirm(message)) {
        router.patch(route('users.toggle', user.id), {}, { preserveScroll: true });
    }
}

function resetPassword(user) {
    if (confirm(`Reset the password for ${user.name}? They will be signed out and need the new temporary password.`)) {
        router.post(route('users.reset', user.id), {}, { preserveScroll: true });
    }
}

function accessLabel(user) {
    if (user.tenant_role === 'owner') return 'Owner, all offices';
    if (user.tenant_role === 'admin') return 'Admin, all offices';
    if (!user.offices.length) return 'No offices yet';
    return user.offices.map((o) => `${o.name}: ${o.role}`).join(', ');
}
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold leading-tight text-gray-800">Users</h1>
                    <p class="mt-1 text-sm text-gray-600">People who can sign in to your business.</p>
                </div>
                <Link :href="route('users.create')">
                    <PrimaryButton class="min-h-[44px]" type="button">Add user</PrimaryButton>
                </Link>
            </div>
        </template>

        <div class="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">
                {{ success }}
            </div>

            <div v-if="credentials" role="alert" class="rounded-md border border-amber-300 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-900">Give {{ credentials.name }} these sign-in details now</p>
                <p class="mt-1 text-sm text-amber-900">
                    The password is shown only once. {{ credentials.name }} must choose their own password at first sign-in.
                </p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
                    <dt class="text-amber-800">Email</dt>
                    <dd class="font-mono text-gray-900">{{ credentials.email }}</dd>
                    <dt class="text-amber-800">Temporary password</dt>
                    <dd class="font-mono text-base font-semibold text-gray-900">{{ credentials.password }}</dd>
                </dl>
                <button
                    type="button"
                    @click="copyPassword"
                    class="mt-3 min-h-[44px] rounded-md border border-amber-400 bg-white px-4 text-sm font-medium text-amber-900 hover:bg-amber-100"
                >
                    {{ copied ? 'Copied' : 'Copy password' }}
                </button>
            </div>

            <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="px-4 py-3">Access</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="user in users" :key="user.id">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-900">{{ user.name }}</div>
                                <div class="text-gray-500">{{ user.email }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ accessLabel(user) }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                    :class="user.is_active ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-700'"
                                >
                                    {{ user.is_active ? 'Active' : 'Switched off' }}
                                </span>
                                <span v-if="user.must_change_password" class="ms-2 text-xs text-amber-700">
                                    Has not chosen a password yet
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div v-if="user.can_manage" class="flex flex-wrap justify-end gap-2">
                                    <Link
                                        :href="route('users.edit', user.id)"
                                        class="inline-flex min-h-[44px] items-center rounded-md border border-gray-300 px-3 text-gray-700 hover:bg-gray-50"
                                    >
                                        Edit
                                    </Link>
                                    <button
                                        type="button"
                                        @click="resetPassword(user)"
                                        class="min-h-[44px] rounded-md border border-gray-300 px-3 text-gray-700 hover:bg-gray-50"
                                    >
                                        Reset password
                                    </button>
                                    <button
                                        type="button"
                                        @click="toggle(user)"
                                        class="min-h-[44px] rounded-md border px-3"
                                        :class="user.is_active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                                    >
                                        {{ user.is_active ? 'Switch off' : 'Switch on' }}
                                    </button>
                                </div>
                                <div v-else class="text-right text-xs text-gray-400">You cannot change this account here</div>
                            </td>
                        </tr>
                        <tr v-if="!users.length">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500">No users yet. Add the first one.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
