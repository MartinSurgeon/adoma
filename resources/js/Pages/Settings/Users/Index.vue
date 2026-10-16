<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import CredentialsBanner from '@/Components/CredentialsBanner.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import NavIcon from '@/Components/NavIcon.vue';
import RoleGuide from '@/Components/RoleGuide.vue';
import { sinceWhen } from '@/format';
import { ROLE_BADGES, roleLabel } from '@/roles';
import { resetPassword, switchAccount } from '@/userActions';

/**
 * Who can sign in, and what they can do in each branch.
 *
 * Owners and admins come first, in their own group: they work in every branch. Then everyone who works in chosen
 * branches, with a label for each branch and role. Find someone by name, by branch or by state.
 * Room enough: a table. A phone: a card per person.
 */
const props = defineProps({
    users: { type: Array, required: true },
    branches: { type: Array, default: () => [] },
    roleGuide: { type: Array, default: () => [] },
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const credentials = computed(() => page.props.flash?.credentials);

const search = ref('');
const branch = ref('');
const state = ref('');

const states = [
    { value: '', label: 'Everyone' },
    { value: 'active', label: 'Active' },
    { value: 'waiting', label: 'Not signed in yet' },
    { value: 'off', label: 'Switched off' },
];

const leads = (user) => user.tenant_role === 'owner' || user.tenant_role === 'admin';

const shown = computed(() => {
    const words = search.value.trim().toLowerCase();

    return props.users.filter((user) => {
        if (words && !`${user.name} ${user.email}`.toLowerCase().includes(words)) return false;
        // Owners and admins work in every branch, so they belong to every branch's list.
        if (branch.value && !leads(user) && !user.offices.some((o) => String(o.branch_id) === String(branch.value))) return false;
        if (state.value === 'active') return user.is_active;
        if (state.value === 'off') return !user.is_active;
        if (state.value === 'waiting') return user.is_active && user.must_change_password;
        return true;
    });
});

const groups = computed(() =>
    [
        { key: 'leads', title: 'Owners and admins', hint: 'Every branch', people: shown.value.filter(leads) },
        { key: 'staff', title: 'Branch staff', hint: 'A role in each chosen branch', people: shown.value.filter((u) => !leads(u)) },
    ].filter((group) => group.people.length),
);

const filtering = computed(() => Boolean(search.value.trim() || branch.value || state.value));
const counts = computed(() => ({
    active: props.users.filter((u) => u.is_active).length,
    waiting: props.users.filter((u) => u.is_active && u.must_change_password).length,
    off: props.users.filter((u) => !u.is_active).length,
}));

function clearFilters() {
    search.value = '';
    branch.value = '';
    state.value = '';
}

const initial = (name) => name.trim().charAt(0).toUpperCase();

function status(user) {
    if (!user.is_active) return { label: 'Switched off', badge: 'badge-gray' };
    if (user.must_change_password) return { label: 'Not signed in yet', badge: 'badge-amber' };
    return { label: 'Active', badge: 'badge-green' };
}

function open(event, user) {
    if (!user.can_manage || event.target.closest('a, button')) return;
    router.visit(route('users.edit', user.id));
}

const field = 'block min-h-12 w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout title="Users" subtitle="Who can sign in, and what they can do in each branch." width="7xl">
        <template #actions>
            <ButtonLink :href="route('users.create')">
                <NavIcon name="plus" class="h-5 w-5" stroke-width="2" />
                Add user
            </ButtonLink>
        </template>

        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>
            <CredentialsBanner v-if="credentials" :credentials="credentials" />

            <section class="table-card" aria-labelledby="people-heading">
                <div class="table-title">
                    <div>
                        <h2 id="people-heading" class="text-lg font-semibold text-gray-900">People</h2>
                        <p class="text-sm text-gray-700">
                            {{ users.length }} {{ users.length === 1 ? 'person' : 'people' }} · {{ counts.active }} active<template v-if="counts.waiting"> · {{ counts.waiting }} not signed in yet</template><template v-if="counts.off"> · {{ counts.off }} switched off</template>
                        </p>
                    </div>
                </div>

                <!-- Finding someone: by name or email, by branch, by state. -->
                <div class="grid gap-3 border-t border-gray-100 px-6 py-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                    <div>
                        <label for="user-search" class="text-sm font-medium text-gray-800">Find</label>
                        <input id="user-search" v-model="search" type="search" placeholder="Name or email" autocomplete="off" :class="[field, 'mt-1']" />
                    </div>
                    <div>
                        <label for="user-branch" class="text-sm font-medium text-gray-800">Branch</label>
                        <select id="user-branch" v-model="branch" :class="[field, 'mt-1']">
                            <option value="">All branches</option>
                            <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="user-state" class="text-sm font-medium text-gray-800">Show</label>
                        <select id="user-state" v-model="state" :class="[field, 'mt-1']">
                            <option v-for="s in states" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                </div>

                <div v-if="!shown.length" class="mx-6 mb-6 flex flex-col items-center rounded-xl border border-dashed border-gray-300 p-8 text-center">
                    <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon name="users" class="h-6 w-6" /></span>
                    <p class="mt-3 text-base font-medium text-gray-900">{{ filtering ? 'Nobody matches' : 'No users yet' }}</p>
                    <button v-if="filtering" type="button" class="mt-2 inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-teal-800 underline underline-offset-4 hover:bg-teal-50" @click="clearFilters">Show everyone</button>
                </div>

                <div v-else class="@container border-t border-gray-100">
                    <!-- Room enough: a table, grouped. -->
                    <div class="hidden overflow-x-auto @4xl:block">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th scope="col">Person</th>
                                    <th scope="col">Branches and roles</th>
                                    <th scope="col">Last signed in</th>
                                    <th scope="col">State</th>
                                    <th scope="col"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody v-for="group in groups" :key="group.key">
                                <tr class="group-row">
                                    <th colspan="5" scope="colgroup">{{ group.title }} <span class="font-normal text-teal-800">· {{ group.hint }}</span></th>
                                </tr>
                                <tr v-for="user in group.people" :key="user.id" :class="{ 'row-link': user.can_manage }" @click="open($event, user)">
                                    <td>
                                        <div class="flex items-center gap-3">
                                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-base font-semibold" :class="user.is_active ? 'bg-teal-100 text-teal-900' : 'bg-gray-100 text-gray-700'" aria-hidden="true">{{ initial(user.name) }}</span>
                                            <span class="min-w-0">
                                                <span class="flex items-center gap-2">
                                                    <Link v-if="user.can_manage" :href="route('users.edit', user.id)" class="inline-flex min-h-12 items-center font-medium text-gray-900 underline-offset-4 hover:underline">{{ user.name }}</Link>
                                                    <span v-else class="font-medium text-gray-900">{{ user.name }}</span>
                                                    <span v-if="user.is_self" class="badge badge-teal">You</span>
                                                </span>
                                                <span class="block truncate text-sm text-gray-700">{{ user.email }}</span>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="flex flex-wrap gap-1.5">
                                            <template v-if="leads(user)">
                                                <span class="badge" :class="ROLE_BADGES[user.tenant_role]">{{ roleLabel(user.tenant_role) }} · every branch</span>
                                                <span v-if="user.tenant_role === 'admin' && user.can_manage_branches" class="badge badge-gray">Can add branches</span>
                                            </template>
                                            <span v-for="office in user.offices" v-else :key="office.branch_id" class="badge" :class="ROLE_BADGES[office.role]">{{ office.name }} · {{ roleLabel(office.role) }}</span>
                                            <span v-if="!leads(user) && !user.offices.length" class="text-sm text-gray-700">No branch</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap text-gray-800">{{ sinceWhen(user.last_sign_in) }}</td>
                                    <td><span class="badge" :class="status(user).badge">{{ status(user).label }}</span></td>
                                    <td class="text-right">
                                        <MoreMenu v-if="user.can_manage" :label="`More for ${user.name}`">
                                            <MenuItem :href="route('users.edit', user.id)">Edit access</MenuItem>
                                            <MenuItem @click="resetPassword(user)">Reset password</MenuItem>
                                            <MenuItem :danger="user.is_active" @click="switchAccount(user)">{{ user.is_active ? 'Switch off' : 'Switch on' }}</MenuItem>
                                        </MoreMenu>
                                        <Link v-else-if="user.is_self" :href="route('profile.edit')" class="inline-flex min-h-12 items-center rounded-lg px-2 text-base font-medium text-teal-800 hover:bg-teal-50">My profile</Link>
                                        <span v-else class="text-sm text-gray-700">Only the owner</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- A phone: a card per person. -->
                    <div class="@4xl:hidden">
                        <section v-for="group in groups" :key="group.key">
                            <h3 class="border-y border-gray-200 bg-teal-50/60 px-4 py-2.5 text-sm font-semibold text-teal-900 sm:px-6">{{ group.title }} <span class="font-normal text-teal-800">· {{ group.hint }}</span></h3>
                            <ul class="divide-y divide-gray-100">
                                <li v-for="user in group.people" :key="user.id" class="flex items-start gap-3 px-4 py-4 sm:px-6">
                                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-base font-semibold" :class="user.is_active ? 'bg-teal-100 text-teal-900' : 'bg-gray-100 text-gray-700'" aria-hidden="true">{{ initial(user.name) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-center gap-2">
                                            <Link v-if="user.can_manage" :href="route('users.edit', user.id)" class="inline-flex min-h-12 items-center text-base font-medium text-gray-900 underline-offset-4 hover:underline">{{ user.name }}</Link>
                                            <span v-else class="text-base font-medium text-gray-900">{{ user.name }}</span>
                                            <span v-if="user.is_self" class="badge badge-teal">You</span>
                                        </p>
                                        <p class="truncate text-sm text-gray-700">{{ user.email }}</p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <span v-if="leads(user)" class="badge" :class="ROLE_BADGES[user.tenant_role]">{{ roleLabel(user.tenant_role) }} · every branch</span>
                                            <span v-for="office in user.offices" v-else :key="office.branch_id" class="badge" :class="ROLE_BADGES[office.role]">{{ office.name }} · {{ roleLabel(office.role) }}</span>
                                            <span class="badge" :class="status(user).badge">{{ status(user).label }}</span>
                                        </div>
                                        <p class="mt-2 text-sm text-gray-700">Last signed in: {{ sinceWhen(user.last_sign_in) }}</p>
                                    </div>
                                    <MoreMenu v-if="user.can_manage" :label="`More for ${user.name}`">
                                        <MenuItem :href="route('users.edit', user.id)">Edit access</MenuItem>
                                        <MenuItem @click="resetPassword(user)">Reset password</MenuItem>
                                        <MenuItem :danger="user.is_active" @click="switchAccount(user)">{{ user.is_active ? 'Switch off' : 'Switch on' }}</MenuItem>
                                    </MoreMenu>
                                </li>
                            </ul>
                        </section>
                    </div>
                </div>
            </section>

            <RoleGuide :rows="roleGuide" />
        </div>
    </AuthenticatedLayout>
</template>
