<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormActionBar from '@/Components/FormActionBar.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

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
    // 'offices' = works in chosen offices, otherwise the owner/admin value.
    access: props.initial.tenant_role ?? 'offices',
    roles: Object.fromEntries(
        props.branches.map((b) => [b.id, props.initial.offices?.find((o) => o.branch_id === b.id)?.role ?? '']),
    ),
});

const worksInOffices = computed(() => form.access === 'offices');

function submit() {
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

const select = 'block min-h-12 rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600';
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
                    <option value="offices">Works in chosen offices</option>
                    <option v-if="canAssignAdminRoles" value="admin">Admin: all offices, manages users and settings</option>
                    <option v-if="canAssignAdminRoles" value="owner">Owner: everything, in all offices</option>
                </select>
                <InputError class="mt-2" :message="form.errors.tenant_role" />
            </div>

            <div v-if="worksInOffices" class="mt-5">
                <p class="text-base font-medium text-gray-900">Offices and role in each</p>
                <p class="mt-1 text-sm text-gray-700">Leave an office on "No access" if this person should not see it.</p>

                <div class="mt-3 divide-y divide-gray-200 rounded-md border border-gray-200">
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
                Owners and admins can open every office, so there is nothing to choose here.
            </p>
        </FormSection>

        <FormActionBar :cancel-href="route('users.index')" :submit-label="submitLabel" :processing="form.processing" narrow />
    </form>
</template>
