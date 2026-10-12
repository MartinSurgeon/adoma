<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    // Add mode: the kinds this person may add. Edit mode: left empty.
    kinds: { type: Array, default: () => [] },
    canShare: { type: Boolean, default: false },
    chooseOffice: { type: Boolean, default: false },
    activeOffice: { type: String, default: null },
    branches: { type: Array, default: () => [] },
    // Edit mode: { name, phone }
    initial: { type: Object, default: null },
    method: { type: String, required: true }, // 'post' or 'put'
    action: { type: String, required: true },
    submitLabel: { type: String, required: true },
});

const editing = computed(() => props.initial !== null);

const form = useForm({
    kind: props.kinds[0]?.value ?? '',
    name: props.initial?.name ?? '',
    phone: props.initial?.phone ?? '',
    is_shared: false,
    branch_id: '',
});

function submit() {
    form
        .transform((data) =>
            editing.value
                ? { name: data.name, phone: data.phone }
                : { ...data, branch_id: data.is_shared || !props.chooseOffice ? null : data.branch_id || null },
        )
        [props.method](props.action);
}
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <section v-if="!editing" class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">1. What is it?</h2>
            <fieldset class="mt-4 space-y-2">
                <legend class="sr-only">Type of ledger</legend>
                <label
                    v-for="kind in kinds"
                    :key="kind.value"
                    class="flex min-h-[44px] cursor-pointer items-start gap-3 rounded-md border p-3"
                    :class="form.kind === kind.value ? 'border-teal-600 bg-teal-50' : 'border-gray-200 hover:bg-gray-50'"
                >
                    <input type="radio" v-model="form.kind" :value="kind.value" class="mt-1 text-teal-700 focus:ring-teal-600" />
                    <span>
                        <span class="block text-sm font-medium text-gray-900">{{ kind.label }}</span>
                        <span class="block text-sm text-gray-600">{{ kind.help }}</span>
                    </span>
                </label>
            </fieldset>
            <InputError class="mt-2" :message="form.errors.kind" />
        </section>

        <section class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">{{ editing ? 'Details' : '2. Details' }}</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="off" />
                    <p class="mt-1 text-xs text-gray-500">The name staff will search for.</p>
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="phone" value="Phone (optional)" />
                    <TextInput id="phone" type="tel" class="mt-1 block w-full" v-model="form.phone" autocomplete="off" />
                    <InputError class="mt-2" :message="form.errors.phone" />
                </div>
            </div>
        </section>

        <section v-if="!editing" class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-gray-900">3. Which office?</h2>

            <p v-if="!chooseOffice && !form.is_shared" class="mt-3 text-sm text-gray-700">
                This ledger will belong to <strong>{{ activeOffice }}</strong>. Only that office sees it.
            </p>

            <div v-if="chooseOffice && !form.is_shared" class="mt-4">
                <InputLabel for="branch_id" value="Office" />
                <select
                    id="branch_id"
                    v-model="form.branch_id"
                    class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:max-w-md"
                >
                    <option value="" disabled>Choose an office</option>
                    <option v-for="office in branches" :key="office.id" :value="office.id">{{ office.name }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.branch_id" />
            </div>

            <label v-if="canShare" class="mt-4 flex min-h-[44px] cursor-pointer items-start gap-3">
                <input type="checkbox" v-model="form.is_shared" class="mt-1 rounded text-teal-700 focus:ring-teal-600" />
                <span>
                    <span class="block text-sm font-medium text-gray-900">Let every office use this ledger</span>
                    <span class="block text-sm text-gray-600">Only owners and admins can do this.</span>
                </span>
            </label>
        </section>

        <div class="flex items-center justify-end gap-4">
            <Link :href="route('ledgers.index')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
            <PrimaryButton class="min-h-[44px]" :class="{ 'opacity-25': form.processing }" :disabled="form.processing">
                {{ form.processing ? 'Saving…' : submitLabel }}
            </PrimaryButton>
        </div>
    </form>
</template>
