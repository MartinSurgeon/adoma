<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormActionBar from '@/Components/FormActionBar.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import { confirm } from '@/confirm';
import { useLeaveGuard } from '@/leaveGuard';

/**
 * Adding a branch, or changing its name, region or head office.
 *
 * The code is chosen once: it is in every voucher number the branch prints (SAL-TAK-000001), so it never changes
 * afterwards. Adding a branch is asked first, with what it brings, because it cannot be taken back.
 */
const props = defineProps({
    initial: { type: Object, required: true }, // { name, code, region, is_head_office }
    method: { type: String, required: true }, // 'post' or 'put'
    action: { type: String, required: true },
    cancelHref: { type: String, required: true },
    submitLabel: { type: String, required: true },
    headOffice: { type: String, default: null }, // the head office now, if another branch
    codes: { type: Array, default: () => [] }, // codes already used, to warn before sending
});

const creating = computed(() => props.method === 'post');

const form = useForm({
    name: props.initial.name ?? '',
    code: props.initial.code ?? '',
    region: props.initial.region ?? '',
    is_head_office: Boolean(props.initial.is_head_office),
});

// A code is capitals and numbers; typing lower case or spaces is forgiven as you type.
function tidyCode() {
    form.code = form.code.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 5);
}

const codeTaken = computed(() => creating.value && props.codes.includes(form.code));
const example = computed(() => `SAL-${form.code || 'TAK'}-000001`);

async function submit() {
    if (creating.value) {
        const yes = await confirm({
            title: `Add the branch ${form.name.trim() || 'with this name'}?`,
            details: [
                { label: 'Code', value: form.code || '—' },
                { label: 'Voucher numbers', value: example.value },
                ...(form.is_head_office ? [{ label: 'Head office', value: props.headOffice ? `Yes, instead of ${props.headOffice}` : 'Yes' }] : []),
            ],
            body: 'It gets its own stock, books and voucher numbers. A branch cannot be removed, and its code cannot change.',
            confirmLabel: 'Add branch',
            cancelLabel: 'Go back',
        });
        if (!yes) return;
    } else if (form.is_head_office && !props.initial.is_head_office && props.headOffice) {
        const yes = await confirm({
            title: `Make ${form.name} the head office?`,
            body: `${props.headOffice} stops being the head office. The head office is shown first everywhere.`,
            confirmLabel: 'Save changes',
            cancelLabel: 'Go back',
        });
        if (!yes) return;
    }

    form.transform((data) => (creating.value ? data : { name: data.name, region: data.region, is_head_office: data.is_head_office }))[props.method](props.action);
}

useLeaveGuard({ dirty: () => form.isDirty, busy: () => form.processing, noun: 'branch' });
</script>

<template>
    <form class="space-y-6 pb-28" @submit.prevent="submit">
        <FormSection title="1. Branch">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <InputLabel for="name" value="Branch name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus autocomplete="off" placeholder="Takoradi" />
                    <p class="mt-1 text-sm text-gray-700">How everyone will see it: on the branch menu, in reports and on transfers.</p>
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="code" value="Code" />
                    <template v-if="creating">
                        <TextInput id="code" v-model="form.code" class="mt-1 block w-full font-mono uppercase tracking-wider" required maxlength="5" autocomplete="off" placeholder="TAK" @input="tidyCode" />
                        <p class="mt-1 text-sm" :class="codeTaken ? 'font-medium text-red-800' : 'text-gray-700'">
                            {{ codeTaken ? 'Another branch already uses this code.' : `2 to 5 letters or numbers. Voucher numbers will look like ${example}.` }}
                        </p>
                    </template>
                    <template v-else>
                        <p id="code" class="mt-1 flex min-h-12 items-center rounded-lg bg-gray-50 px-3 font-mono text-base tracking-wider text-gray-900 ring-1 ring-inset ring-gray-200">{{ form.code }}</p>
                        <p class="mt-1 text-sm text-gray-700">Cannot change: it is in every voucher number this branch has printed.</p>
                    </template>
                    <InputError class="mt-2" :message="form.errors.code" />
                </div>

                <div>
                    <InputLabel for="region" value="Region (optional)" />
                    <TextInput id="region" v-model="form.region" class="mt-1 block w-full" autocomplete="off" placeholder="Western" />
                    <InputError class="mt-2" :message="form.errors.region" />
                </div>
            </div>
        </FormSection>

        <FormSection title="2. Head office">
            <label class="flex cursor-pointer items-start gap-3 rounded-xl p-4 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 has-[:focus-visible]:outline has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-teal-600">
                <input v-model="form.is_head_office" type="checkbox" class="mt-1 h-5 w-5 rounded border-gray-400 text-teal-700 focus:ring-teal-600" />
                <span>
                    <span class="block text-base font-semibold text-gray-900">This is the head office</span>
                    <span class="mt-0.5 block text-sm text-gray-700">
                        Shown first everywhere. There is one head office<template v-if="headOffice">: now {{ headOffice }}, which stops being it if you tick this</template>.
                    </span>
                </span>
            </label>
            <InputError class="mt-2" :message="form.errors.is_head_office" />
        </FormSection>

        <FormActionBar :cancel-href="cancelHref" :submit-label="submitLabel" :processing="form.processing" :disabled="codeTaken" narrow />
    </form>
</template>
