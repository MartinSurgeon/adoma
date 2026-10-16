<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BranchForm from '@/Components/BranchForm.vue';

defineProps({
    headOffice: { type: String, default: null },
    codes: { type: Array, default: () => [] },
});
</script>

<template>
    <Head title="Add a branch" />

    <AuthenticatedLayout
        title="Add a branch"
        subtitle="Next, you choose who works there and in what role."
        :back="{ href: route('branches.index'), label: 'Branches' }"
        width="3xl"
    >
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <BranchForm
                :initial="{ name: '', code: '', region: '', is_head_office: false }"
                method="post"
                :action="route('branches.store')"
                :cancel-href="route('branches.index')"
                submit-label="Add branch"
                :head-office="headOffice"
                :codes="codes"
            />
        </div>
    </AuthenticatedLayout>
</template>
