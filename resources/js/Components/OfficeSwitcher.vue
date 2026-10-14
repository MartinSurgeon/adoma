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
        <select
            id="office-switcher"
            :value="current"
            @change="switchOffice"
            class="min-h-12 rounded-md border-gray-300 py-2 pe-9 ps-3 text-base text-gray-700 shadow-sm focus:border-teal-600 focus:ring-teal-600"
        >
            <option v-if="tenancy.can_view_all" value="all">All offices</option>
            <option v-for="office in tenancy.branches" :key="office.id" :value="office.id">
                {{ office.name }}
            </option>
        </select>
    </div>
</template>
