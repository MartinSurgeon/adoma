<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Banner from '@/Components/Banner.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import MenuItem from '@/Components/MenuItem.vue';
import MoreMenu from '@/Components/MoreMenu.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    office: String,
    transfers: Array,
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const problem = computed(() => page.props.errors?.transfer);

const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

// One dialog for both answers: { kind: 'accept' | 'decline', transfer }
const answering = ref(null);
const working = ref(false);
const declineForm = useForm({ reason: '' });

function close() {
    answering.value = null;
    declineForm.reset();
    declineForm.clearErrors();
}

function confirm() {
    const { kind, transfer } = answering.value;
    working.value = true;

    if (kind === 'accept') {
        router.post(route('transfers.accept', transfer.id), {}, { onFinish: () => { working.value = false; answering.value = null; } });
    } else {
        declineForm.post(route('transfers.decline', transfer.id), {
            onSuccess: () => close(),
            onFinish: () => { working.value = false; },
        });
    }
}
</script>

<template>
    <Head title="Incoming gold" />

    <AuthenticatedLayout
        title="Incoming gold"
        :subtitle="`Sent to ${office}. It joins your stock when you accept it.`"
        width="5xl"
    >
        <div class="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
            <Banner v-if="success" variant="success">{{ success }}</Banner>
            <Banner v-if="problem" variant="error">{{ problem }}</Banner>

            <ul v-if="transfers.length" class="space-y-4">
                <li v-for="transfer in transfers" :key="transfer.id" class="card p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <p class="text-lg font-semibold text-gray-900">From {{ transfer.from }}</p>
                            <p class="mt-1 text-base text-gray-700">
                                <span class="tabular-nums">{{ transfer.number }}</span> · sent {{ day(transfer.date) }}
                            </p>
                            <p v-if="transfer.note" class="mt-1 text-base text-gray-700">{{ transfer.note }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-3xl font-semibold tabular-nums text-gray-900">{{ transfer.weight }} <span class="text-base font-normal text-gray-700">g</span></p>
                            <p class="mt-1 text-base tabular-nums text-gray-700">You will owe {{ transfer.amount }}</p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <PrimaryButton type="button" @click="answering = { kind: 'accept', transfer }">Accept this gold</PrimaryButton>
                        <MoreMenu :label="`More for ${transfer.number}`">
                            <MenuItem danger @click="answering = { kind: 'decline', transfer }">Decline</MenuItem>
                        </MoreMenu>
                    </div>
                </li>
            </ul>

            <div v-else class="card p-10 text-center">
                <p class="text-lg font-semibold text-gray-900">Nothing is waiting for you</p>
                <p class="mt-1 text-base text-gray-700">When another branch sends gold to {{ office }}, it shows here.</p>
            </div>
        </div>

        <ConfirmDialog
            :show="answering !== null"
            :title="answering?.kind === 'accept' ? `Accept ${answering?.transfer.weight} g from ${answering?.transfer.from}?` : `Decline ${answering?.transfer.number}?`"
            :confirm-label="answering?.kind === 'accept' ? 'Accept and add to stock' : 'Decline'"
            :danger="answering?.kind === 'decline'"
            :processing="working"
            @close="close"
            @confirm="confirm"
        >
            <template v-if="answering?.kind === 'accept'">
                <p>
                    Check that the gold has arrived and the weight matches. Accepting buys it for {{ answering.transfer.amount }}: it joins
                    {{ office }}'s stock at that cost, and {{ office }} will owe {{ answering.transfer.from }} that amount until it is settled.
                </p>
            </template>
            <template v-else-if="answering">
                <p>The gold goes back to {{ answering.transfer.from }}'s stock and nothing changes here. They will see your reason.</p>
                <div>
                    <InputLabel for="reason" value="Why are you declining it?" />
                    <TextInput id="reason" v-model="declineForm.reason" maxlength="200" class="mt-1 block w-full" placeholder="For example: weight does not match" />
                    <InputError class="mt-2" :message="declineForm.errors.reason" />
                </div>
            </template>
        </ConfirmDialog>
    </AuthenticatedLayout>
</template>
