<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormActionBar from '@/Components/FormActionBar.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import RoleGuide from '@/Components/RoleGuide.vue';
import SegmentedControl from '@/Components/SegmentedControl.vue';
import TextInput from '@/Components/TextInput.vue';
import { confirm } from '@/confirm';
import { useLeaveGuard } from '@/leaveGuard';
import { accessInWords, branchRoleOptions } from '@/roles';

/**
 * Adding someone, or changing what they can do. Two steps: who they are, then their access.
 *
 * Access is either a role in each chosen branch (viewer, clerk or manager, which can differ from branch to branch),
 * or admin or owner, which cover every branch. Only an owner can make someone an admin or owner, and only an owner
 * can let an admin add and edit branches.
 */
const props = defineProps({
    // { name, email, tenant_role, can_manage_branches, offices: [{ branch_id, role }] }
    initial: { type: Object, required: true },
    method: { type: String, required: true }, // 'post' or 'put'
    action: { type: String, required: true },
    submitLabel: { type: String, required: true },
    branches: { type: Array, required: true }, // [{ id, name, code, is_head_office }]
    branchRoles: { type: Array, required: true },
    canAssignAdminRoles: { type: Boolean, default: false },
    canGrantBranchManagement: { type: Boolean, default: false },
    roleSummaries: { type: Object, default: () => ({}) },
    roleGuide: { type: Array, default: () => [] },
});

const form = useForm({
    name: props.initial.name ?? '',
    email: props.initial.email ?? '',
    // 'offices' = a role in chosen branches; otherwise 'admin' or 'owner'.
    access: props.initial.tenant_role ?? 'offices',
    roles: Object.fromEntries(props.branches.map((b) => [b.id, props.initial.offices?.find((o) => o.branch_id === b.id)?.role ?? ''])),
    can_manage_branches: Boolean(props.initial.tenant_role === 'admin' && props.initial.can_manage_branches),
});

const worksInOffices = computed(() => form.access === 'offices');

const levels = computed(() => [
    { value: 'offices', title: 'Works in chosen branches', text: 'A role in each branch you choose below. It can differ from branch to branch.' },
    ...(props.canAssignAdminRoles
        ? [
              { value: 'admin', title: 'Admin', text: `${props.roleSummaries.admin}. Cannot change an owner or another admin.` },
              { value: 'owner', title: 'Owner', text: `${props.roleSummaries.owner}, including who is an admin or owner.` },
          ]
        : []),
]);

const roleOptions = computed(() => branchRoleOptions(props.branchRoles));

const chosenOffices = (roles) => props.branches.filter((b) => roles[b.id]).map((b) => ({ name: b.name, role: roles[b.id] }));
const accessNow = () => {
    const words = accessInWords(form.access === 'offices' ? null : form.access, chosenOffices(form.roles));
    return form.access === 'admin' && form.can_manage_branches ? `${words}, can add and edit branches` : words;
};

const creating = computed(() => props.method === 'post');
const startingAccess = accessNow();
const startingEmail = form.email;

/**
 * Giving someone access to the business, or changing what they can do, is asked first, with the access in words.
 * A change of name only is saved at once.
 */
async function submit() {
    const access = accessNow();
    const name = form.name.trim() || 'this person';

    if (creating.value) {
        const yes = await confirm({
            title: `Create an account for ${name}?`,
            details: [
                { label: 'Signs in with', value: form.email || '—' },
                { label: 'Can work in', value: access },
            ],
            body: 'You will see a temporary password once, on the next screen, to give to them.',
            confirmLabel: 'Create account',
            cancelLabel: 'Go back',
        });
        if (!yes) return;
    } else if (access !== startingAccess || form.email.trim() !== startingEmail) {
        const yes = await confirm({
            title: `Change what ${name} can do?`,
            details: [
                ...(access !== startingAccess ? [{ label: 'Access before', value: startingAccess }, { label: 'Access after', value: access }] : []),
                ...(form.email.trim() !== startingEmail ? [{ label: 'New sign-in email', value: form.email }] : []),
            ],
            body: 'The change applies the next time they open a page.',
            confirmLabel: 'Save changes',
            cancelLabel: 'Go back',
        });
        if (!yes) return;
    }

    send();
}

useLeaveGuard({ dirty: () => form.isDirty, busy: () => form.processing, noun: 'user' });

function send() {
    form
        .transform((data) => ({
            name: data.name,
            email: data.email,
            tenant_role: data.access === 'offices' ? null : data.access,
            offices:
                data.access === 'offices'
                    ? Object.entries(data.roles)
                          .filter(([, role]) => role)
                          .map(([branch_id, role]) => ({ branch_id: Number(branch_id), role }))
                    : [],
            // Only an owner may send this; anyone else leaves it as it is.
            ...(props.canGrantBranchManagement ? { can_manage_branches: data.access === 'admin' && data.can_manage_branches } : {}),
        }))
        [props.method](props.action);
}

const roleErrors = computed(() => form.errors.offices ?? Object.entries(form.errors).find(([key]) => key.startsWith('offices.'))?.[1]);
</script>

<template>
    <form class="space-y-6 pb-28" @submit.prevent="submit">
        <FormSection title="1. Person">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="name" value="Full name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus autocomplete="off" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="email" value="Email address" />
                    <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" required autocomplete="off" />
                    <p class="mt-1 text-sm text-gray-700">They sign in with this address. No email is sent.</p>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>
            </div>
        </FormSection>

        <FormSection title="2. Access">
            <!-- The level: in chosen branches, or every branch. Only an owner sees the second two. -->
            <fieldset v-if="levels.length > 1">
                <legend class="text-base font-medium text-gray-900">What kind of access?</legend>
                <div class="mt-2 grid gap-3 md:grid-cols-3">
                    <label
                        v-for="level in levels"
                        :key="level.value"
                        class="flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 ring-inset transition has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-teal-600"
                        :class="form.access === level.value ? 'bg-teal-50 ring-2 ring-teal-600' : 'ring-gray-300 hover:bg-gray-50'"
                    >
                        <input v-model="form.access" type="radio" name="access" :value="level.value" class="mt-1 h-5 w-5 border-gray-400 text-teal-700 focus:ring-teal-600" />
                        <span>
                            <span class="block text-base font-semibold text-gray-900">{{ level.title }}</span>
                            <span class="mt-0.5 block text-sm text-gray-700">{{ level.text }}</span>
                        </span>
                    </label>
                </div>
                <InputError class="mt-2" :message="form.errors.tenant_role" />
            </fieldset>

            <!-- A role in each branch, one row per branch. -->
            <div v-if="worksInOffices" :class="{ 'mt-6': levels.length > 1 }">
                <p class="text-base font-medium text-gray-900">Role in each branch</p>
                <p class="mt-1 text-sm text-gray-700">Leave a branch on "No access" if this person should not see it.</p>

                <ul class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl ring-1 ring-gray-200">
                    <li v-for="office in branches" :key="office.id" class="flex flex-col gap-2 p-4 lg:flex-row lg:items-center lg:justify-between">
                        <div class="min-w-0">
                            <p :id="`branch-${office.id}`" class="flex flex-wrap items-center gap-2 text-base font-medium text-gray-900">
                                {{ office.name }}
                                <span class="ref text-gray-600">{{ office.code }}</span>
                                <span v-if="office.is_head_office" class="badge badge-amber">Head office</span>
                            </p>
                            <p class="mt-0.5 text-sm text-gray-700">{{ form.roles[office.id] ? roleSummaries[form.roles[office.id]] : 'Cannot see this branch' }}</p>
                        </div>
                        <fieldset class="shrink-0" :aria-labelledby="`branch-${office.id}`">
                            <SegmentedControl v-model="form.roles[office.id]" :name="`role-${office.id}`" :options="roleOptions" />
                        </fieldset>
                    </li>
                </ul>
                <InputError class="mt-2" :message="roleErrors" />
            </div>

            <div v-else class="mt-6 space-y-4">
                <p class="flex items-start gap-2 text-base text-gray-700">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {{ form.access === 'owner' ? 'An owner' : 'An admin' }} works in every branch, so there are no branches to choose.
                </p>

                <!-- Letting an admin add and edit branches: the owner's decision, off until turned on. -->
                <label
                    v-if="form.access === 'admin' && canGrantBranchManagement"
                    class="flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-teal-600"
                >
                    <input v-model="form.can_manage_branches" type="checkbox" class="mt-1 h-5 w-5 rounded border-gray-400 text-teal-700 focus:ring-teal-600" />
                    <span>
                        <span class="block text-base font-semibold text-gray-900">Can add and edit branches</span>
                        <span class="mt-0.5 block text-sm text-gray-700">A new branch gets its own stock, books and voucher numbers. Off unless you turn it on, and you can take it back at any time.</span>
                    </span>
                </label>
                <p v-else-if="form.access === 'admin' && initial.can_manage_branches" class="text-base text-gray-700">This admin can add and edit branches. Only the owner can change that.</p>
                <InputError :message="form.errors.can_manage_branches" />
            </div>
        </FormSection>

        <RoleGuide :rows="roleGuide" />

        <FormActionBar :cancel-href="route('users.index')" :submit-label="submitLabel" :processing="form.processing" narrow />
    </form>
</template>
