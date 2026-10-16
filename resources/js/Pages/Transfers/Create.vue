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
import { cedis, grams, longDay } from '@/format';

const props = defineProps({
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    inStock: String,
    baseKarat: Number,
    offices: { type: Array, default: () => [] },
});

const form = useForm({
    date: props.today ?? '',
    to_branch_id: '',
    weight: '',
    price: '',
    note: '',
    idempotency_key: props.idempotencyKey ?? '',
});

// Starting values are not "typed in", so they do not count as unsaved work.
form.defaults();

onMounted(() => document.getElementById('to')?.focus());

const destination = computed(() => props.offices.find((o) => o.id === Number(form.to_branch_id))?.name ?? null);

// The figures come from the server, worked out from the oldest lots, so what is shown is what will be saved.
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
        const { data } = await axios.post(route('transfers.quote'), { weight: form.weight, price: form.price });
        if (mine === latest) quote.value = data;
    } catch {
        // No connection or a server error: the form stays usable, and saving checks everything again.
        if (mine === latest) quote.value = null;
    } finally {
        if (mine === latest) working.value = false;
    }
}

watch(() => [form.weight, form.price], () => {
    clearTimeout(timer);
    timer = setTimeout(ask, 300);
});

const blocked = computed(() => quote.value !== null && quote.value.ok === false);
const problem = (field) => (quote.value && !quote.value.ok && quote.value.field === field ? quote.value.message : '');
const stockProblem = computed(() => quote.value && !quote.value.ok && quote.value.enough === false);
const submit = () => form.post(route('transfers.store'));

// ---- The check before sending: where to, how much, the price, and what they will owe, last and largest.
const review = computed(() => {
    const q = quote.value?.ok ? quote.value : null;
    const to = destination.value;

    return {
        title: to ? `Send gold to ${to}?` : 'Send this gold?',
        details: [
            { label: 'To', value: to ?? '—' },
            { label: 'Date', value: longDay(form.date) },
            { label: 'Gold', value: q ? `${grams(form.weight)} from ${q.lots} ${q.lots === 1 ? 'lot' : 'lots'}` : grams(form.weight) },
            { label: 'Price per pound', value: cedis(form.price) },
            { label: `${to ?? 'They'} will owe you`, value: q?.amount ?? '—', strong: true },
        ],
        body: `The gold leaves ${props.office}'s stock now, and joins ${to ?? 'theirs'} only when they accept it. Until then you can cancel it.`,
    };
});
</script>

<template>
    <TransactionLayout
        title="Send gold to a branch"
        submit-label="Send gold"
        :processing="form.processing"
        :blocked="blocked"
        :needs-office="needsOffice"
        noun="transfer"
        :dirty="form.isDirty"
        :errors="form.errors"
        :review="review"
        @submit="submit"
    >
        <template #subtitle>{{ office }} · {{ inStock }} g in stock</template>

        <FormSection title="1. Where to">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <InputLabel for="to" value="Sell to" />
                    <select
                        id="to"
                        v-model="form.to_branch_id"
                        class="mt-1 block min-h-12 w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
                    >
                        <option value="" disabled>Choose a branch</option>
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

        <FormSection title="2. How much gold">
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
            <InputError class="mt-2" :message="form.errors.weight || (quote && !quote.ok && quote.enough !== false && !quote.field ? quote.message : '')" />

            <dl class="mt-4 grid grid-cols-2 gap-4 rounded-xl bg-gray-50 p-4 ring-1 ring-inset ring-gray-200">
                <div>
                    <dt class="text-sm text-gray-700">Taken from</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">
                        {{ quote?.ok ? `${quote.lots} ${quote.lots === 1 ? 'lot' : 'lots'}, oldest first` : '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-sm text-gray-700">What this gold cost you</dt>
                    <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900">{{ quote?.ok ? quote.cost : working ? 'Working it out…' : '—' }}</dd>
                </div>
            </dl>
        </FormSection>

        <!-- The price, and the amount it comes to: last on the form and the largest figure on it. -->
        <FormSection title="3. Price and amount">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="price" :value="`Price per pound at ${baseKarat} karat (GH₵)`" />
                    <TextInput id="price" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.price" required />
                    <p class="mt-1 text-sm text-gray-700">The same price a sale uses. Each lot is priced by its own karat.</p>
                    <InputError class="mt-2" :message="form.errors.price || problem('price')" />
                </div>
                <div class="card-feature p-5">
                    <p class="relative text-sm font-medium uppercase tracking-wide text-amber-200">{{ destination ?? 'The other branch' }} will owe you</p>
                    <p class="relative mt-1 text-3xl font-bold tabular-nums text-white">{{ quote?.ok && quote.amount ? quote.amount : working ? 'Working it out…' : '—' }}</p>
                    <p v-if="quote?.ok && quote.profit" class="mt-2 text-base font-medium" :class="quote.is_loss ? 'text-red-200' : 'text-emerald-200'">
                        {{ quote.is_loss ? 'A loss of' : 'You make' }} {{ quote.profit }} on it
                    </p>
                </div>
            </div>
        </FormSection>

        <template #summary>
            <div class="rounded-xl bg-gradient-to-b from-teal-50 to-white p-6 shadow-sm ring-1 ring-inset ring-teal-100">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>

                <Banner v-if="stockProblem" variant="warning" class="mt-4">
                    Not enough gold in stock. {{ office }} has {{ quote.in_stock }} g.
                </Banner>

                <p v-if="quote?.ok && quote.amount && destination" class="mt-4 text-base text-gray-900">
                    This is a sale. {{ quote.weight }} g leaves {{ office }}'s stock for {{ quote.amount }}, and {{ destination }} will owe
                    {{ office }} that until it is settled with a payment. The gold counts as {{ destination }}'s stock only after they accept it.
                </p>
                <p v-else class="mt-4 text-base text-gray-700">
                    Choose the branch, then enter the weight and the price to see how this will be recorded.
                </p>

                <p class="mt-4 text-sm text-gray-700">The server works the figures out again when you save.</p>
            </div>
        </template>
    </TransactionLayout>
</template>
