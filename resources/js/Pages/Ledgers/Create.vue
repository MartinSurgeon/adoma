<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LedgerForm from '@/Components/LedgerForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    kinds: Array,
    canShare: Boolean,
    chooseOffice: Boolean,
    activeOffice: String,
    branches: Array,
});
</script>

<template>
    <Head title="Add a ledger" />

    <AuthenticatedLayout
        title="Add a ledger"
        subtitle="A customer, supplier, bank or cost you deal with."
        :back="{ href: route('ledgers.index'), label: 'Ledgers' }"
        width="3xl"
    >
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <LedgerForm
                :kinds="kinds"
                :can-share="canShare"
                :choose-office="chooseOffice"
                :active-office="activeOffice"
                :branches="branches"
                method="post"
                :action="route('ledgers.store')"
                submit-label="Add ledger"
            />
        </div>
    </AuthenticatedLayout>
</template>
