<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ButtonLink from '@/Components/ButtonLink.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    document: Object,
    party_balance: String,
    gold: { type: Object, default: null },
    profit: { type: Object, default: null },
    lines: Array,
    canReverse: Boolean,
    canRecordAnother: Boolean,
    history: { type: Array, default: null },
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const reversed = computed(() => props.document.status === 'reversed');
const noun = computed(() => props.document.type_label.toLowerCase());
const partyLabel = computed(() => ({ receipt: 'Received from', payment: 'Paid to', purchase: 'Bought from', sale: 'Sold to' })[props.document.type] ?? 'With');

const confirming = ref(false);
const reverseForm = useForm({ reason: '' });

function reverse() {
    reverseForm.post(route('documents.reverse', props.document.id), {
        preserveScroll: true,
        onSuccess: () => {
            confirming.value = false;
            reverseForm.reset();
        },
    });
}

const when = (iso) =>
    new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <Head :title="document.number" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold leading-tight text-gray-900">{{ document.type_label }} {{ document.number }}</h1>
                <span
                    class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium"
                    :class="reversed ? 'bg-gray-200 text-gray-900' : document.reverses ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-900'"
                >
                    {{ reversed ? 'Reversed' : document.reverses ? 'Reversal' : 'Saved' }}
                </span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- The end of a posting: a clear success message, then the receipt and the next step right below. -->
            <Banner v-if="success" variant="success">
                <p class="text-lg font-semibold">{{ success }}</p>
                <p v-if="!reversed && !document.reverses" class="mt-1">Print the receipt, or record the next one.</p>
            </Banner>

            <Banner v-if="reversed" variant="info">
                This {{ noun }} was reversed by
                <Link :href="route('documents.show', document.reversed_by.id)" class="font-medium underline">{{ document.reversed_by.number }}</Link>.
                Both stay on record.
            </Banner>
            <Banner v-if="document.reverses" variant="warning">
                This reverses
                <Link :href="route('documents.show', document.reverses.id)" class="font-medium underline">{{ document.reverses.number }}</Link>.
            </Banner>

            <!-- One main action, one quieter one, the rest under More. -->
            <div class="flex flex-wrap items-center gap-3">
                <ButtonLink :href="route('documents.print', { document: document.id, layout: 'a4' })" external>Print {{ noun }}</ButtonLink>
                <ButtonLink v-if="canRecordAnother" :href="route(`${document.type}s.create`)" variant="secondary">Record another {{ noun }}</ButtonLink>
                <MoreMenu>
                    <MenuItem :href="route('documents.print', { document: document.id, layout: 'thermal' })" external>Print small receipt</MenuItem>
                    <MenuItem :href="route('documents.index')">All transactions</MenuItem>
                    <MenuItem v-if="canReverse" danger @click="confirming = true">Reverse this {{ noun }}</MenuItem>
                </MoreMenu>
            </div>

            <!-- The amount first: it is what people look for. -->
            <section class="card p-6">
                <p class="text-base text-gray-700">Amount</p>
                <p class="mt-1 text-4xl font-semibold tabular-nums text-gray-900">{{ document.total }}</p>
                <p class="mt-1 text-base text-gray-700">{{ document.total_in_words }}</p>

                <dl class="mt-6 grid gap-x-6 gap-y-4 text-base sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-700">{{ partyLabel }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">
                            <Link v-if="document.party" :href="route('ledgers.statement', document.party.id)" class="text-teal-800 hover:underline">{{ document.party.name }}</Link>
                        </dd>
                        <dd v-if="party_balance" class="text-sm text-gray-700">Now: {{ party_balance }}</dd>
                    </div>
                    <div v-if="document.cash">
                        <dt class="text-gray-700">{{ document.type === 'receipt' ? 'Paid into' : 'Paid from' }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ document.cash.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-700">Date</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ day(document.date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-700">Office</dt>
                        <dd class="mt-1 font-medium text-gray-900">{{ document.office }}</dd>
                    </div>
                    <div v-if="document.note" class="sm:col-span-2">
                        <dt class="text-gray-700">Note</dt>
                        <dd class="mt-1 text-gray-900">{{ document.note }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-700">Recorded by</dt>
                        <dd class="mt-1 text-gray-900">{{ document.recorded_by ?? 'Unknown' }}</dd>
                    </div>
                </dl>
            </section>

            <!-- The weighing, for a purchase or a sale. The working out is folded away. -->
            <section v-if="gold" aria-labelledby="gold-heading" class="card p-6">
                <h2 id="gold-heading" class="text-lg font-semibold text-gray-900">The gold</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-base sm:grid-cols-4">
                    <div><dt class="text-gray-700">Form</dt><dd class="mt-1 font-medium capitalize text-gray-900">{{ gold.form }}</dd></div>
                    <div><dt class="text-gray-700">Weight</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.weight }} g</dd></div>
                    <div><dt class="text-gray-700">Karat</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.karat }}</dd></div>
                    <div><dt class="text-gray-700">Pounds</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.pounds }}</dd></div>
                    <div class="col-span-2 sm:col-span-4"><dt class="text-gray-700">Price per pound at {{ gold.base_karat }} karat</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.price }}</dd></div>
                </dl>

                <Banner v-if="profit" :variant="profit.is_loss ? 'error' : 'success'" class="mt-4">
                    This gold had cost {{ profit.cost }}. {{ profit.is_loss ? 'The sale made a loss of' : 'The sale made a profit of' }}
                    <span class="font-semibold">{{ profit.profit }}</span>.
                </Banner>

                <details class="mt-4">
                    <summary class="flex min-h-12 cursor-pointer items-center text-base font-medium text-gray-900">How it was worked out</summary>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 pb-2 text-base sm:grid-cols-3">
                        <div v-if="gold.water_weight"><dt class="text-gray-700">Water weight</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.water_weight }} g</dd></div>
                        <div v-if="gold.density"><dt class="text-gray-700">Density</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.density }}</dd></div>
                        <div><dt class="text-gray-700">Formulas version</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.formula_version }}</dd></div>
                    </dl>
                </details>
            </section>

            <!-- Details are one click away, not in the way. -->
            <details class="card px-6 py-2">
                <summary class="flex min-h-12 cursor-pointer items-center text-base font-medium text-gray-900">Accounting entries</summary>
                <div class="overflow-x-auto pb-4">
                    <table class="data-table">
                        <thead>
                            <tr><th scope="col">Ledger</th><th scope="col" class="num">Debit</th><th scope="col" class="num">Credit</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in lines" :key="line.id">
                                <td>{{ line.ledger }}</td>
                                <td class="num">{{ line.debit }}</td>
                                <td class="num">{{ line.credit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>

            <details v-if="history && history.length" class="card px-6 py-2">
                <summary class="flex min-h-12 cursor-pointer items-center text-base font-medium text-gray-900">History</summary>
                <ul class="space-y-3 pb-4 text-base">
                    <li v-for="entry in history" :key="entry.id">
                        <span class="font-semibold">{{ entry.actor }}</span> {{ entry.description }}
                        <span class="block text-sm text-gray-700">{{ when(entry.when) }}</span>
                    </li>
                </ul>
            </details>
        </div>

        <ConfirmDialog
            :show="confirming"
            :title="`Reverse ${document.number}?`"
            :confirm-label="`Reverse ${noun}`"
            danger
            :processing="reverseForm.processing"
            @close="confirming = false"
            @confirm="reverse"
        >
            <p>
                A saved {{ noun }} cannot be edited. Reversing posts the opposite entry, so the books are put right and the history stays honest.
                The original stays on record.
            </p>
            <div>
                <InputLabel for="reason" value="Why are you reversing it?" />
                <TextInput id="reason" v-model="reverseForm.reason" maxlength="200" class="mt-1 block w-full" placeholder="For example: wrong amount entered" />
                <InputError class="mt-2" :message="reverseForm.errors.reason" />
            </div>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
