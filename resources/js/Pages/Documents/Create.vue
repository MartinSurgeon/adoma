<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ButtonLink from '@/Components/ButtonLink.vue';
import Banner from '@/Components/Banner.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import TextInput from '@/Components/TextInput.vue';
import TransactionLayout from '@/Components/TransactionLayout.vue';

const props = defineProps({
    type: String, // receipt or payment
    words: Object,
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    cashLedgers: { type: Array, default: () => [] },
});

const form = useForm({
    date: props.today ?? '',
    ledger_id: '',
    cash_ledger_id: props.cashLedgers[0]?.id ?? '',
    amount: '',
    note: '',
    idempotency_key: props.idempotencyKey ?? '',
});

const submit = () => form.post(route(`${props.type}s.store`));

// The amount as it will read on the receipt, worked out while typing. The server checks it again on saving.
const typed = computed(() => {
    const value = Number(String(form.amount).replace(/,/g, ''));
    return Number.isFinite(value) && value > 0 ? value : null;
});
const amountText = computed(() => (typed.value === null ? null : 'GH₵ ' + typed.value.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 })));
const cashName = computed(() => props.cashLedgers.find((l) => l.id === form.cash_ledger_id)?.name);
const effect = computed(() => (props.type === 'receipt' ? 'goes up' : 'goes down'));
</script>

<template>
    <TransactionLayout
        :title="words.title"
        :submit-label="words.button"
        :processing="form.processing"
        :needs-office="needsOffice"
        :noun="type"
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
                        help="Customers, suppliers, banks and other accounts in your list."
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
                    <select id="cash" v-model="form.cash_ledger_id" class="mt-1 block w-full rounded-md border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600">
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
            <InputLabel for="amount" value="Amount (GH₵)" />
            <TextInput
                id="amount"
                class="mt-1 block w-full text-right text-3xl font-semibold tabular-nums"
                inputmode="decimal"
                autocomplete="off"
                placeholder="0.00"
                v-model="form.amount"
                required
            />
            <InputError class="mt-2" :message="form.errors.amount" />
        </FormSection>

        <template #summary>
            <div class="rounded-lg bg-teal-50 p-6 ring-1 ring-teal-100">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>
                <dl class="mt-4 space-y-4 text-base">
                    <div>
                        <dt class="text-gray-700">Amount</dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ amountText ?? 'Not entered yet' }}</dd>
                    </div>
                    <div v-if="cashName">
                        <dt class="text-gray-700">What happens</dt>
                        <dd class="mt-1 text-gray-900">{{ cashName }} {{ effect }}.</dd>
                    </div>
                </dl>
                <p class="mt-4 text-sm text-gray-700">The server checks everything again when you save.</p>
            </div>
        </template>
    </TransactionLayout>
</template>
