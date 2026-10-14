<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const page = usePage();
const tenancy = computed(() => page.props.tenancy);

// The select shows the active office id, or "all" for admins viewing every office.
const current = computed(() => (tenancy.value?.all_branches ? 'all' : tenancy.value?.branch?.id ?? ''));

function switchOffice(event) {
    router.post(
        route('branch.switch'),
        { branch: event.target.value },
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <div v-if="tenancy" class="flex items-center gap-2">
        <label for="office-switcher" class="sr-only">Active office</label>
        <div class="relative flex items-center">
            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-500" aria-hidden="true">
                <svg class="h-4 w-4 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4zm3 1h2v2H7V5zm4 0h2v2h-2V5zm-4 4h2v2H7V9zm4 0h2v2h-2V9zm-4 4h2v2H7v-2zm4 0h2v2h-2v-2z" clip-rule="evenodd" />
                </svg>
            </span>
            <select
                id="office-switcher"
                :value="current"
                @change="switchOffice"
                class="min-h-12 rounded-md border-gray-300 py-2 pe-9 ps-9 text-base text-gray-700 shadow-sm focus:border-teal-600 focus:ring-teal-600"
            >
                <option v-if="tenancy.can_view_all" value="all">All offices</option>
                <option v-for="office in tenancy.branches" :key="office.id" :value="office.id">
                    {{ office.name }}
                </option>
            </select>
        </div>
    </div>
</template>
