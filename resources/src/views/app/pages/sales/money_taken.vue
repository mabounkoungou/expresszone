<template>
  <div class="main-content">
    <breadcumb page="Money Taken / Sales Collections" folder="Sales" />
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
    <div v-else>
        <b-row class="mb-3">
          <b-col md="4"><b-form-group label="Branch"><b-form-select v-model="warehouseId" :options="warehouseOptions" @change="load" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Date From"><b-form-input v-model="filters.dateFrom" type="date" @change="load" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Date To"><b-form-input v-model="filters.dateTo" type="date" @change="load" /></b-form-group></b-col>
          <b-col md="2" class="d-flex align-items-end">
            <b-button variant="outline-secondary" block @click="clearFilters">Clear</b-button>
          </b-col>
        </b-row>
        <b-row class="mb-3">
          <b-col md="4">
            <b-form-group label="Person">
              <b-form-input v-model="filters.person" placeholder="Search person" @input="debouncedLoad" />
            </b-form-group>
          </b-col>
        </b-row>
      <b-row class="mb-4">
          <b-col md="4" class="mb-3"><b-card><small class="text-muted">TOTAL SALES COLLECTED</small><h3>{{ money(summary.total_collected) }}</h3></b-card></b-col>
          <b-col md="4" class="mb-3"><b-card><small class="text-muted">TOTAL COLLECTED FROM SHOP</small><h3>{{ money(summary.total_taken) }}</h3></b-card></b-col>
          <b-col md="4" class="mb-3"><b-card><small class="text-muted">TOTAL AMOUNT REMAINING TO BE COLLECTED FROM SHOP</small><h3 class="text-success">{{ money(summary.remaining) }}</h3></b-card></b-col>
      </b-row>
      <b-card class="wrapper">
        <div class="d-flex justify-content-between align-items-center mb-3"><h4 class="mb-0">Money Taken History</h4><b-button v-if="canCreate" variant="primary" @click="$bvModal.show('money-taken-modal')"><lucide-icon name="plus" /> Record Money Taken</b-button></div>
        <vue-good-table :columns="columns" :rows="transactions" styleClass="table-hover tableOne vgt-table">
          <template slot="table-row" slot-scope="props">
            <span v-if="props.column.field === 'person'">{{ props.row.person_name || userName(props.row.person) }}</span>
            <span v-else-if="props.column.field === 'amount'">{{ money(props.row.amount) }}</span>
            <span v-else-if="props.column.field === 'date_time'">{{ props.row.date }} {{ props.row.time }}</span>
            <span v-else-if="props.column.field === 'recorded_by'">{{ userName(props.row.recorder) }}</span>
            <span v-else>{{ props.formattedRow[props.column.field] }}</span>
          </template>
        </vue-good-table>
      </b-card>
    </div>
    <b-modal id="money-taken-modal" hide-footer title="Record Money Taken" @hidden="resetForm">
      <b-form @submit.prevent="save">
        <b-form-group label="Person who took the money"><b-form-input v-model="form.person_name" placeholder="Enter the person's name" required /></b-form-group>
        <b-form-group label="Amount Taken"><b-form-input v-model="form.amount" type="number" min="0.01" step="0.01" required /></b-form-group>
        <b-row><b-col><b-form-group label="Date"><b-form-input v-model="form.date" type="date" required /></b-form-group></b-col><b-col><b-form-group label="Time"><b-form-input v-model="form.time" type="time" required /></b-form-group></b-col></b-row>
        <b-form-group label="Branch"><b-form-select v-model="form.warehouse_id" :options="warehouseOptions" required /></b-form-group>
        <b-form-group label="Reason / Note"><b-form-textarea v-model="form.reason" rows="3" /></b-form-group>
        <b-button type="submit" variant="primary" :disabled="saving">Save</b-button>
      </b-form>
    </b-modal>
  </div>
</template>

<script>
import axios from 'axios';
import moment from 'moment';
import { mapGetters } from 'vuex';
import {
  formatPriceDisplay,
  getPriceFormatSetting
} from '../../../../utils/priceFormat';

export default {
  name: 'MoneyTaken',
  data() { return { loading: true, saving: false, warehouseId: '', warehouses: [], summary: { total_sales: 0, total_taken: 0, remaining: 0 }, users: [], transactions: [], filters: { dateFrom: '', dateTo: '', person: '' }, form: { person_name: '', amount: '', date: moment().format('YYYY-MM-DD'), time: moment().format('HH:mm'), reason: '', warehouse_id: '' }, columns: [{ label: 'Person', field: 'person' }, { label: 'Amount Taken', field: 'amount' }, { label: 'Date / Time', field: 'date_time' }, { label: 'Recorded By', field: 'recorded_by' }, { label: 'Note', field: 'reason' }] }; },
  computed: {
    ...mapGetters(['currentUserPermissions', 'currentUser']),
    canCreate() {
      return this.currentUserPermissions && (
        this.currentUserPermissions.includes('Sales_add') ||
        this.currentUserPermissions.includes('money_taken_view')
      );
    },
    userOptions() { return this.users.map(user => ({ value: user.id, text: this.userName(user) })); }
    ,warehouseOptions() { return this.warehouses.map(warehouse => ({ value: warehouse.id, text: warehouse.name })); }
  },
  mounted() { this.load(); },
  created() {
    this.debouncedLoad = this.debounce(() => this.load(), 300);
  },
  methods: {
    debounce(fn, delay) {
      let timer = null;
      return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
      };
    },
    clearFilters() {
      this.filters = { dateFrom: '', dateTo: '', person: '' };
      this.load();
    },
    money(value) {
      const currency = this.currentUser && this.currentUser.currency;
      const symbol = currency && typeof currency === 'object' ? (currency.symbol || currency.code || '') : (currency || '');
      const formatKey = getPriceFormatSetting({ store: this.$store });
      const formatted = formatPriceDisplay(value || 0, 2, formatKey);
      return symbol ? `${symbol} ${formatted}` : formatted;
    },
    userName(user) { return user ? ([user.firstname, user.lastname].filter(Boolean).join(' ') || user.username) : ''; },
    async load() {
      try {
        const params = new URLSearchParams();
        if (this.warehouseId) params.append('warehouse_id', this.warehouseId);
        if (this.filters.dateFrom) params.append('date_from', this.filters.dateFrom);
        if (this.filters.dateTo) params.append('date_to', this.filters.dateTo);
        if (this.filters.person) params.append('person', this.filters.person);

        const response = await axios.get(`/api/money-taken?${params.toString()}`);
        this.summary = response.data;
        this.users = response.data.users;
        this.transactions = response.data.transactions;
        this.warehouses = response.data.warehouses || [];
        if (!this.form.warehouse_id && this.warehouses.length === 1) this.form.warehouse_id = this.warehouses[0].id;
      } catch (error) {
        this.$bvToast.toast(error.response && error.response.data.message ? error.response.data.message : 'Unable to load money taken.', { variant: 'danger' });
      } finally {
        this.loading = false;
      }
    },
    resetForm() { this.form = { person_name: '', amount: '', date: moment().format('YYYY-MM-DD'), time: moment().format('HH:mm'), reason: '', warehouse_id: this.warehouseId || (this.warehouses[0] ? this.warehouses[0].id : '') }; },
    async save() { this.saving = true; try { await axios.post('/api/money-taken', this.form); this.$bvModal.hide('money-taken-modal'); await this.load(); this.$bvToast.toast('Money taken recorded.', { variant: 'success' }); } catch (error) { this.$bvToast.toast(error.response && error.response.data.message ? error.response.data.message : 'Unable to record money taken.', { variant: 'danger' }); } finally { this.saving = false; } }
  }
};
</script>