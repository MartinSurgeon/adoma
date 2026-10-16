<script setup>
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { asking, settle } from '@/confirm';

// The one dialog behind confirm() (see confirm.js). Each layout draws it once.
</script>

<template>
    <ConfirmDialog
        :show="asking.open"
        :title="asking.title"
        :confirm-label="asking.confirmLabel"
        :cancel-label="asking.cancelLabel"
        :danger="asking.danger"
        :details="asking.details"
        @close="settle(false)"
        @confirm="settle(true)"
    >
        <p v-if="asking.body">{{ asking.body }}</p>
    </ConfirmDialog>
</template>
