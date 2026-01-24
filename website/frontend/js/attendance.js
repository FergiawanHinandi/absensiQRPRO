// Attendance Page JavaScript
const API_BASE_URL = '../backend/api';

// Initialize date picker with today's date
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('dateFilter');
    dateInput.value = new Date().toISOString().split('T')[0];
    loadAttendance();
});

// Load attendance records for selected date
async function loadAttendance() {
    const date = document.getElementById('dateFilter').value;
    
    if (!date) {
        alert('Silakan pilih tanggal terlebih dahulu');
        return;
    }

    try {
        const response = await fetch(`${API_BASE_URL}/attendance.php?date=${date}`);
        const data = await response.json();

        const tbody = document.getElementById('attendanceBody');

        if (data.records && data.records.length > 0) {
            tbody.innerHTML = '';
            
            // Calculate status counts
            let presentCount = 0, lateCount = 0, absentCount = 0, excusedCount = 0;

            data.records.forEach(record => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${record.nis}</td>
                    <td>${record.student_name}</td>
                    <td>${record.class}</td>
                    <td>${record.time}</td>
                    <td><span class="status-badge status-${record.status}">${formatStatus(record.status)}</span></td>
                    <td>${record.teacher_name || '-'}</td>
                    <td>${record.notes || '-'}</td>
                `;
                tbody.appendChild(row);

                // Count statuses
                switch(record.status) {
                    case 'present': presentCount++; break;
                    case 'late': lateCount++; break;
                    case 'absent': absentCount++; break;
                    case 'excused': excusedCount++; break;
                }
            });

            // Update summary cards
            document.getElementById('presentCount').textContent = presentCount;
            document.getElementById('lateCount').textContent = lateCount;
            document.getElementById('absentCount').textContent = absentCount;
            document.getElementById('excusedCount').textContent = excusedCount;
        } else {
            tbody.innerHTML = '<tr><td colspan="7" class="no-data">Tidak ada data absensi untuk tanggal ini</td></tr>';
            
            // Reset summary cards
            document.getElementById('presentCount').textContent = '0';
            document.getElementById('lateCount').textContent = '0';
            document.getElementById('absentCount').textContent = '0';
            document.getElementById('excusedCount').textContent = '0';
        }
    } catch (error) {
        console.error('Error loading attendance:', error);
        document.getElementById('attendanceBody').innerHTML = 
            '<tr><td colspan="7" class="no-data">Error memuat data</td></tr>';
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
