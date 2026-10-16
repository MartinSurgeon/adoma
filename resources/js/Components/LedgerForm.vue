<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormActionBar from '@/Components/FormActionBar.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { useLeaveGuard } from '@/leaveGuard';

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

// Adding or renaming a ledger is easy to undo (edit it, or switch it off), so saving does not ask. Leaving with
// typed-in details does.
useLeaveGuard({ dirty: () => form.isDirty, busy: () => form.processing, noun: 'ledger' });
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6 pb-28">
        <FormSection v-if="!editing" title="1. What is it?">
            <fieldset class="space-y-2">
                <legend class="sr-only">Type of ledger</legend>
                <label
                    v-for="kind in kinds"
                    :key="kind.value"
                    class="flex min-h-12 cursor-pointer items-start gap-3 rounded-xl border p-3 transition"
                    :class="form.kind === kind.value ? 'border-teal-600 bg-teal-50' : 'border-gray-200 hover:bg-gray-50'"
                >
                    <input type="radio" v-model="form.kind" :value="kind.value" class="mt-1 h-5 w-5 text-teal-700 focus:ring-teal-600" />
                    <span>
                        <span class="block text-base font-medium text-gray-900">{{ kind.label }}</span>
                        <span class="block text-base text-gray-700">{{ kind.help }}</span>
                    </span>
                </label>
            </fieldset>
            <InputError class="mt-2" :message="form.errors.kind" />
        </FormSection>

        <FormSection :title="editing ? 'Details' : '2. Details'">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" class="mt-1 block w-full" v-model="form.name" required autofocus autocomplete="off" />
                    <p class="mt-1 text-sm text-gray-700">The name staff will search for.</p>
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>
                <div>
                    <InputLabel for="phone" value="Phone (optional)" />
                    <TextInput id="phone" type="tel" class="mt-1 block w-full" v-model="form.phone" autocomplete="off" />
                    <InputError class="mt-2" :message="form.errors.phone" />
                </div>
            </div>
        </FormSection>

        <FormSection v-if="!editing" title="3. Which branch?">
            <p v-if="!chooseOffice && !form.is_shared" class="text-base text-gray-800">
                This ledger will belong to <strong>{{ activeOffice }}</strong>. Only that branch sees it.
            </p>

            <div v-if="chooseOffice && !form.is_shared">
                <InputLabel for="branch_id" value="Branch" />
                <select
                    id="branch_id"
                    v-model="form.branch_id"
                    class="mt-1 block min-h-12 w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600 sm:max-w-md"
                >
                    <option value="" disabled>Choose a branch</option>
                    <option v-for="office in branches" :key="office.id" :value="office.id">{{ office.name }}</option>
                </select>
                <InputError class="mt-2" :message="form.errors.branch_id" />
            </div>

            <label v-if="canShare" class="mt-4 flex min-h-12 cursor-pointer items-start gap-3">
                <input type="checkbox" v-model="form.is_shared" class="mt-1 h-5 w-5 rounded text-teal-700 focus:ring-teal-600" />
                <span>
                    <span class="block text-base font-medium text-gray-900">Let every branch use this ledger</span>
                    <span class="block text-base text-gray-700">Only owners and admins can do this.</span>
                </span>
            </label>
        </FormSection>

        <FormActionBar :cancel-href="route('ledgers.index')" :submit-label="submitLabel" :processing="form.processing" narrow />
    </form>
</template>
