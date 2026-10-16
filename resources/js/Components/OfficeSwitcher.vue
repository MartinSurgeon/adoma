<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import NavIcon from '@/Components/NavIcon.vue';

const page = usePage();
const tenancy = computed(() => page.props.tenancy);

// The select shows the active branch id, or "all" for admins viewing every branch.
const current = computed(() => (tenancy.value?.all_branches ? 'all' : tenancy.value?.branch?.id ?? ''));

// Someone with one branch has nothing to switch to: they see its name, not a control that does nothing.
const onlyOne = computed(() => !tenancy.value?.can_view_all && (tenancy.value?.branches?.length ?? 0) <= 1);

function switchOffice(event) {
    router.post(
        route('branch.switch'),
        { branch: event.target.value },
        { preserveScroll: true, preserveState: false },
    );
}
</script>

<template>
    <div v-if="tenancy" class="flex min-w-0 items-center">
        <p v-if="onlyOne" class="flex min-h-12 min-w-0 items-center gap-2 px-2 text-base font-medium text-gray-800">
            <NavIcon name="branch" class="h-5 w-5 shrink-0 text-gray-600" />
            <span class="truncate">{{ tenancy.branch?.name }}</span>
        </p>

        <template v-else>
            <label for="office-switcher" class="sr-only">Branch you are working in</label>
            <div class="relative flex min-w-0 items-center">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-gray-600" aria-hidden="true">
                    <NavIcon name="branch" class="h-5 w-5" />
                </span>
                <select
                    id="office-switcher"
                    :value="current"
                    class="min-h-12 w-full max-w-[9.5rem] truncate rounded-md border-gray-300 py-2 pe-9 ps-10 text-base font-medium text-gray-800 shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:max-w-[15rem]"
                    @change="switchOffice"
                >
                    <option v-if="tenancy.can_view_all" value="all">All branches</option>
                    <option v-for="office in tenancy.branches" :key="office.id" :value="office.id">
                        {{ office.name }}
                    </option>
                </select>
            </div>
        </template>
    </div>
</template>
