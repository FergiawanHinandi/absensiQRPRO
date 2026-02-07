<template>
  <div class="backup-monitoring-dashboard">
    <div class="dashboard-header">
      <h1>🔍 Backup & Restore Monitoring</h1>
      <div class="header-actions">
        <button @click="refreshData" class="btn btn-primary" :disabled="loading">
          <i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i>
          Refresh
        </button>
        <button @click="openSettings" class="btn btn-secondary">
          <i class="fas fa-cog"></i>
          Settings
        </button>
      </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
      <div class="card">
        <div class="card-header">
          <h3>📊 Total Jobs Today</h3>
        </div>
        <div class="card-body">
          <div class="metric-value">{{ dashboardData.summary?.total_jobs_today || 0 }}</div>
          <div class="metric-label">Jobs</div>
        </div>
      </div>

      <div class="card success">
        <div class="card-header">
          <h3>✅ Successful</h3>
        </div>
        <div class="card-body">
          <div class="metric-value">{{ dashboardData.summary?.successful_jobs_today || 0 }}</div>
          <div class="metric-label">Jobs</div>
        </div>
      </div>

      <div class="card danger">
        <div class="card-header">
          <h3>❌ Failed</h3>
        </div>
        <div class="card-body">
          <div class="metric-value">{{ dashboardData.summary?.failed_jobs_today || 0 }}</div>
          <div class="metric-label">Jobs</div>
        </div>
      </div>

      <div class="card warning">
        <div class="card-header">
          <h3>🔄 Running</h3>
        </div>
        <div class="card-body">
          <div class="metric-value">{{ dashboardData.summary?.running_jobs || 0 }}</div>
          <div class="metric-label">Jobs</div>
        </div>
      </div>
    </div>

    <!-- Recent Jobs Table -->
    <div class="recent-jobs-section">
      <div class="section-header">
        <h2>📋 Recent Jobs</h2>
        <div class="filter-controls">
          <select v-model="filters.job_type" @change="loadJobs">
            <option value="">All Types</option>
            <option value="backup">💾 Backup</option>
            <option value="restore">🔄 Restore</option>
            <option value="rollback">⏪ Rollback</option>
          </select>
          <select v-model="filters.status" @change="loadJobs">
            <option value="">All Status</option>
            <option value="running">🔄 Running</option>
            <option value="success">✅ Success</option>
            <option value="failed">❌ Failed</option>
            <option value="cancelled">⏹️ Cancelled</option>
          </select>
        </div>
      </div>

      <div class="jobs-table-container">
        <table class="jobs-table">
          <thead>
            <tr>
              <th>Job ID</th>
              <th>Type</th>
              <th>Status</th>
              <th>School</th>
              <th>Duration</th>
              <th>Size</th>
              <th>Started</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="job in jobs" :key="job.id" :class="getStatusClass(job.status)">
              <td>
                <code>{{ job.job_id }}</code>
              </td>
              <td>
                <span class="job-type">{{ getJobTypeIcon(job.job_type) }} {{ job.job_type }}</span>
              </td>
              <td>
                <span class="status-badge" :class="job.status">
                  {{ getStatusIcon(job.status) }} {{ job.status }}
                </span>
              </td>
              <td>{{ job.school?.name || 'N/A' }}</td>
              <td>{{ job.formatted_duration }}</td>
              <td>{{ job.formatted_size }}</td>
              <td>{{ formatDateTime(job.started_at) }}</td>
              <td>
                <div class="action-buttons">
                  <button @click="viewJobDetails(job)" class="btn btn-sm btn-info" title="View Details">
                    <i class="fas fa-eye"></i>
                  </button>
                  <button 
                    v-if="job.status === 'running'" 
                    @click="cancelJob(job)" 
                    class="btn btn-sm btn-warning" 
                    title="Cancel Job"
                  >
                    <i class="fas fa-stop"></i>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="loading" class="loading-indicator">
          <i class="fas fa-spinner fa-spin"></i>
          Loading jobs...
        </div>

        <div v-if="!loading && jobs.length === 0" class="no-data">
          <i class="fas fa-inbox"></i>
          No jobs found
        </div>
      </div>
    </div>

    <!-- Job Details Modal -->
    <div v-if="selectedJob" class="modal-overlay" @click="closeJobDetails">
      <div class="modal-content" @click.stop>
        <div class="modal-header">
          <h3>📋 Job Details</h3>
          <button @click="closeJobDetails" class="btn-close">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="modal-body">
          <div class="job-details">
            <div class="detail-row">
              <label>Job ID:</label>
              <span><code>{{ selectedJob.job_id }}</code></span>
            </div>
            <div class="detail-row">
              <label>Type:</label>
              <span>{{ getJobTypeIcon(selectedJob.job_type) }} {{ selectedJob.job_type }}</span>
            </div>
            <div class="detail-row">
              <label>Status:</label>
              <span class="status-badge" :class="selectedJob.status">
                {{ getStatusIcon(selectedJob.status) }} {{ selectedJob.status }}
              </span>
            </div>
            <div class="detail-row">
              <label>School:</label>
              <span>{{ selectedJob.school?.name || 'N/A' }}</span>
            </div>
            <div class="detail-row">
              <label>User:</label>
              <span>{{ selectedJob.user?.name || 'N/A' }}</span>
            </div>
            <div class="detail-row">
              <label>Started:</label>
              <span>{{ formatDateTime(selectedJob.started_at) }}</span>
            </div>
            <div class="detail-row" v-if="selectedJob.completed_at">
              <label>Completed:</label>
              <span>{{ formatDateTime(selectedJob.completed_at) }}</span>
            </div>
            <div class="detail-row" v-if="selectedJob.duration_seconds">
              <label>Duration:</label>
              <span>{{ selectedJob.formatted_duration }}</span>
            </div>
            <div class="detail-row" v-if="selectedJob.backup_size_bytes">
              <label>Size:</label>
              <span>{{ selectedJob.formatted_size }}</span>
            </div>
            <div class="detail-row" v-if="selectedJob.progress_percentage !== undefined">
              <label>Progress:</label>
              <div class="progress-bar">
                <div 
                  class="progress-fill" 
                  :style="{ width: selectedJob.progress_percentage + '%' }"
                ></div>
                <span class="progress-text">{{ selectedJob.progress_percentage }}%</span>
              </div>
            </div>
            <div class="detail-row" v-if="selectedJob.status_message">
              <label>Message:</label>
              <span>{{ selectedJob.status_message }}</span>
            </div>
            <div class="detail-row" v-if="selectedJob.error_message">
              <label>Error:</label>
              <span class="error-message">{{ selectedJob.error_message }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Real-time Status Updates -->
    <div v-if="runningJobs.length > 0" class="real-time-status">
      <h3>🔄 Real-time Status</h3>
      <div class="running-jobs">
        <div 
          v-for="job in runningJobs" 
          :key="job.job_id" 
          class="running-job-card"
        >
          <div class="job-header">
            <span class="job-id">{{ job.job_id }}</span>
            <span class="job-type">{{ getJobTypeIcon(job.job_type) }} {{ job.job_type }}</span>
          </div>
          <div class="progress-container">
            <div class="progress-bar">
              <div 
                class="progress-fill" 
                :style="{ width: job.progress_percentage + '%' }"
              ></div>
            </div>
            <span class="progress-text">{{ job.progress_percentage }}%</span>
          </div>
          <div class="job-message" v-if="job.status_message">
            {{ job.status_message }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

export default {
  name: 'BackupMonitoringDashboard',
  setup() {
    const loading = ref(false)
    const dashboardData = ref({})
    const jobs = ref([])
    const selectedJob = ref(null)
    const runningJobs = ref([])
    const refreshInterval = ref(null)

    const filters = reactive({
      job_type: '',
      status: ''
    })

    // Load dashboard data
    const loadDashboardData = async () => {
      try {
        const response = await axios.get('/api/monitoring/dashboard')
        dashboardData.value = response.data.data
      } catch (error) {
        console.error('Failed to load dashboard data:', error)
      }
    }

    // Load jobs with filters
    const loadJobs = async () => {
      loading.value = true
      try {
        const params = new URLSearchParams(filters)
        const response = await axios.get(`/api/monitoring/jobs?${params}`)
        jobs.value = response.data.data.data
        
        // Update running jobs for real-time display
        runningJobs.value = jobs.value.filter(job => job.status === 'running')
      } catch (error) {
        console.error('Failed to load jobs:', error)
      } finally {
        loading.value = false
      }
    }

    // Refresh all data
    const refreshData = async () => {
      await Promise.all([
        loadDashboardData(),
        loadJobs()
      ])
    }

    // View job details
    const viewJobDetails = async (job) => {
      try {
        const response = await axios.get(`/api/monitoring/jobs/${job.job_id}`)
        selectedJob.value = response.data.data
      } catch (error) {
        console.error('Failed to load job details:', error)
      }
    }

    // Close job details
    const closeJobDetails = () => {
      selectedJob.value = null
    }

    // Cancel running job
    const cancelJob = async (job) => {
      if (!confirm(`Are you sure you want to cancel job ${job.job_id}?`)) {
        return
      }

      try {
        await axios.post(`/api/monitoring/jobs/${job.job_id}/cancel`)
        await loadJobs()
      } catch (error) {
        console.error('Failed to cancel job:', error)
      }
    }

    // Update running job status
    const updateRunningJobStatus = async () => {
      if (runningJobs.value.length === 0) return

      try {
        const promises = runningJobs.value.map(job => 
          axios.get(`/api/monitoring/jobs/${job.job_id}/status`)
        )
        
        const responses = await Promise.all(promises)
        
        responses.forEach((response, index) => {
          const updatedJob = response.data.data
          const jobIndex = jobs.value.findIndex(j => j.job_id === updatedJob.job_id)
          if (jobIndex !== -1) {
            jobs.value[jobIndex] = { ...jobs.value[jobIndex], ...updatedJob }
          }
        })

        // Update running jobs list
        runningJobs.value = jobs.value.filter(job => job.status === 'running')
      } catch (error) {
        console.error('Failed to update job status:', error)
      }
    }

    // Helper functions
    const getStatusClass = (status) => {
      return `status-${status}`
    }

    const getStatusIcon = (status) => {
      const icons = {
        running: '🔄',
        success: '✅',
        failed: '❌',
        cancelled: '⏹️'
      }
      return icons[status] || '❓'
    }

    const getJobTypeIcon = (type) => {
      const icons = {
        backup: '💾',
        restore: '🔄',
        rollback: '⏪'
      }
      return icons[type] || '📋'
    }

    const formatDateTime = (dateTime) => {
      if (!dateTime) return 'N/A'
      return new Date(dateTime).toLocaleString()
    }

    const openSettings = () => {
      // TODO: Implement settings modal
      alert('Settings functionality coming soon!')
    }

    // Lifecycle hooks
    onMounted(async () => {
      await refreshData()
      
      // Set up real-time updates for running jobs
      refreshInterval.value = setInterval(() => {
        updateRunningJobStatus()
      }, 5000) // Update every 5 seconds
    })

    onUnmounted(() => {
      if (refreshInterval.value) {
        clearInterval(refreshInterval.value)
      }
    })

    return {
      loading,
      dashboardData,
      jobs,
      selectedJob,
      runningJobs,
      filters,
      loadDashboardData,
      loadJobs,
      refreshData,
      viewJobDetails,
      closeJobDetails,
      cancelJob,
      updateRunningJobStatus,
      getStatusClass,
      getStatusIcon,
      getJobTypeIcon,
      formatDateTime,
      openSettings
    }
  }
}
</script>

<style scoped>
.backup-monitoring-dashboard {
  padding: 20px;
  max-width: 1400px;
  margin: 0 auto;
}

.dashboard-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 30px;
}

.dashboard-header h1 {
  margin: 0;
  color: #333;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.summary-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.card {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  border-left: 4px solid #6c757d;
}

.card.success {
  border-left-color: #28a745;
}

.card.danger {
  border-left-color: #dc3545;
}

.card.warning {
  border-left-color: #ffc107;
}

.card-header h3 {
  margin: 0 0 10px 0;
  font-size: 14px;
  color: #666;
}

.metric-value {
  font-size: 32px;
  font-weight: bold;
  color: #333;
}

.metric-label {
  font-size: 12px;
  color: #666;
  text-transform: uppercase;
}

.recent-jobs-section {
  background: white;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.filter-controls {
  display: flex;
  gap: 10px;
}

.filter-controls select {
  padding: 8px 12px;
  border: 1px solid #ddd;
  border-radius: 4px;
  background: white;
}

.jobs-table {
  width: 100%;
  border-collapse: collapse;
}

.jobs-table th,
.jobs-table td {
  padding: 12px;
  text-align: left;
  border-bottom: 1px solid #eee;
}

.jobs-table th {
  background: #f8f9fa;
  font-weight: 600;
  color: #333;
}

.status-badge {
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
}

.status-badge.running {
  background: #e3f2fd;
  color: #1976d2;
}

.status-badge.success {
  background: #e8f5e8;
  color: #2e7d32;
}

.status-badge.failed {
  background: #ffebee;
  color: #c62828;
}

.status-badge.cancelled {
  background: #fff3e0;
  color: #f57c00;
}

.action-buttons {
  display: flex;
  gap: 5px;
}

.btn {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
}

.btn-primary {
  background: #007bff;
  color: white;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-info {
  background: #17a2b8;
  color: white;
}

.btn-warning {
  background: #ffc107;
  color: #212529;
}

.btn-sm {
  padding: 4px 8px;
  font-size: 12px;
}

.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 8px;
  padding: 20px;
  max-width: 600px;
  width: 90%;
  max-height: 80vh;
  overflow-y: auto;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.btn-close {
  background: none;
  border: none;
  font-size: 18px;
  cursor: pointer;
}

.detail-row {
  display: flex;
  margin-bottom: 10px;
  align-items: flex-start;
}

.detail-row label {
  font-weight: 600;
  min-width: 100px;
  color: #333;
}

.progress-bar {
  width: 100%;
  height: 20px;
  background: #e9ecef;
  border-radius: 10px;
  overflow: hidden;
  position: relative;
}

.progress-fill {
  height: 100%;
  background: #007bff;
  transition: width 0.3s ease;
}

.progress-text {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-size: 12px;
  font-weight: 500;
  color: #333;
}

.error-message {
  color: #dc3545;
  font-family: monospace;
  background: #f8d7da;
  padding: 8px;
  border-radius: 4px;
}

.real-time-status {
  background: white;
  border-radius: 8px;
  padding: 20px;
  margin-top: 20px;
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.running-jobs {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 15px;
  margin-top: 15px;
}

.running-job-card {
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 15px;
  background: #f8f9fa;
}

.job-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.job-id {
  font-family: monospace;
  font-size: 12px;
  color: #666;
}

.progress-container {
  margin-bottom: 10px;
}

.job-message {
  font-size: 12px;
  color: #666;
  font-style: italic;
}

.loading-indicator,
.no-data {
  text-align: center;
  padding: 40px;
  color: #666;
}

.fa-spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
