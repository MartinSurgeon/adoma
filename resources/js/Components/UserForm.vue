<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormActionBar from '@/Components/FormActionBar.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { confirm } from '@/confirm';
import { useLeaveGuard } from '@/leaveGuard';

const props = defineProps({
    // { name, email, tenant_role, offices: [{ branch_id, role }] }
    initial: { type: Object, required: true },
    method: { type: String, required: true }, // 'post' or 'put'
    action: { type: String, required: true },
    submitLabel: { type: String, required: true },
    branches: { type: Array, required: true },
    branchRoles: { type: Array, required: true },
    canAssignAdminRoles: { type: Boolean, default: false },
});

const roleHelp = {
    viewer: 'Viewer: can look, cannot change anything',
    clerk: 'Clerk: records purchases, sales, receipts and payments',
    manager: 'Manager: records, approves and reverses',
};

const form = useForm({
    name: props.initial.name ?? '',
    email: props.initial.email ?? '',
    // 'offices' = works in chosen branches, otherwise the owner/admin value.
    access: props.initial.tenant_role ?? 'offices',
    roles: Object.fromEntries(
        props.branches.map((b) => [b.id, props.initial.offices?.find((o) => o.branch_id === b.id)?.role ?? '']),
    ),
});

const worksInOffices = computed(() => form.access === 'offices');

// What a set of choices lets the person do, in words: "Admin, all branches" or "Kumasi: clerk · Central: viewer".
function accessInWords(access, roles) {
    if (access === 'owner') return 'Owner, all branches';
    if (access === 'admin') return 'Admin, all branches';
    const lines = props.branches.filter((b) => roles[b.id]).map((b) => `${b.name}: ${roles[b.id]}`);
    return lines.length ? lines.join(' · ') : 'No branches';
}

const creating = computed(() => props.method === 'post');
const startingAccess = accessInWords(form.access, { ...form.roles });
const startingEmail = form.email;

/**
 * Giving someone access to the business, or changing what they can do, is asked first, with the access in words.
 * A change of name only is saved at once.
 */
async function submit() {
    const access = accessInWords(form.access, form.roles);
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
        }))
        [props.method](props.action);
}

const select = 'block min-h-12 rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6 pb-28">
        <FormSection title="1. Person">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="name" value="Full name" />
                    <TextInput id="name" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="off" />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="email" value="Email address" />
                    <TextInput id="email" type="email" class="mt-1 block w-full" v-model="form.email" required autocomplete="off" />
                    <p class="mt-1 text-sm text-gray-700">They sign in with this address. No email is sent.</p>
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>
            </div>
        </FormSection>

        <FormSection title="2. Access">
            <div>
                <InputLabel for="access" value="Access level" />
                <select id="access" v-model="form.access" :class="[select, 'mt-1 w-full sm:max-w-md']">
                    <option value="offices">Works in chosen branches</option>
                    <option v-if="canAssignAdminRoles" value="admin">Admin: all branches, manages users and settings</option>
                    <option v-if="canAssignAdminRoles" value="owner">Owner: everything, in all branches</option>
                </select>
                <InputError class="mt-2" :message="form.errors.tenant_role" />
            </div>

            <div v-if="worksInOffices" class="mt-5">
                <p class="text-base font-medium text-gray-900">Branches and role in each</p>
                <p class="mt-1 text-sm text-gray-700">Leave a branch on "No access" if this person should not see it.</p>

                <div class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200">
                    <div
                        v-for="office in branches"
                        :key="office.id"
                        class="flex flex-col gap-2 p-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <label :for="`role-${office.id}`" class="text-base font-medium text-gray-900">
                            {{ office.name }}
                        </label>
                        <select :id="`role-${office.id}`" v-model="form.roles[office.id]" :class="[select, 'sm:w-80']">
                            <option value="">No access</option>
                            <option v-for="role in branchRoles" :key="role" :value="role">{{ roleHelp[role] }}</option>
                        </select>
                    </div>
                </div>
                <InputError class="mt-2" :message="form.errors.offices" />
            </div>
            <p v-else class="mt-4 text-base text-gray-700">
                Owners and admins can open every branch, so there is nothing to choose here.
            </p>
        </FormSection>

        <FormActionBar :cancel-href="route('users.index')" :submit-label="submitLabel" :processing="form.processing" narrow />
    </form>
</template>
