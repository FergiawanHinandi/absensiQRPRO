// Students Page JavaScript
const API_BASE_URL = '../backend/api';

// Load students data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadStudents();
    setupFilters();
});

let allStudents = [];

// Load all students
async function loadStudents() {
    try {
        const response = await fetch(`${API_BASE_URL}/students.php`);
        const data = await response.json();

        if (data.records && data.records.length > 0) {
            allStudents = data.records;
            displayStudents(allStudents);
        } else {
            document.getElementById('studentsBody').innerHTML = 
                '<tr><td colspan="5" class="no-data">Belum ada data siswa</td></tr>';
        }
    } catch (error) {
        console.error('Error loading students:', error);
        document.getElementById('studentsBody').innerHTML = 
            '<tr><td colspan="5" class="no-data">Error memuat data</td></tr>';
    }
}

// Display students in table
function displayStudents(students) {
    const tbody = document.getElementById('studentsBody');
    tbody.innerHTML = '';

    students.forEach(student => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${student.nis}</td>
            <td>${student.name}</td>
            <td>${student.class}</td>
            <td>
                <div class="qr-code-display" id="qr-${student.id}"></div>
            </td>
            <td>
                <button class="btn btn-primary" onclick="generateQRCode('${student.qr_code}', 'qr-${student.id}')">
                    Tampilkan QR
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}

// Generate QR Code
function generateQRCode(qrData, elementId) {
    const container = document.getElementById(elementId);
    container.innerHTML = '';
    
    new QRCode(container, {
        text: qrData,
        width: 100,
        height: 100
    });
}

// Setup search and filter functionality
function setupFilters() {
    const searchInput = document.getElementById('searchInput');
    const classFilter = document.getElementById('classFilter');

    searchInput.addEventListener('input', filterStudents);
    classFilter.addEventListener('change', filterStudents);
}

// Filter students based on search and class selection
function filterStudents() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const selectedClass = document.getElementById('classFilter').value;

    const filteredStudents = allStudents.filter(student => {
        const matchesSearch = 
            student.nis.toLowerCase().includes(searchTerm) ||
            student.name.toLowerCase().includes(searchTerm) ||
            student.class.toLowerCase().includes(searchTerm);

        const matchesClass = !selectedClass || student.class === selectedClass;

        return matchesSearch && matchesClass;
    });

    displayStudents(filteredStudents);
}
