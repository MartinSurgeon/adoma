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
    transfer: { type: Object, default: null },
    lots_sold: { type: Array, default: () => [] },
    lines: Array,
    canReverse: Boolean,
    canRecordAnother: Boolean,
    history: { type: Array, default: null },
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const reversed = computed(() => props.document.status === 'reversed');
const noun = computed(() => props.document.type_label.toLowerCase());
const partyLabel = computed(() => ({ receipt: 'Received from', payment: 'Paid to', purchase: 'Bought from', sale: 'Sold to', transfer: 'Sent to', transfer_in: 'Received from' })[props.document.type] ?? 'With');

// A transfer nobody has accepted yet is cancelled, not reversed: there is nothing on the other side to undo.
const undoWord = computed(() => (props.transfer?.state === 'waiting' ? 'Cancel' : 'Reverse'));

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

    <AuthenticatedLayout
        :title="`${document.type_label} ${document.number}`"
        :back="{ href: route('documents.index'), label: 'Transactions' }"
        width="3xl"
    >
        <template #status>
            <span
                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-sm font-medium"
                    :class="reversed ? 'bg-gray-200 text-gray-800' : document.reverses ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-900'"
                >
                    <svg v-if="reversed" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                    </svg>
                    <svg v-else-if="document.reverses" class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M7.707 3.293a1 1 0 010 1.414L5.414 7H11a7 7 0 017 7v2a1 1 0 11-2 0v-2a5 5 0 00-5-5H5.414l2.293 2.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <svg v-else class="h-3.5 w-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                    </svg>
                    {{ reversed ? 'Reversed' : document.reverses ? 'Reversal' : 'Saved' }}
            </span>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <!-- The end of a posting: a clear success message, then the receipt and the next step right below. -->
            <Banner v-if="success" variant="success">
                <p class="text-lg font-semibold">{{ success }}</p>
                <p v-if="!reversed && !document.reverses" class="mt-1">Print the receipt, or record another. It starts with the same person and details, and you fill in the amounts.</p>
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
                <ButtonLink :href="route('documents.print', { document: document.id, layout: 'a4' })">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v8H6v-8z" />
                    </svg>
                    Print {{ noun }}
                </ButtonLink>
                <ButtonLink v-if="canRecordAnother" :href="route(`${document.type}s.create`, { like: document.id })" variant="secondary">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 5v14M5 12h14" />
                    </svg>
                    Record another {{ noun }}
                </ButtonLink>
                <MoreMenu>
                    <MenuItem v-if="canRecordAnother" :href="route(`${document.type}s.create`)">Start a blank {{ noun }}</MenuItem>
                    <MenuItem :href="route('documents.print', { document: document.id, layout: 'thermal' })">Print small receipt</MenuItem>
                    <MenuItem v-if="canReverse" danger @click="confirming = true">{{ undoWord }} this {{ noun }}</MenuItem>
                </MoreMenu>
            </div>

            <!-- The amount first: it is what people look for. -->
            <section class="card p-6">
                <p class="text-sm font-medium uppercase tracking-wider text-gray-500">Amount</p>
                <p class="mt-1 text-4xl font-bold tabular-nums text-gray-900">{{ document.total }}</p>
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
                        <dt class="text-gray-700">Branch</dt>
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

            <!-- A transfer: what moved, and the other half in the other branch's books. -->
            <section v-if="transfer" aria-labelledby="transfer-heading" class="card p-6">
                <h2 id="transfer-heading" class="text-lg font-semibold text-gray-900">The gold</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-base">
                    <div>
                        <dt class="text-gray-700">Weight</dt>
                        <dd class="mt-1 font-medium tabular-nums text-gray-900">{{ transfer.weight }} g</dd>
                    </div>
                    <div v-if="transfer.other_number">
                        <dt class="text-gray-700">{{ transfer.direction === 'out' ? 'Arrived as' : 'Sent as' }}</dt>
                        <dd class="mt-1 font-medium text-gray-900">
                            <Link v-if="transfer.other_id" :href="route('documents.show', transfer.other_id)" class="text-teal-800 hover:underline">{{ transfer.other_number }}</Link>
                            <span v-else>{{ transfer.other_number }}</span>
                            <span v-if="transfer.other_office" class="text-gray-700"> in {{ transfer.other_office }}</span>
                        </dd>
                    </div>
                </dl>
                <Banner v-if="transfer.state === 'waiting'" variant="warning" class="mt-4">
                    <p class="font-semibold">Waiting for {{ transfer.other_office }} to accept this gold</p>
                    <p class="mt-1">
                        Until they do, the gold is in nobody's stock. If they decline, or you cancel, it goes back to your stock.
                    </p>
                </Banner>
                <p v-else class="mt-4 text-base text-gray-700">
                    {{ transfer.direction === 'out'
                        ? 'This was a sale to the other branch. They owe the amount above until it is settled with a payment.'
                        : 'This was bought from the other branch. You owe the amount above until it is settled with a payment.' }}
                    <template v-if="transfer.direction === 'in'">To put this right, the sending branch reverses its transfer, which undoes both halves.</template>
                </p>

                <Banner v-if="profit" :variant="profit.is_loss ? 'error' : 'success'" class="mt-4">
                    This gold had cost {{ profit.cost }}. {{ profit.is_loss ? 'The sale made a loss of' : 'The sale made a profit of' }}
                    <span class="font-semibold">{{ profit.profit }}</span>.
                </Banner>
            </section>

            <!-- The weighing, for a purchase or a sale. The working out is folded away. -->
            <section v-if="gold" aria-labelledby="gold-heading" class="card p-6">
                <h2 id="gold-heading" class="text-lg font-semibold text-gray-900">The gold</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-4 text-base sm:grid-cols-4">
                    <div><dt class="text-gray-700">Form</dt><dd class="mt-1 font-medium capitalize text-gray-900">{{ gold.form }}</dd></div>
                    <div><dt class="text-gray-700">Weight</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.weight }} g</dd></div>
                    <div><dt class="text-gray-700">Karat</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.karat }}</dd></div>
                    <div v-if="gold.paid_karat"><dt class="whitespace-nowrap text-gray-700">Karat (&minus;{{ gold.karat_deduction }})</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.paid_karat }}</dd></div>
                    <div><dt class="text-gray-700">Purity</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.purity }}%</dd></div>
                    <div><dt class="text-gray-700">Pounds</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.pounds }}</dd></div>
                    <div class="col-span-2 sm:col-span-4"><dt class="text-gray-700">Price per pound at {{ gold.base_karat }} karat</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.price }}</dd></div>
                </dl>

                <Banner v-if="gold.karat_override" variant="warning" class="mt-4">
                    <p class="font-semibold">Karat above 24, allowed by {{ gold.karat_override.by ?? 'an admin' }}</p>
                    <p class="mt-1">Reason: {{ gold.karat_override.reason }}</p>
                </Banner>

                <Banner v-if="profit" :variant="profit.is_loss ? 'error' : 'success'" class="mt-4">
                    This gold had cost {{ profit.cost }}. {{ profit.is_loss ? 'The sale made a loss of' : 'The sale made a profit of' }}
                    <span class="font-semibold">{{ profit.profit }}</span>.
                </Banner>

                <details class="mt-4">
                    <summary class="flex min-h-12 cursor-pointer items-center text-base font-medium text-gray-900">How it was worked out</summary>
                    <dl class="grid grid-cols-2 gap-x-6 gap-y-4 pb-2 text-base sm:grid-cols-3">
                        <div v-if="gold.big_weight"><dt class="text-gray-700">Without particles</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.big_weight }} g</dd></div>
                        <div v-if="gold.water_weight"><dt class="text-gray-700">Water weight</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.water_weight }} g</dd></div>
                        <div v-if="gold.density"><dt class="text-gray-700">Density</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.density }}</dd></div>
                        <div><dt class="text-gray-700">Formulas version</dt><dd class="mt-1 font-medium tabular-nums text-gray-900">{{ gold.formula_version }}</dd></div>
                    </dl>
                </details>
            </section>

            <!-- Which purchases a sale's gold came from. -->
            <section v-if="lots_sold?.length" aria-labelledby="lots-heading" class="table-card overflow-x-auto">
                <div class="table-title">
                    <h2 id="lots-heading" class="text-lg font-semibold text-gray-900">Gold taken from</h2>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Purchase</th><th scope="col">Bought</th><th scope="col">Form</th>
                            <th scope="col" class="num">Karat</th><th scope="col" class="num">Weight (g)</th>
                            <th v-if="lots_sold.some((l) => l.amount)" scope="col" class="num">Sold for</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(lot, i) in lots_sold" :key="i">
                            <td>
                                <Link v-if="lot.origin_id" :href="route('documents.show', lot.origin_id)" class="ref inline-flex min-h-12 items-center font-medium text-teal-800 underline underline-offset-4">{{ lot.origin_number }}</Link>
                                <span v-else>—</span>
                                <span v-if="lot.origin_name" class="text-gray-700"> · {{ lot.origin_name }}</span>
                            </td>
                            <td class="whitespace-nowrap">{{ lot.date }}</td>
                            <td>{{ lot.form }}</td>
                            <td class="num">{{ lot.karat }}</td>
                            <td class="num">{{ lot.weight }}</td>
                            <td v-if="lots_sold.some((l) => l.amount)" class="num">{{ lot.amount }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- Details are one click away, not in the way. -->
            <details class="table-card">
                <summary class="flex min-h-12 cursor-pointer items-center px-6 text-base font-medium text-gray-900">Accounting entries</summary>
                <div class="overflow-x-auto border-t border-gray-200">
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
            :title="`${undoWord} ${document.number}?`"
            :confirm-label="`${undoWord} ${noun}`"
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
