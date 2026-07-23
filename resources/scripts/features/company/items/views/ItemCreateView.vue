<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  required,
  minLength,
  maxLength,
  helpers,
} from '@vuelidate/validators'
import useVuelidate from '@vuelidate/core'
import { useItemStore } from '../store'
import { useTaxTypes } from '../use-tax-types'
import { useCompanyStore } from '../../../../stores/company.store'
import { useModalStore } from '../../../../stores/modal.store'
import { useUserStore } from '../../../../stores/user.store'
import ItemUnitModal from '@/scripts/features/company/settings/components/ItemUnitModal.vue'
import type { TaxType } from '@/scripts/types/domain/tax'

interface TaxOption {
  id: number
  name: string
  percent: number
  fixed_amount: number
  calculation_type: string | null
  tax_type_id: number
  tax_name: string
}

const ABILITIES = {
  VIEW_TAX_TYPE: 'view-tax-type',
} as const

const itemStore = useItemStore()
const { taxTypes, fetchTaxTypes } = useTaxTypes()
const modalStore = useModalStore()
const companyStore = useCompanyStore()
const userStore = useUserStore()

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const isSaving = ref<boolean>(false)
const taxPerItem = ref<string>(companyStore.selectedCompanySettings.tax_per_item || 'NO')
const isFetchingInitialData = ref<boolean>(false)
const isEdit = computed<boolean>(() => route.name === 'items.edit')

itemStore.resetCurrentItem()
loadData()

const price = computed<number>({
  get: () => itemStore.currentItem.price / 100,
  set: (value: number) => {
    itemStore.currentItem.price = Math.round(value * 100)
  },
})

const fbrFixedNotifiedValue = computed<number>({
  get: () => (itemStore.currentItem.fbr_fixed_notified_value ?? 0) / 100,
  set: (value: number) => {
    itemStore.currentItem.fbr_fixed_notified_value = value ? Math.round(value * 100) : null
  },
})

const fbrSalesTaxWithheld = computed<number>({
  get: () => (itemStore.currentItem.fbr_sales_tax_withheld ?? 0) / 100,
  set: (value: number) => {
    itemStore.currentItem.fbr_sales_tax_withheld = value ? Math.round(value * 100) : null
  },
})

const fbrFurtherTax = computed<number>({
  get: () => (itemStore.currentItem.fbr_further_tax ?? 0) / 100,
  set: (value: number) => {
    itemStore.currentItem.fbr_further_tax = value ? Math.round(value * 100) : null
  },
})

const fbrExtraTax = computed<number>({
  get: () => (itemStore.currentItem.fbr_extra_tax ?? 0) / 100,
  set: (value: number) => {
    itemStore.currentItem.fbr_extra_tax = value ? Math.round(value * 100) : null
  },
})

const fbrFedPayable = computed<number>({
  get: () => (itemStore.currentItem.fbr_fed_payable ?? 0) / 100,
  set: (value: number) => {
    itemStore.currentItem.fbr_fed_payable = value ? Math.round(value * 100) : null
  },
})

const taxes = computed({
  get: () =>
    itemStore.currentItem.taxes?.map((tax) => {
      if (tax) {
        const currencyCode = companyStore.selectedCompanyCurrency?.code ?? 'USD'
        return {
          ...tax,
          tax_type_id: tax.id,
          tax_name: `${tax.name} (${
            tax.calculation_type === 'fixed'
              ? new Intl.NumberFormat(undefined, {
                  style: 'currency',
                  currency: currencyCode,
                }).format(tax.fixed_amount / 100)
              : `${tax.percent}%`
          })`,
        }
      }
      return tax
    }) ?? [],
  set: (value: TaxOption[]) => {
    itemStore.currentItem.taxes = value as unknown as typeof itemStore.currentItem.taxes
  },
})

const pageTitle = computed<string>(() =>
  isEdit.value ? t('items.edit_item') : t('items.new_item')
)

const getTaxTypes = computed<TaxOption[]>(() => {
  return taxTypes.value.map((tax: TaxType) => {
    const currencyCode = companyStore.selectedCompanyCurrency?.code ?? 'USD'
    return {
      ...tax,
      tax_type_id: tax.id,
      tax_name: `${tax.name} (${
        tax.calculation_type === 'fixed'
          ? new Intl.NumberFormat(undefined, {
              style: 'currency',
              currency: currencyCode,
            }).format(tax.fixed_amount / 100)
          : `${tax.percent}%`
      })`,
    }
  }) as TaxOption[]
})

const isTaxPerItem = computed<boolean>(() => taxPerItem.value === 'YES')

const rules = computed(() => ({
  currentItem: {
    name: {
      required: helpers.withMessage(t('validation.required'), required),
      minLength: helpers.withMessage(
        t('validation.name_min_length', { count: 2 }),
        minLength(2)
      ),
    },
    description: {
      maxLength: helpers.withMessage(
        t('validation.description_maxlength'),
        maxLength(65000)
      ),
    },
  },
}))

const v$ = useVuelidate(rules, itemStore)

async function addItemUnit(): Promise<void> {
  modalStore.openModal({
    title: t('settings.customization.items.add_item_unit'),
    componentName: 'ItemUnitModal',
    size: 'sm',
    refreshData: (unit: { id: number }) => {
      itemStore.currentItem.unit_id = unit.id
    },
  })
}

async function loadData(): Promise<void> {
  isFetchingInitialData.value = true

  await itemStore.fetchItemUnits({ limit: 'all' })
  if (userStore.hasAbilities(ABILITIES.VIEW_TAX_TYPE)) {
    await fetchTaxTypes()
  }

  if (isEdit.value) {
    const id = Number(route.params.id)
    await itemStore.fetchItem(id)
    taxPerItem.value =
      itemStore.currentItem.tax_per_item === 1 ||
      itemStore.currentItem.tax_per_item === '1' ||
      itemStore.currentItem.tax_per_item === true
        ? 'YES'
        : 'NO'
  }

  isFetchingInitialData.value = false
}

async function submitItem(): Promise<void> {
  v$.value.currentItem.$touch()

  if (v$.value.currentItem.$invalid) {
    return
  }

  isSaving.value = true

  try {
    const data: Record<string, unknown> = {
      id: route.params.id,
      ...itemStore.currentItem,
    }

    if (itemStore.currentItem.taxes) {
      data.taxes = itemStore.currentItem.taxes.map((tax) => ({
        tax_type_id: (tax as Record<string, unknown>).tax_type_id ?? tax.id,
        calculation_type: tax.calculation_type,
        fixed_amount: tax.fixed_amount,
        amount:
          tax.calculation_type === 'fixed'
            ? tax.fixed_amount
            : Math.round(price.value * tax.percent),
        percent: tax.percent,
        name: tax.name,
        collective_tax: 0,
      }))
    }

    const action = isEdit.value ? itemStore.updateItem : itemStore.addItem
    await action(data)
    isSaving.value = false
    router.push('/admin/items')
  } catch {
    isSaving.value = false
  }
}
</script>

<template>
  <BasePage>
    <BasePageHeader :title="pageTitle">
      <BaseBreadcrumb>
        <BaseBreadcrumbItem :title="$t('general.home')" to="dashboard" />
        <BaseBreadcrumbItem :title="$t('items.item', 2)" to="/admin/items" />
        <BaseBreadcrumbItem :title="pageTitle" to="#" active />
      </BaseBreadcrumb>
    </BasePageHeader>

    <ItemUnitModal />

    <form
      class="grid lg:grid-cols-2 mt-6"
      action="submit"
      @submit.prevent="submitItem"
    >
      <BaseCard class="w-full">
        <BaseInputGrid layout="one-column">
          <BaseInputGroup
            :label="$t('items.name')"
            :content-loading="isFetchingInitialData"
            required
            :error="
              v$.currentItem.name.$error &&
              v$.currentItem.name.$errors[0].$message
            "
          >
            <BaseInput
              v-model="itemStore.currentItem.name"
              :content-loading="isFetchingInitialData"
              :invalid="v$.currentItem.name.$error"
              @input="v$.currentItem.name.$touch()"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('items.price')"
            :content-loading="isFetchingInitialData"
          >
            <BaseMoney
              v-model="price"
              :content-loading="isFetchingInitialData"
            />
          </BaseInputGroup>

          <BaseInputGroup
            :content-loading="isFetchingInitialData"
            :label="$t('items.unit')"
          >
            <BaseMultiselect
              v-model="itemStore.currentItem.unit_id"
              :content-loading="isFetchingInitialData"
              label="name"
              :options="itemStore.itemUnits"
              value-prop="id"
              :placeholder="$t('items.select_a_unit')"
              searchable
              track-by="name"
            >
              <template #action>
                <BaseSelectAction @click="addItemUnit">
                  <BaseIcon
                    name="PlusIcon"
                    class="h-4 mr-2 -ml-2 text-center text-primary-400"
                  />
                  {{ $t('settings.customization.items.add_item_unit') }}
                </BaseSelectAction>
              </template>
            </BaseMultiselect>
          </BaseInputGroup>

          <BaseInputGroup
            v-if="isTaxPerItem"
            :label="$t('items.taxes')"
            :content-loading="isFetchingInitialData"
          >
            <BaseMultiselect
              v-model="taxes"
              :content-loading="isFetchingInitialData"
              :options="getTaxTypes"
              mode="tags"
              label="tax_name"
              class="w-full"
              value-prop="id"
              :can-deselect="false"
              :can-clear="false"
              searchable
              track-by="tax_name"
              object
            />
          </BaseInputGroup>

          <BaseInputGroup
            :label="$t('items.description')"
            :content-loading="isFetchingInitialData"
            :error="
              v$.currentItem.description.$error &&
              v$.currentItem.description.$errors[0].$message
            "
          >
            <BaseTextarea
              v-model="itemStore.currentItem.description"
              :content-loading="isFetchingInitialData"
              name="description"
              :row="2"
              rows="2"
              @input="v$.currentItem.description.$touch()"
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
              <BaseInputGroup label="HS Code" :content-loading="isFetchingInitialData">
                <BaseInput
                  v-model="itemStore.currentItem.fbr_hs_code"
                  :content-loading="isFetchingInitialData"
                  name="fbr_hs_code"
                />
              </BaseInputGroup>

              <BaseInputGroup label="FBR UOM" :content-loading="isFetchingInitialData">
                <BaseInput
                  v-model="itemStore.currentItem.fbr_uom"
                  :content-loading="isFetchingInitialData"
                  name="fbr_uom"
                />
              </BaseInputGroup>

              <BaseInputGroup label="Sale Type" :content-loading="isFetchingInitialData">
                <BaseInput
                  v-model="itemStore.currentItem.fbr_sale_type"
                  :content-loading="isFetchingInitialData"
                  name="fbr_sale_type"
                />
              </BaseInputGroup>

              <BaseInputGroup label="SRO No" :content-loading="isFetchingInitialData">
                <BaseInput
                  v-model="itemStore.currentItem.fbr_sro_no"
                  :content-loading="isFetchingInitialData"
                  name="fbr_sro_no"
                />
              </BaseInputGroup>

              <BaseInputGroup label="SRO Item No" :content-loading="isFetchingInitialData">
                <BaseInput
                  v-model="itemStore.currentItem.fbr_sro_item_no"
                  :content-loading="isFetchingInitialData"
                  name="fbr_sro_item_no"
                />
              </BaseInputGroup>

              <BaseInputGroup
                label="Fixed Notified Value / Retail Price"
                :content-loading="isFetchingInitialData"
              >
                <BaseMoney
                  v-model="fbrFixedNotifiedValue"
                  :content-loading="isFetchingInitialData"
                />
              </BaseInputGroup>

              <BaseInputGroup
                label="Sales Tax Withheld at Source"
                :content-loading="isFetchingInitialData"
              >
                <BaseMoney
                  v-model="fbrSalesTaxWithheld"
                  :content-loading="isFetchingInitialData"
                />
              </BaseInputGroup>

              <BaseInputGroup label="Further Tax" :content-loading="isFetchingInitialData">
                <BaseMoney
                  v-model="fbrFurtherTax"
                  :content-loading="isFetchingInitialData"
                />
              </BaseInputGroup>

              <BaseInputGroup label="Extra Tax" :content-loading="isFetchingInitialData">
                <BaseMoney
                  v-model="fbrExtraTax"
                  :content-loading="isFetchingInitialData"
                />
              </BaseInputGroup>

              <BaseInputGroup label="FED Payable" :content-loading="isFetchingInitialData">
                <BaseMoney
                  v-model="fbrFedPayable"
                  :content-loading="isFetchingInitialData"
                />
              </BaseInputGroup>
            </BaseInputGrid>
          </div>

          <div>
            <BaseButton
              :content-loading="isFetchingInitialData"
              type="submit"
              :loading="isSaving"
            >
              <template #left="slotProps">
                <BaseIcon
                  v-if="!isSaving"
                  name="ArrowDownOnSquareIcon"
                  :class="slotProps.class"
                />
              </template>
              {{ isEdit ? $t('items.update_item') : $t('items.save_item') }}
            </BaseButton>
          </div>
        </BaseInputGrid>
      </BaseCard>
    </form>
  </BasePage>
</template>
