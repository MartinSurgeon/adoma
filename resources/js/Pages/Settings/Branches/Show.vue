<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import FormActionBar from '@/Components/FormActionBar.vue';
import InputError from '@/Components/InputError.vue';
import NavIcon from '@/Components/NavIcon.vue';
import RoleGuide from '@/Components/RoleGuide.vue';
import SegmentedControl from '@/Components/SegmentedControl.vue';
import { confirm } from '@/confirm';
import { useLeaveGuard } from '@/leaveGuard';
import { ROLE_BADGES, branchRoleOptions, roleLabel } from '@/roles';

/**
 * One branch, and who works there in what role.
 *
 * Every member of staff has a row: choose their role here, or "No access". Only this branch changes; their roles in
 * other branches stay as they are. The changes are listed and asked before saving. Owners and admins work here
 * already, as in every branch.
 */
const props = defineProps({
    branch: { type: Object, required: true },
    staff: { type: Array, required: true }, // [{ id, name, email, is_active, role, only_here, elsewhere, can_manage }]
    admins: { type: Array, default: () => [] },
    branchRoles: { type: Array, required: true },
    roleSummaries: { type: Object, default: () => ({}) },
    roleGuide: { type: Array, default: () => [] },
    canEdit: Boolean,
});

const page = usePage();
const success = computed(() => page.props.flash?.success);

const form = useForm({ roles: Object.fromEntries(props.staff.map((p) => [p.id, p.role ?? ''])) });

const roleOptions = computed(() => branchRoleOptions(props.branchRoles));

const changes = computed(() =>
    props.staff
        .filter((p) => (form.roles[p.id] || null) !== (p.role || null))
        .map((p) => ({ person: p, from: p.role, to: form.roles[p.id] || null })),
);

// Taking away someone's only branch would leave them able to sign in to nothing.
const stranded = computed(() => changes.value.filter((c) => c.to === null && c.person.only_here).map((c) => c.person.id));

const working = computed(() => props.staff.filter((p) => p.is_active && form.roles[p.id]).length);

async function save() {
    if (!changes.value.length || stranded.value.length) return;

    const yes = await confirm({
        title: `Change who works in ${props.branch.name}?`,
        details: changes.value.map((c) => ({
            label: c.person.name,
            value: `${c.from ? roleLabel(c.from) : 'No access'} → ${c.to ? roleLabel(c.to) : 'No access'}`,
        })),
        body: 'Only this branch changes. The change applies the next time they open a page.',
        confirmLabel: 'Save roles',
        cancelLabel: 'Go back',
    });
    if (!yes) return;

    form.transform(() => ({ roles: Object.fromEntries(changes.value.map((c) => [c.person.id, c.to])) })).put(route('branches.access', props.branch.id), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

useLeaveGuard({ dirty: () => changes.value.length > 0, busy: () => form.processing, noun: 'roles' });

const added = computed(() => (props.branch.added ? new Date(`${props.branch.added}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : ''));
const initial = (name) => name.trim().charAt(0).toUpperCase();
</script>

<template>
    <Head :title="branch.name" />

    <AuthenticatedLayout :title="branch.name" :back="{ href: route('branches.index'), label: 'Branches' }" width="5xl">
        <template #status>
            <span v-if="branch.is_head_office" class="badge badge-amber">Head office</span>
        </template>
        <template #subtitle>
            Code <span class="ref text-gray-900">{{ branch.code }}</span><template v-if="branch.region"> · {{ branch.region }}</template> · Added {{ added }}
        </template>
        <template v-if="canEdit" #actions>
            <ButtonLink :href="route('branches.edit', branch.id)" variant="secondary">Edit details</ButtonLink>
        </template>

        <form class="mx-auto max-w-5xl space-y-6 px-4 pb-28 pt-8 sm:px-6 lg:px-8" @submit.prevent="save">
            <Banner v-if="success" variant="success">{{ success }}</Banner>

            <section class="table-card" aria-labelledby="people-heading">
                <div class="table-title">
                    <div>
                        <h2 id="people-heading" class="text-lg font-semibold text-gray-900">People and roles</h2>
                        <p class="text-sm text-gray-700">Choose each person's role in {{ branch.name }}. Their roles in other branches stay as they are.</p>
                    </div>
                    <span class="badge badge-teal">{{ working }} working here</span>
                </div>

                <div v-if="!staff.length" class="mx-6 mb-6 flex flex-col items-center rounded-xl border border-dashed border-gray-300 p-8 text-center">
                    <span class="icon-chip h-12 w-12" aria-hidden="true"><NavIcon name="users" class="h-6 w-6" /></span>
                    <p class="mt-3 text-base font-medium text-gray-900">No branch staff yet</p>
                    <p class="mt-1 text-base text-gray-700">Add people first, then give them a role here.</p>
                    <Link :href="route('users.create')" class="mt-2 inline-flex min-h-12 items-center rounded-lg px-3 font-medium text-teal-800 underline underline-offset-4 hover:bg-teal-50">Add a user</Link>
                </div>

                <ul v-else class="divide-y divide-gray-100 border-t border-gray-100">
                    <li
                        v-for="person in staff"
                        :key="person.id"
                        class="flex flex-col gap-3 px-6 py-4 transition-colors lg:flex-row lg:items-center lg:justify-between"
                        :class="{ 'bg-amber-50/60': changes.some((c) => c.person.id === person.id) }"
                    >
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-base font-semibold" :class="person.is_active ? 'bg-teal-100 text-teal-900' : 'bg-gray-100 text-gray-700'" aria-hidden="true">{{ initial(person.name) }}</span>
                            <div class="min-w-0">
                                <p :id="`person-${person.id}`" class="flex flex-wrap items-center gap-2 text-base font-medium text-gray-900">
                                    {{ person.name }}
                                    <span v-if="!person.is_active" class="badge badge-gray">Switched off</span>
                                </p>
                                <p class="truncate text-sm text-gray-700">{{ person.email }}</p>
                                <p class="mt-0.5 text-sm text-gray-700">
                                    <template v-if="form.roles[person.id]">{{ roleSummaries[form.roles[person.id]] }}</template>
                                    <template v-else>Cannot see {{ branch.name }}</template>
                                    <template v-if="person.elsewhere.length"> · Also in {{ person.elsewhere.join(', ') }}</template>
                                </p>
                                <p v-if="stranded.includes(person.id)" class="mt-1 text-sm font-medium text-red-800">
                                    {{ branch.name }} is {{ person.name }}'s only branch. Give them another branch first, or switch their account off.
                                </p>
                                <InputError class="mt-1" :message="form.errors[`roles.${person.id}`]" />
                            </div>
                        </div>
                        <fieldset v-if="person.can_manage" class="shrink-0" :aria-labelledby="`person-${person.id}`">
                            <SegmentedControl v-model="form.roles[person.id]" :name="`role-${person.id}`" :options="roleOptions" />
                        </fieldset>
                        <span v-else class="badge" :class="person.role ? ROLE_BADGES[person.role] : 'badge-gray'">{{ person.role ? roleLabel(person.role) : 'No access' }}</span>
                    </li>
                </ul>
            </section>

            <section class="card p-6" aria-labelledby="everywhere-heading">
                <h2 id="everywhere-heading" class="text-lg font-semibold text-gray-900">Also here, as in every branch</h2>
                <p class="mt-1 text-base text-gray-700">Owners and admins work in all branches without a role in each.</p>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li v-for="person in admins" :key="person.id" class="badge" :class="ROLE_BADGES[person.role]">{{ person.name }} · {{ roleLabel(person.role) }}</li>
                </ul>
            </section>

            <RoleGuide :rows="roleGuide" />

            <FormActionBar
                v-if="staff.length"
                :cancel-href="route('branches.index')"
                :submit-label="changes.length ? `Save ${changes.length} ${changes.length === 1 ? 'change' : 'changes'}` : 'Save roles'"
                :processing="form.processing"
                :disabled="!changes.length || stranded.length > 0"
            />
        </form>
    </AuthenticatedLayout>
</template>
