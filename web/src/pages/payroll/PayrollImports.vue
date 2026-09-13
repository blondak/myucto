<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'
import RegistrationImportPanel from '@/components/payroll/imports/RegistrationImportPanel.vue'
import AttendanceImportPanel from '@/components/payroll/imports/AttendanceImportPanel.vue'
import AttendanceMappingPanel from '@/components/payroll/imports/AttendanceMappingPanel.vue'
import { createAttendanceWorkspace, provideAttendanceWorkspace } from '@/components/payroll/imports/attendanceWorkspace'

type Tab = 'registration' | 'attendance' | 'mapping'
const TABS: readonly Tab[] = ['registration', 'attendance', 'mapping']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

function tabFromQuery(): Tab {
  const requested = payrollQueryValue(route.query, 'tab')
  // Starší odkazy nesly množné číslo.
  if (requested === 'registrations') return 'registration'
  return requested !== null && (TABS as readonly string[]).includes(requested) ? requested as Tab : 'registration'
}

const activeTab = ref<Tab>(tabFromQuery())

const canWriteInputs = computed(() => auth.canWrite('payroll.inputs.write'))
const canWritePersons = computed(() => auth.canWrite('payroll.person.write'))
const canManageProfiles = computed(() => auth.canWrite('payroll.settings'))

// Mapování se nastavuje jednou, import běží měsíčně — obě záložky ale pracují
// se stejnými soubory a profily, proto sdílený stav místo dvojího nahrávání.
const workspace = createAttendanceWorkspace({
  onOpenMapping: () => {
    activeTab.value = 'mapping'
    window.scrollTo({ top: 0, behavior: 'smooth' })
  },
  errorMessage: error => apiErrorMessage(error, t('payroll_imports.profiles_load_failed')),
})
provideAttendanceWorkspace(workspace)

// Záložka v adrese, ať jde na import odkázat a po obnovení stránky se neztratí.
watch(activeTab, tab => {
  if (payrollQueryValue(route.query, 'tab') === tab) return
  void router.replace({ query: { ...route.query, tab } })
})
watch(() => route.query.tab, () => {
  const tab = tabFromQuery()
  if (tab !== activeTab.value) activeTab.value = tab
})

onMounted(() => { void workspace.loadProfiles() })
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll_imports.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.subtitle') }}</p>
    </header>

    <nav class="flex flex-wrap gap-1 border-b border-neutral-200" :aria-label="t('payroll_imports.tabs.label')">
      <button
        v-for="tab in TABS"
        :key="tab"
        type="button"
        :data-testid="`payroll-imports-tab-${tab}`"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        :aria-current="activeTab === tab ? 'page' : undefined"
        @click="activeTab = tab"
      >
        {{ t(`payroll_imports.tabs.${tab}`) }}
      </button>
    </nav>

    <!-- v-show: rozpracovaný průvodce ani neuložený profil nesmí zmizet jen kvůli přepnutí záložky. -->
    <RegistrationImportPanel v-show="activeTab === 'registration'" :can-write="canWritePersons" />
    <AttendanceImportPanel
      v-show="activeTab === 'attendance'"
      :can-write="canWriteInputs"
      :can-create-persons="canWritePersons"
    />
    <AttendanceMappingPanel
      v-show="activeTab === 'mapping'"
      :can-manage="canManageProfiles"
      :can-preview="canWriteInputs"
    />
  </div>
</template>
