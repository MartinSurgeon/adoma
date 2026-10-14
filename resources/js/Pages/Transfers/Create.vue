<script setup>
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import TransactionLayout from '@/Components/TransactionLayout.vue';

const props = defineProps({
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    inStock: String,
    offices: { type: Array, default: () => [] },
});

const form = useForm({
    date: props.today ?? '',
    to_branch_id: '',
    weight: '',
    note: '',
    idempotency_key: props.idempotencyKey ?? '',
});

// Starting values are not "typed in", so they do not count as unsaved work.
form.defaults();

onMounted(() => document.getElementById('to')?.focus());

const destination = computed(() => props.offices.find((o) => o.id === Number(form.to_branch_id))?.name ?? null);

// The cost comes from the server, worked out from the oldest lots, so what is shown is what will be saved.
const quote = ref(null);
const working = ref(false);
let timer = null;
let latest = 0;

async function ask() {
    if (!form.weight) {
        quote.value = null;
        return;
    }

    const mine = ++latest;
    working.value = true;

    try {
        const { data } = await axios.post(route('transfers.quote'), { weight: form.weight });
        if (mine === latest) quote.value = data;
    } catch {
        // No connection or a server error: the form stays usable, and saving checks everything again.
        if (mine === latest) quote.value = null;
    } finally {
        if (mine === latest) working.value = false;
    }
}

watch(() => form.weight, () => {
    clearTimeout(timer);
    timer = setTimeout(ask, 300);
});

const blocked = computed(() => quote.value !== null && quote.value.ok === false);
const submit = () => form.post(route('transfers.store'));
</script>

<template>
    <TransactionLayout
        title="Send gold to another office"
        submit-label="Send gold"
        :processing="form.processing"
        :blocked="blocked"
        :needs-office="needsOffice"
        noun="transfer"
        :dirty="form.isDirty"
        :errors="form.errors"
        @submit="submit"
    >
        <template #subtitle>{{ office }} · {{ inStock }} g in stock</template>

        <FormSection title="1. Where to">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <InputLabel for="to" value="Send to" />
                    <select
                        id="to"
                        v-model="form.to_branch_id"
                        class="mt-1 block min-h-12 w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
                    >
                        <option value="" disabled>Choose an office</option>
                        <option v-for="o in offices" :key="o.id" :value="o.id">{{ o.name }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.to_branch_id" />
                </div>
                <div>
                    <InputLabel for="date" value="Date" />
                    <TextInput id="date" type="date" class="mt-1 block w-full" v-model="form.date" :max="today" required />
                    <InputError class="mt-2" :message="form.errors.date" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel for="note" value="Note (optional)" />
                    <TextInput id="note" class="mt-1 block w-full" v-model="form.note" maxlength="500" placeholder="For example: who is carrying it" />
                    <InputError class="mt-2" :message="form.errors.note" />
                </div>
            </div>
        </FormSection>

        <!-- The weight last, and the largest thing on the form. -->
        <FormSection title="2. How much">
            <InputLabel for="weight" value="Weight to send (g)" />
            <TextInput
                id="weight"
                class="mt-1 block w-full text-right text-3xl font-semibold tabular-nums"
                inputmode="decimal"
                autocomplete="off"
                placeholder="0.00"
                v-model="form.weight"
                required
            />
            <InputError class="mt-2" :message="form.errors.weight || (quote && !quote.ok && quote.enough !== false ? quote.message : '')" />

            <dl class="mt-4 grid grid-cols-2 gap-4 rounded-md bg-gray-50 p-4 ring-1 ring-gray-200">
                <div>
                    <dt class="text-sm text-gray-700">What this gold cost</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">{{ quote?.ok ? quote.cost : working ? 'Working it out…' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-700">Taken from</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                        {{ quote?.ok ? `${quote.lots} ${quote.lots === 1 ? 'lot' : 'lots'}, oldest first` : '—' }}
                    </dd>
                </div>
            </dl>
        </FormSection>

        <template #summary>
            <div class="rounded-lg bg-teal-50 p-6 ring-1 ring-teal-100">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>

                <Banner v-if="quote && quote.ok === false && quote.enough === false" variant="warning" class="mt-4">
                    Not enough gold in stock. {{ office }} has {{ quote.in_stock }} g.
                </Banner>

                <p v-if="quote?.ok && destination" class="mt-4 text-base text-gray-900">
                    {{ quote.weight }} g leaves {{ office }}'s stock and arrives at {{ destination }}, at what it cost, {{ quote.cost }}.
                    Neither office shows a profit or a loss on it.
                </p>
                <p v-else class="mt-4 text-base text-gray-700">
                    Choose the office and enter the weight to see how this will be recorded.
                </p>

                <p v-if="quote?.ok && destination" class="mt-4 text-base text-gray-900">
                    {{ destination }} will owe {{ office }} {{ quote.cost }} until it is settled with a payment.
                </p>

                <p class="mt-4 text-sm text-gray-700">The server works the figures out again when you save.</p>
            </div>
        </template>
    </TransactionLayout>
</template>
