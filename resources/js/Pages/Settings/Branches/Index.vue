<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import NavIcon from '@/Components/NavIcon.vue';
import { ROLE_BADGES, roleLabel } from '@/roles';

/**
 * Where the business works, and who works in each branch. A card per branch opens its page, where people are
 * given their roles there. Owners and admins work in every branch, so they are named once, below the cards.
 */
const props = defineProps({
    branches: { type: Array, required: true }, // [{ id, name, code, region, is_head_office, added, people: { manager, clerk, viewer } }]
    admins: { type: Array, default: () => [] }, // [{ id, name, role }]
    canCreate: Boolean,
    canEdit: Boolean,
});

const page = usePage();
const success = computed(() => page.props.flash?.success);

const plural = { manager: 'managers', clerk: 'clerks', viewer: 'viewers' };
const peopleIn = (branch) =>
    ['manager', 'clerk', 'viewer'].filter((role) => branch.people[role] > 0).map((role) => ({ role, count: branch.people[role], label: `${branch.people[role]} ${branch.people[role] === 1 ? role : plural[role]}` }));

const added = (iso) => (iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
</script>

<template>
    <Head title="Branches" />

    <AuthenticatedLayout title="Branches" subtitle="Where the business works, and who works in each." width="7xl">
        <template v-if="canCreate" #actions>
            <ButtonLink :href="route('branches.create')">
                <NavIcon name="plus" class="h-5 w-5" stroke-width="2" />
                Add branch
            </ButtonLink>
        </template>

        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>

            <ul class="grid gap-4 @3xl:grid-cols-2 @6xl:grid-cols-3">
                <li v-for="branch in branches" :key="branch.id">
                    <Link :href="route('branches.show', branch.id)" class="card card-link flex h-full flex-col p-6">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="icon-chip" aria-hidden="true"><NavIcon name="branch" class="h-5 w-5" /></span>
                                <span class="min-w-0">
                                    <span class="block truncate text-lg font-semibold text-gray-900">{{ branch.name }}</span>
                                    <span class="block text-sm text-gray-700"><span class="ref">{{ branch.code }}</span><template v-if="branch.region"> · {{ branch.region }}</template></span>
                                </span>
                            </div>
                            <span v-if="branch.is_head_office" class="badge badge-amber">Head office</span>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-1.5">
                            <span v-for="entry in peopleIn(branch)" :key="entry.role" class="badge" :class="ROLE_BADGES[entry.role]">{{ entry.label }}</span>
                            <span v-if="!peopleIn(branch).length" class="badge badge-amber">
                                <NavIcon name="users" class="h-4 w-4" />Nobody given a role yet
                            </span>
                        </div>

                        <div class="mt-auto pt-5">
                            <p class="flex items-center justify-between gap-3 border-t border-gray-100 pt-3 text-sm text-gray-700">
                                <span>Added {{ added(branch.added) }}</span>
                                <span class="inline-flex items-center gap-1 font-medium text-teal-800">People and roles <NavIcon name="arrowRight" class="h-4 w-4" stroke-width="2" /></span>
                            </p>
                        </div>
                    </Link>
                </li>
            </ul>

            <!-- Owners and admins: in every branch without a role in each. -->
            <section class="card p-6" aria-labelledby="everywhere-heading">
                <h2 id="everywhere-heading" class="text-lg font-semibold text-gray-900">In every branch</h2>
                <p class="mt-1 text-base text-gray-700">Owners and admins work in all branches, so they are not listed in each one.</p>
                <ul class="mt-3 flex flex-wrap gap-2">
                    <li v-for="person in admins" :key="person.id" class="badge" :class="ROLE_BADGES[person.role]">{{ person.name }} · {{ roleLabel(person.role) }}</li>
                </ul>
            </section>

            <p v-if="!canEdit" class="flex items-start gap-2 text-base text-gray-700">
                <NavIcon name="lock" class="mt-0.5 h-5 w-5 shrink-0 text-gray-600" />
                Adding and editing branches is for the owner, or an admin the owner allows. You can still choose who works in each branch.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
