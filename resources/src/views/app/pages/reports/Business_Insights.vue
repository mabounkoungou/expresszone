<template>
  <div class="main-content p-2 p-md-4">
    <breadcumb page="AI Business Insights" :folder="$t('Reports')" />
    <b-card class="mb-4">
      <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div><h3 class="mb-1">AI Business Insights</h3><p class="text-muted mb-0">Gemini analysis based on your real business data.</p></div>
        <lucide-icon name="brain" size="32" />
      </div>
    </b-card>
    <b-card class="mb-4">
      <b-row>
        <b-col md="4"><b-form-group label="Branch"><b-form-select v-model="warehouseId" :options="warehouseOptions" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="From"><b-form-input v-model="dateFrom" type="date" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="To"><b-form-input v-model="dateTo" type="date" /></b-form-group></b-col>
        <b-col md="2" class="d-flex align-items-end"><b-button variant="primary" block :disabled="loading" @click="load">Analyze</b-button></b-col>
      </b-row>
    </b-card>
    <div v-if="loading" class="text-center p-5"><div class="spinner spinner-primary"></div><p class="mt-3">Analyzing trusted business data...</p></div>
    <template v-else-if="analysis">
      <b-row class="mb-4">
        <b-col md="3"><b-card><small class="text-muted">STATUS</small><h3>{{ analysis.status || 'Needs Attention' }}</h3></b-card></b-col>
        <b-col md="3"><b-card><small class="text-muted">HEALTH SCORE</small><h3>{{ analysis.score !== undefined ? analysis.score : 'N/A' }}/100</h3></b-card></b-col>
        <b-col md="3"><b-card><small class="text-muted">REVENUE</small><h3>{{ money(metrics.overall.revenue) }}</h3><small>{{ signed(metrics.overall.sales_change_percent) }} vs previous</small></b-card></b-col>
        <b-col md="3"><b-card><small class="text-muted">ORDERS</small><h3>{{ metrics.overall.orders }}</h3><small>{{ signed(metrics.overall.orders_change_percent) }} vs previous</small></b-card></b-col>
      </b-row>
      <b-card class="mb-4"><h4>Business Analysis</h4><p>{{ analysis.summary }}</p><p class="mb-0"><strong>Best branch:</strong> {{ analysis.best_branch || 'No branch data' }} <span class="mx-2">|</span> <strong>Needs attention:</strong> {{ analysis.branch_needing_attention || 'No branch currently declining' }}</p></b-card>
      <b-row class="mb-4"><b-col md="4"><b-card><h5>What Is Going Well</h5><ul><li v-for="item in analysis.positives || []" :key="item">{{ item }}</li><li v-if="!(analysis.positives || []).length">No positive findings returned.</li></ul></b-card></b-col><b-col md="4"><b-card><h5>Areas Needing Attention</h5><ul><li v-for="item in analysis.attention || []" :key="item">{{ item }}</li><li v-if="!(analysis.attention || []).length">No urgent findings returned.</li></ul></b-card></b-col><b-col md="4"><b-card><h5>Recommendations</h5><ul><li v-for="item in analysis.recommendations || []" :key="item">{{ item }}</li><li v-if="!(analysis.recommendations || []).length">No recommendations returned.</li></ul></b-card></b-col></b-row>
      <b-card><h4>Branch Performance</h4><vue-good-table :columns="columns" :rows="branchRows" /></b-card>
    </template>
  </div>
</template>
<script>
import axios from 'axios';
import moment from 'moment';
import { mapGetters } from 'vuex';
export default {
  name: 'BusinessInsights',
  data() { const end = moment(); return { loading: false, warehouseId: '', warehouses: [], metrics: { overall: { revenue: 0, orders: 0, sales_change_percent: 0, orders_change_percent: 0 }, branches: [] }, analysis: null, dateFrom: end.clone().startOf('month').format('YYYY-MM-DD'), dateTo: end.format('YYYY-MM-DD'), columns: [{ label: 'Branch', field: 'branch' }, { label: 'Revenue', field: 'revenue' }, { label: 'Revenue Change', field: 'sales_change' }, { label: 'Profit', field: 'profit' }, { label: 'Orders', field: 'orders' }] }; },
  computed: { ...mapGetters(['currentUser', 'currentUserPermissions']), warehouseOptions() { return [{ value: '', text: 'All Branches' }].concat(this.warehouses.map(item => ({ value: item.id, text: item.name }))); }, branchRows() { return (this.metrics.branches || []).map(item => ({ branch: item.branch, revenue: this.money(item.current.revenue), sales_change: this.signed(item.current.sales_change_percent) + '%', profit: this.money(item.current.profit), orders: item.current.orders })); } },
  mounted() { this.load(); },
  methods: { money(value) { return Number(value || 0).toFixed(2); }, signed(value) { const number = Number(value || 0); return (number >= 0 ? '+' : '') + number.toFixed(2); }, async load() { this.loading = true; try { const response = await axios.get('/api/business-insights', { params: { warehouse_id: this.warehouseId || undefined, date_from: this.dateFrom, date_to: this.dateTo } }); this.metrics = response.data.metrics; this.analysis = response.data.analysis; this.warehouses = response.data.metrics.branches.map(item => ({ id: item.branch_id, name: item.branch })); } finally { this.loading = false; } } }
};
</script>
