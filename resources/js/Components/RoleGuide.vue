<script setup>
import { computed } from 'vue';
import { ROLE_LABELS } from '@/roles';

/**
 * What each role can do, side by side: a closed panel that opens when wanted, so it helps without crowding the page.
 * The lines come from the server (RoleGuide), and a test checks each against the app's real rules.
 * Room enough: a table with a tick per role. A phone: one short list per role.
 */
const props = defineProps({
    rows: { type: Array, required: true }, // [{ key, can, roles: [...], note? }]
    open: { type: Boolean, default: false },
});

const roles = ['viewer', 'clerk', 'manager', 'admin', 'owner'];
const where = { viewer: 'in a branch', clerk: 'in a branch', manager: 'in a branch', admin: 'every branch', owner: 'every branch' };

const byRole = computed(() => roles.map((role) => ({ role, can: props.rows.filter((row) => row.roles.includes(role)) })));
</script>

<template>
    <details class="group card @container" :open="open">
        <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-xl px-6 py-4 hover:bg-gray-50 [&::-webkit-details-marker]:hidden">
            <span>
                <span class="block text-lg font-semibold text-gray-900">What each role can do</span>
                <span class="block text-sm text-gray-700">Viewer, Clerk and Manager are given per branch. Admin and Owner cover every branch.</span>
            </span>
            <svg class="h-5 w-5 shrink-0 text-gray-600 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6" /></svg>
        </summary>

        <!-- Room enough: one row per thing, one column per role. -->
        <div class="hidden overflow-x-auto border-t border-gray-100 @2xl:block">
            <table class="data-table">
                <thead>
                    <tr>
                        <th scope="col">Can</th>
                        <th v-for="role in roles" :key="role" scope="col" class="text-center">
                            {{ ROLE_LABELS[role] }}
                            <span class="block text-sm font-normal text-gray-600">{{ where[role] }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.key">
                        <th scope="row" class="py-3 ps-6 text-left font-normal text-gray-900">
                            {{ row.can }}
                            <span v-if="row.note" class="block text-sm text-gray-700">{{ row.note }}</span>
                        </th>
                        <td v-for="role in roles" :key="role" class="text-center">
                            <span v-if="row.roles.includes(role)" class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-teal-100 text-teal-800">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
                                <span class="sr-only">Yes</span>
                            </span>
                            <span v-else class="text-gray-500"><span aria-hidden="true">—</span><span class="sr-only">No</span></span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- A phone: each role and what it can do. -->
        <ul class="divide-y divide-gray-100 border-t border-gray-100 @2xl:hidden">
            <li v-for="entry in byRole" :key="entry.role" class="px-6 py-4">
                <p class="text-base font-semibold text-gray-900">{{ ROLE_LABELS[entry.role] }} <span class="font-normal text-gray-700">· {{ where[entry.role] }}</span></p>
                <ul class="mt-2 space-y-1">
                    <li v-for="row in entry.can" :key="row.key" class="flex items-start gap-2 text-base text-gray-800">
                        <svg class="mt-1 h-4 w-4 shrink-0 text-teal-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7" /></svg>
                        <span>{{ row.can }}<span v-if="row.note" class="block text-sm text-gray-700">{{ row.note }}</span></span>
                    </li>
                </ul>
            </li>
        </ul>
    </details>
</template>
