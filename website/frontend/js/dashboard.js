// Dashboard JavaScript
const API_BASE_URL = '../backend/api';

// Load dashboard data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadDashboardStats();
    loadTodayAttendance();
});

// Load dashboard statistics
async function loadDashboardStats() {
    try {
        // Load students count
        const studentsResponse = await fetch(`${API_BASE_URL}/students.php`);
        const studentsData = await studentsResponse.json();
        
        const totalStudents = studentsData.records ? studentsData.records.length : 0;
        document.getElementById('totalStudents').textContent = totalStudents;

        // Load today's attendance
        const today = new Date().toISOString().split('T')[0];
        const attendanceResponse = await fetch(`${API_BASE_URL}/attendance.php?date=${today}`);
        const attendanceData = await attendanceResponse.json();

        const todayAttendance = attendanceData.records ? attendanceData.records.length : 0;
        const todayAbsent = totalStudents - todayAttendance;
        const attendanceRate = totalStudents > 0 ? Math.round((todayAttendance / totalStudents) * 100) : 0;

        document.getElementById('todayPresent').textContent = todayAttendance;
        document.getElementById('todayAbsent').textContent = todayAbsent;
        document.getElementById('attendanceRate').textContent = attendanceRate + '%';
    } catch (error) {
        console.error('Error loading dashboard stats:', error);
    }
}

// Load today's attendance records
async function loadTodayAttendance() {
    try {
        const today = new Date().toISOString().split('T')[0];
        const response = await fetch(`${API_BASE_URL}/attendance.php?date=${today}`);
        const data = await response.json();

        const tbody = document.getElementById('attendanceBody');

        if (data.records && data.records.length > 0) {
            tbody.innerHTML = '';
            data.records.forEach(record => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${record.nis}</td>
                    <td>${record.student_name}</td>
                    <td>${record.class}</td>
                    <td>${record.time}</td>
                    <td><span class="status-badge status-${record.status}">${formatStatus(record.status)}</span></td>
                    <td>${record.teacher_name || '-'}</td>
                `;
                tbody.appendChild(row);
            });
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="no-data">Belum ada data absensi hari ini</td></tr>';
        }
    } catch (error) {
        console.error('Error loading attendance:', error);
        document.getElementById('attendanceBody').innerHTML = 
            '<tr><td colspan="6" class="no-data">Error memuat data</td></tr>';
    }
}

// Format status text
function formatStatus(status) {
    const statusMap = {
        'present': 'Hadir',
        'late': 'Terlambat',
        'absent': 'Tidak Hadir',
        'excused': 'Izin'
    };
    return statusMap[status] || status;
}
