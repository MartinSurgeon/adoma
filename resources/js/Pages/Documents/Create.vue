<script setup>
import { computed, onMounted, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ButtonLink from '@/Components/ButtonLink.vue';
import Banner from '@/Components/Banner.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import TransactionLayout from '@/Components/TransactionLayout.vue';
import { longDay } from '@/format';

const props = defineProps({
    type: String, // receipt or payment
    words: Object,
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    cashLedgers: { type: Array, default: () => [] },
    prefill: { type: Object, default: null }, // "record another like this"
});

// The cash account used last time is the likely one this time. It is remembered in this browser only.
const rememberKey = `adoma.cash.${props.type}`;
function rememberedCash() {
    try {
        const id = Number(localStorage.getItem(rememberKey));
        return props.cashLedgers.some((l) => l.id === id) ? id : null;
    } catch {
        return null;
    }
}

const fromPrefill = props.prefill?.cash_ledger_id;
const startingCash = props.cashLedgers.some((l) => l.id === fromPrefill)
    ? fromPrefill
    : (rememberedCash() ?? props.cashLedgers[0]?.id ?? '');

const form = useForm({
    date: props.today ?? '',
    ledger_id: props.prefill?.ledger?.id ?? '',
    cash_ledger_id: startingCash,
    amount: '',
    note: props.prefill?.note ?? '',
    idempotency_key: props.idempotencyKey ?? '',
});

// Starting values are not "typed in", so they do not count as unsaved work.
form.defaults();

onMounted(() => {
    // The person comes first, so the cursor starts there (unless it is already chosen).
    if (!form.ledger_id) document.getElementById('party')?.focus();
});

const submit = () =>
    form.post(route(`${props.type}s.store`), {
        onSuccess: () => {
            try {
                localStorage.setItem(rememberKey, String(form.cash_ledger_id));
            } catch {
                // Private window or blocked storage: nothing is lost, it just will not be remembered.
            }
        },
    });

// The amount as it will read on the receipt, worked out while typing. The server checks it again on saving.
const typed = computed(() => {
    const value = Number(String(form.amount).replace(/,/g, ''));
    return Number.isFinite(value) && value > 0 ? value : null;
});
const amountText = computed(() => (typed.value === null ? null : 'GH₵ ' + typed.value.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })));
const cashName = computed(() => props.cashLedgers.find((l) => l.id === form.cash_ledger_id)?.name);
const effect = computed(() => (props.type === 'receipt' ? 'goes up' : 'goes down'));

// ---- The check before saving: who, which account, when, and the amount, last and largest.
const party = ref(props.prefill?.ledger ?? null);

const review = computed(() => ({
    title: props.type === 'receipt' ? 'Save this receipt?' : 'Save this payment?',
    details: [
        { label: props.words.party, value: party.value?.name ?? '—' },
        { label: props.words.cash, value: cashName.value ?? '—' },
        { label: 'Date', value: longDay(form.date) },
        ...(form.note ? [{ label: 'Note', value: form.note }] : []),
        { label: 'Amount', value: amountText.value ?? '—', strong: true },
    ],
}));
</script>

<template>
    <TransactionLayout
        :title="words.title"
        :submit-label="words.button"
        :processing="form.processing"
        :needs-office="needsOffice"
        :noun="type"
        :dirty="form.isDirty"
        :errors="form.errors"
        :review="review"
        @submit="submit"
    >
        <template #subtitle>{{ office }}</template>

        <template v-if="!needsOffice && !cashLedgers.length" #instead>
            <Banner variant="warning">
                <p class="font-semibold">Add a cash or bank account first</p>
                <p class="mt-1">A {{ type }} needs a cash or bank account for the money to go {{ type === 'receipt' ? 'into' : 'out of' }}.</p>
                <ButtonLink :href="route('ledgers.create')" class="mt-4">Add a ledger</ButtonLink>
            </Banner>
        </template>

        <!-- The person first. -->
        <FormSection title="1. Who">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <LedgerPicker
                        v-model="form.ledger_id"
                        input-id="party"
                        :label="words.party"
                        kinds="customer,supplier,bank,cash,expense,branch"
                        :error="form.errors.ledger_id"
                        :initial="prefill?.ledger"
                        help="Customers, suppliers, banks and other accounts in your list."
                        @chosen="party = $event"
                    />
                </div>
                <div>
                    <InputLabel for="date" value="Date" />
                    <TextInput id="date" type="date" class="mt-1 block w-full" v-model="form.date" :max="today" required />
                    <InputError class="mt-2" :message="form.errors.date" />
                </div>
            </div>
        </FormSection>

        <FormSection title="2. Details">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="cash" :value="words.cash" />
                    <select id="cash" v-model="form.cash_ledger_id" class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600">
                        <option v-for="ledger in cashLedgers" :key="ledger.id" :value="ledger.id">{{ ledger.name }}</option>
                    </select>
                    <InputError class="mt-2" :message="form.errors.cash_ledger_id" />
                </div>
                <div>
                    <InputLabel for="note" value="Note (optional)" />
                    <TextInput id="note" class="mt-1 block w-full" v-model="form.note" maxlength="500" placeholder="What is it for?" />
                    <InputError class="mt-2" :message="form.errors.note" />
                </div>
            </div>
        </FormSection>

        <!-- The amount last, and the largest thing on the form. -->
        <FormSection title="3. Amount">
            <InputLabel for="amount" value="Amount" />
            <div class="relative mt-1">
                <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4">
                    <span class="text-xl font-semibold text-gray-500">GH₵</span>
                </div>
                <TextInput
                    id="amount"
                    class="block w-full pl-20 pr-4 text-right text-3xl font-semibold tabular-nums"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="0.00"
                    v-model="form.amount"
                    required
                />
            </div>
            <InputError class="mt-2" :message="form.errors.amount" />
        </FormSection>

        <template #summary>
            <div class="rounded-xl bg-gradient-to-b from-teal-50 to-white p-6 shadow-sm ring-1 ring-inset ring-teal-100">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>
                <dl class="mt-4 space-y-4 text-base">
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Amount</dt>
                        <dd class="mt-1 text-3xl font-bold tabular-nums text-teal-950">{{ amountText ?? 'Not entered yet' }}</dd>
                    </div>
                    <div v-if="cashName" class="border-t border-teal-100 pt-3">
                        <dt class="text-sm font-medium text-gray-600">Account impact</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ cashName }} {{ effect }}.</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-gray-700">The server checks and verifies all figures again when you save.</p>
            </div>
        </template>
    </TransactionLayout>
</template>
