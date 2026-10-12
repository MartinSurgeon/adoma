<script setup>
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ logs: Object, type: String, types: Array });

const selected = ref(props.type);
watch(selected, (value) =>
    router.get(route('activity.index'), { type: value || undefined }, { preserveScroll: true, replace: true }),
);

const when = (iso) =>
    new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// Values are text, numbers, yes/no or a list of offices and roles.
function show(value) {
    if (value === null || value === undefined || value === '') return 'nothing';
    if (typeof value === 'object') {
        const entries = Object.entries(value);
        return entries.length ? entries.map(([k, v]) => `${k}: ${v}`).join(', ') : 'nothing';
    }
    if (value === true) return 'yes';
    if (value === false) return 'no';
    return String(value);
}
</script>

<template>
    <Head title="Activity" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Activity</h1>
            <p class="mt-1 text-sm text-gray-600">Who did what, newest first. Entries cannot be changed or removed.</p>
        </template>

        <div class="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <label for="type" class="block text-sm font-medium text-gray-700">Show</label>
                <select
                    id="type"
                    v-model="selected"
                    class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:w-64"
                >
                    <option value="">Everything</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </div>

            <ul class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white shadow-sm">
                <li v-for="log in logs.data" :key="log.id" class="p-4">
                    <p class="text-sm text-gray-900">
                        <span class="font-semibold">{{ log.actor }}</span> {{ log.description }}
                    </p>
                    <p class="mt-1 text-xs text-gray-500">{{ when(log.when) }}</p>

                    <details v-if="log.changes.length" class="mt-2 text-sm">
                        <summary class="cursor-pointer text-teal-700 hover:underline">Details</summary>
                        <ul class="mt-2 space-y-1 text-gray-700">
                            <li v-for="change in log.changes" :key="change.field">
                                <span class="font-medium capitalize">{{ change.field }}:</span>
                                {{ show(change.old) }} to {{ show(change.new) }}
                            </li>
                        </ul>
                    </details>
                </li>

                <li v-if="!logs.data.length" class="p-8 text-center text-sm text-gray-500">Nothing to show yet.</li>
            </ul>

            <Pagination :paginator="logs" />
        </div>
    </AuthenticatedLayout>
</template>
