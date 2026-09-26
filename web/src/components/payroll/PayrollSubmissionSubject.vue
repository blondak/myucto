<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * „Koho se podání týká" v přehledu a inboxu podání. Když je předmětem
 * pracovní vztah, vede jméno na kartu zaměstnance (stejně jako fronta podání).
 */
const props = defineProps<{
  label: string | null
  employeeId?: number | null
}>()

const { t } = useI18n()

const personLink = computed(() =>
  props.employeeId
    ? { name: 'payroll-people', query: { person: String(props.employeeId) } }
    : null,
)
</script>

<template>
  <RouterLink
    v-if="personLink"
    :to="personLink"
    class="text-primary-700 hover:underline"
    data-test="submission-subject-link"
  >
    {{ label ?? t('payroll.submissions.subject.open_person') }}
  </RouterLink>
  <span v-else-if="label">{{ label }}</span>
</template>
