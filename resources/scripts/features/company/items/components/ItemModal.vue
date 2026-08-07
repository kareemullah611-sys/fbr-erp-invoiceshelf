<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useModalStore } from '../../../../stores/modal.store'
import { useCompanyStore } from '../../../../stores/company.store'
import { useUserStore } from '../../../../stores/user.store'
import { useGlobalStore } from '@/scripts/stores/global.store'
import { fbrReferenceService } from '@/scripts/api/services/fbr-reference.service'
import type { FbrHsCodeOption } from '@/scripts/api/services/fbr-reference.service'
import { useItemStore } from '../store'
import { useTaxTypes } from '../use-tax-types'
import ItemUnitModal from '@/scripts/features/company/settings/components/ItemUnitModal.vue'
import { useNotificationStore } from '../../../../stores/notification.store'
import {
  handleApiError,
  getErrorTranslationKey,
} from '../../../../utils/error-handling'
import type { TaxType } from '@/scripts/types/domain/tax'

interface TaxOption {
  id: number
  name: string
  percent: number
  fixed_amount: number
  calculation_type: string | null
  tax_name: string
}

interface ItemFormState {
  name: string
  description: string
  price: number
  unit_id: string | number | null
  taxes: TaxOption[]
  fbr_hs_code: string | null
  fbr_uom: string | null
  fbr_sale_type: string | null
  fbr_sro_no: string | null
  fbr_sro_item_no: string | null
}

const ABILITIES = {
  VIEW_TAX_TYPE: 'view-tax-type',
} as const

interface Emits {
  (e: 'newItem', item: unknown): void
}

const emit = defineEmits<Emits>()

const modalStore = useModalStore()
const itemStore = useItemStore()
const companyStore = useCompanyStore()
const userStore = useUserStore()
const globalStore = useGlobalStore()
const notificationStore = useNotificationStore()
const { taxTypes, fetchTaxTypes } = useTaxTypes()

const { t } = useI18n()
const isLoading = ref<boolean>(false)
const triedSubmit = ref<boolean>(false)
const hsCodeSelect = ref<FbrHsCodeOption | null>(null)
const hsCodeLoading = ref<boolean>(false)
const uomOptions = ref<string[]>([])
const fbrLoading = ref<boolean>(false)

const saleTypeOptions = computed(() =>
  globalStore.fbrReference.sale_types.map((value) => ({ value, label: value })),
)

async function searchHsCodes(search: string): Promise<FbrHsCodeOption[]> {
  hsCodeLoading.value = true
  try {
    return await fbrReferenceService.searchHsCodes(search)
  } catch {
    return []
  } finally {
    hsCodeLoading.value = false
  }
}

async function loadUomOptions(): Promise<void> {
  fbrLoading.value = true
  try {
    uomOptions.value = await fbrReferenceService.getUoms()
  } catch {
    uomOptions.value = []
  } finally {
    fbrLoading.value = false
  }
}

function onSelectHsCode(option: FbrHsCodeOption | null): void {
  hsCodeSelect.value = option
  form.fbr_hs_code = option ? option.hs_code : null
  if (option?.uoms?.[0]) {
    form.fbr_uom = option.uoms[0].toUpperCase()
  }
  if (option) {
    applyReducedRateAutoSelect(option.hs_code)
  }
}

function onSelectUom(value: string | null): void {
  form.fbr_uom = (value ?? '').trim().toUpperCase() || null
}

function onSelectSaleType(value: string | null): void {
  form.fbr_sale_type = value?.trim() || null
}

function applyReducedRateAutoSelect(hsCode: string): void {
  const entry = globalStore.fbrReference.reduced_rate_hs[hsCode]
  if (!entry) return
  form.fbr_sale_type = 'Goods at Reduced Rate'
  form.fbr_sro_no = entry.sroScheduleNo
  form.fbr_sro_item_no = entry.sroItemSerialNo
}

const taxPerItemSetting = ref<string>(
  companyStore.selectedCompanySettings.tax_per_item || 'NO'
)

const modalActive = computed<boolean>(
  () => modalStore.active && modalStore.componentName === 'ItemModal'
)

// Local form state owned by this modal. ItemModal is permanently mounted (inside
// DocumentItemsTable, via BaseModal's `static` dialog); @vuelidate did not track
// this reactive form's values in that context (it kept validating an empty
// snapshot), so validation here is done with plain reactive computeds instead —
// the same reactivity the price/taxes computeds below already rely on.
const form = reactive<ItemFormState>({
  name: '',
  description: '',
  price: 0,
  unit_id: '',
  taxes: [],
  fbr_hs_code: null,
  fbr_uom: null,
  fbr_sale_type: null,
  fbr_sro_no: null,
  fbr_sro_item_no: null,
})

const nameError = computed<string>(() => {
  const value = (form.name ?? '').trim()
  if (!value) {
    return t('validation.required')
  }
  if (value.length < 3) {
    return t('validation.name_min_length', { count: 3 })
  }
  return ''
})

const descriptionError = computed<string>(() => {
  if ((form.description ?? '').length > 255) {
    return t('validation.description_maxlength', { count: 255 })
  }
  return ''
})

const isFormValid = computed<boolean>(
  () => !nameError.value && !descriptionError.value
)

const price = computed<number>({
  get: () => form.price / 100,
  set: (value: number) => {
    form.price = Math.round(value * 100)
  },
})

const taxes = computed<TaxOption[]>({
  get: () =>
    form.taxes?.map((tax) => {
      const currencySymbol = companyStore.selectedCompanyCurrency?.symbol ?? '$'
      return {
        ...tax,
        tax_type_id: tax.id,
        tax_name: `${tax.name} (${
          tax.calculation_type === 'fixed'
            ? tax.fixed_amount + currencySymbol
            : tax.percent + '%'
        })`,
      }
    }) ?? [],
  set: (value: TaxOption[]) => {
    form.taxes = value
  },
})

const isTaxPerItemEnabled = computed<boolean>(() => {
  return taxPerItemSetting.value === 'YES'
})

const getTaxTypes = computed<TaxOption[]>(() => {
  return taxTypes.value.map((tax: TaxType) => {
    const currencyCode = companyStore.selectedCompanyCurrency?.code ?? 'USD'
    const amount =
      tax.calculation_type === 'fixed'
        ? new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currencyCode,
          }).format(tax.fixed_amount / 100)
        : `${tax.percent}%`

    return {
      ...tax,
      tax_name: `${tax.name} (${amount})`,
    }
  }) as TaxOption[]
})

// Reset + prefill the form every time the modal opens. The typed item-search
// text is handed in via modalStore.data.name by BaseItemSelect.
watch(modalActive, (active) => {
  if (!active) {
    return
  }
  const data = modalStore.data as { name?: string } | null
  Object.assign(form, {
    name: data?.name ?? '',
    description: '',
    price: 0,
    unit_id: '',
    taxes: [],
    fbr_hs_code: null,
    fbr_uom: null,
    fbr_sale_type: null,
    fbr_sro_no: null,
    fbr_sro_item_no: null,
  })
  hsCodeSelect.value = null
  triedSubmit.value = false
})

onMounted(async () => {
  await itemStore.fetchItemUnits({ limit: 'all' })

  if (userStore.hasAbilities(ABILITIES.VIEW_TAX_TYPE)) {
    await fetchTaxTypes()
  }
})

async function submitItemData(): Promise<void> {
  triedSubmit.value = true

  if (!isFormValid.value) {
    notificationStore.showNotification({
      type: 'error',
      message: nameError.value || descriptionError.value,
    })
    return
  }

  const data: Record<string, unknown> = {
    name: form.name,
    description: form.description,
    price: form.price,
    unit_id: form.unit_id || null,
    fbr_hs_code: form.fbr_hs_code,
    fbr_uom: form.fbr_uom,
    fbr_sale_type: form.fbr_sale_type,
    fbr_sro_no: form.fbr_sro_no,
    fbr_sro_item_no: form.fbr_sro_item_no,
    taxes: (form.taxes ?? []).map((tax) => ({
      tax_type_id: tax.id,
      amount:
        tax.calculation_type === 'fixed'
          ? tax.fixed_amount
          : Math.round((form.price / 100) * tax.percent),
      percent: tax.percent,
      fixed_amount: tax.fixed_amount,
      calculation_type: tax.calculation_type,
      name: tax.name,
      collective_tax: 0,
    })),
  }

  isLoading.value = true

  try {
    const res = await itemStore.addItem(data)
    isLoading.value = false
    if (res.data && modalStore.refreshData) {
      modalStore.refreshData(res.data)
    }
    closeItemModal()
  } catch (err: unknown) {
    isLoading.value = false
    const normalized = handleApiError(err)
    const translationKey = getErrorTranslationKey(normalized.message)
    notificationStore.showNotification({
      type: 'error',
      message: translationKey ? t(translationKey) : normalized.message,
    })
  }
}

function addItemUnit(): void {
  modalStore.openModal({
    title: t('settings.customization.items.add_item_unit'),
    componentName: 'ItemUnitModal',
    size: 'sm',
    refreshData: (unit: { id: number }) => {
      form.unit_id = unit.id
    },
  })
}

function closeItemModal(): void {
  modalStore.closeModal()
  setTimeout(() => {
    triedSubmit.value = false
  }, 300)
}
</script>

<template>
  <BaseModal :show="modalActive" @close="closeItemModal">
    <template #header>
      <div class="flex justify-between w-full">
        {{ modalStore.title }}
        <BaseIcon
          name="XMarkIcon"
          class="h-6 w-6 text-muted cursor-pointer"
          @click="closeItemModal"
        />
      </div>
    </template>
    <div class="item-modal">
      <form action="" @submit.prevent="submitItemData">
        <div class="px-8 py-8 sm:p-6">
          <BaseInputGrid layout="one-column">
            <BaseInputGroup
              :label="$t('items.name')"
              required
              :error="triedSubmit ? nameError : ''"
            >
              <BaseInput
                v-model="form.name"
                type="text"
                :invalid="Boolean(triedSubmit && nameError)"
              />
            </BaseInputGroup>

            <BaseInputGroup :label="$t('items.price')">
              <BaseMoney
                :key="companyStore.selectedCompanyCurrency?.id"
                v-model="price"
                :currency="companyStore.selectedCompanyCurrency"
                class="
                  relative
                  w-full
                  focus:border focus:border-solid focus:border-primary
                "
              />
            </BaseInputGroup>

            <BaseInputGroup :label="$t('items.unit')">
              <BaseMultiselect
                v-model="form.unit_id"
                label="name"
                :options="itemStore.itemUnits"
                value-prop="id"
                :can-deselect="false"
                :can-clear="false"
                :placeholder="$t('items.select_a_unit')"
                searchable
                track-by="name"
              >
                <template #action>
                  <BaseSelectAction @click="addItemUnit">
                    <BaseIcon
                      name="PlusCircleIcon"
                      class="h-4 mr-2 -ml-2 text-center text-primary-400"
                    />
                    {{ $t('settings.customization.items.add_item_unit') }}
                  </BaseSelectAction>
                </template>
              </BaseMultiselect>
              <ItemUnitModal />
            </BaseInputGroup>

            <BaseInputGroup
              v-if="isTaxPerItemEnabled"
              :label="$t('items.taxes')"
            >
              <BaseMultiselect
                v-model="taxes"
                :options="getTaxTypes"
                mode="tags"
                label="tax_name"
                value-prop="id"
                class="w-full"
                :can-deselect="false"
                :can-clear="false"
                searchable
                track-by="tax_name"
                object
              />
            </BaseInputGroup>

            <BaseInputGroup
              :label="$t('items.description')"
              :error="triedSubmit ? descriptionError : ''"
            >
              <BaseTextarea
                v-model="form.description"
                rows="4"
                cols="50"
                :invalid="Boolean(triedSubmit && descriptionError)"
              />
            </BaseInputGroup>

            <div class="border border-line-light rounded-md p-5">
              <div class="mb-4">
                <h3 class="text-lg font-semibold text-heading">
                  FBR Digital Invoice Details
                </h3>
                <p class="text-sm text-muted">
                  Used as defaults when this item is added to a sale invoice.
                </p>
              </div>

              <BaseInputGrid layout="one-column">
                <BaseInputGroup label="HS Code">
                  <BaseMultiselect
                    v-model="hsCodeSelect"
                    :content-loading="hsCodeLoading"
                    :options="searchHsCodes"
                    value-prop="hs_code"
                    track-by="hs_code"
                    label="description"
                    :filter-results="false"
                    searchable
                    :delay="500"
                    preserve-search
                    object
                    :placeholder="'8311.1000'"
                    @update:model-value="onSelectHsCode"
                  />
                </BaseInputGroup>

                <BaseInputGroup label="FBR UOM">
                  <BaseMultiselect
                    v-model="form.fbr_uom"
                    :content-loading="fbrLoading"
                    :options="uomOptions"
                    searchable
                    :filter-results="true"
                    :delay="300"
                    can-deselect
                    :placeholder="'NUMBERS, PIECES, UNITS'"
                    @update:model-value="onSelectUom"
                    @open="loadUomOptions"
                  />
                </BaseInputGroup>

                <BaseInputGroup label="Sale Type">
                  <BaseMultiselect
                    v-model="form.fbr_sale_type"
                    :options="saleTypeOptions"
                    value-prop="value"
                    label="label"
                    track-by="label"
                    :can-deselect="true"
                    :placeholder="$t('invoices.item.select_sale_type')"
                    @update:model-value="onSelectSaleType"
                  />
                </BaseInputGroup>
              </BaseInputGrid>
            </div>
          </BaseInputGrid>
        </div>
        <div
          class="z-0 flex justify-end p-4 border-t border-line-default border-solid"
        >
          <BaseButton
            class="mr-3"
            variant="primary-outline"
            type="button"
            @click="closeItemModal"
          >
            {{ $t('general.cancel') }}
          </BaseButton>
          <BaseButton
            :loading="isLoading"
            :disabled="isLoading"
            variant="primary"
            type="submit"
          >
            <template #left="slotProps">
              <BaseIcon name="ArrowDownOnSquareIcon" :class="slotProps.class" />
            </template>
            {{ $t('general.save') }}
          </BaseButton>
        </div>
      </form>
    </div>
  </BaseModal>
</template>
