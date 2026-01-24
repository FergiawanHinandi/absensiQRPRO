import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';

// Update this URL to your backend server
const API_BASE_URL = 'http://your-server-ip/absensiQRPRO/website/backend/api';

// API service for all backend communications
const api = {
  // Teacher login
  login: async (email, password) => {
    try {
      const response = await axios.post(`${API_BASE_URL}/login.php`, {
        email,
        password,
      });
      
      if (response.data && response.data.data) {
        // Save teacher info to AsyncStorage
        await AsyncStorage.setItem('teacher', JSON.stringify(response.data.data));
        return { success: true, data: response.data.data };
      }
      
      return { success: false, message: 'Login failed' };
    } catch (error) {
      console.error('Login error:', error);
      return { 
        success: false, 
        message: error.response?.data?.message || 'Network error' 
      };
    }
  },

  // Get student by QR code
  scanQRCode: async (qrCode) => {
    try {
      const response = await axios.post(`${API_BASE_URL}/scan_qr.php`, {
        qr_code: qrCode,
      });
      
      if (response.data && response.data.data) {
        return { success: true, data: response.data.data };
      }
      
      return { success: false, message: 'Student not found' };
    } catch (error) {
      console.error('Scan error:', error);
      return { 
        success: false, 
        message: error.response?.data?.message || 'Network error' 
      };
    }
  },

  // Mark attendance
  markAttendance: async (studentId, teacherId, status = 'present', notes = '') => {
    try {
      const response = await axios.post(`${API_BASE_URL}/mark_attendance.php`, {
        student_id: studentId,
        teacher_id: teacherId,
        status,
        notes,
      });
      
      if (response.data) {
        return { success: true, message: response.data.message };
      }
      
      return { success: false, message: 'Failed to mark attendance' };
    } catch (error) {
      console.error('Mark attendance error:', error);
      return { 
        success: false, 
        message: error.response?.data?.message || 'Network error' 
      };
    }
  },

  // Get attendance for a specific date
  getAttendance: async (date) => {
    try {
      const response = await axios.get(`${API_BASE_URL}/attendance.php?date=${date}`);
      
      if (response.data && response.data.records) {
        return { success: true, data: response.data.records };
      }
      
      return { success: false, message: 'No attendance records found' };
    } catch (error) {
      console.error('Get attendance error:', error);
      return { 
        success: false, 
        message: error.response?.data?.message || 'Network error' 
      };
    }
  },

  // Get all students
  getStudents: async () => {
    try {
      const response = await axios.get(`${API_BASE_URL}/students.php`);
      
      if (response.data && response.data.records) {
        return { success: true, data: response.data.records };
      }
      
      return { success: false, message: 'No students found' };
    } catch (error) {
      console.error('Get students error:', error);
      return { 
        success: false, 
        message: error.response?.data?.message || 'Network error' 
      };
    }
  },

  // Logout
  logout: async () => {
    try {
      await AsyncStorage.removeItem('teacher');
      return { success: true };
    } catch (error) {
      console.error('Logout error:', error);
      return { success: false };
    }
  },

  // Get saved teacher info
  getTeacher: async () => {
    try {
      const teacherData = await AsyncStorage.getItem('teacher');
      if (teacherData) {
        return { success: true, data: JSON.parse(teacherData) };
      }
      return { success: false };
    } catch (error) {
      console.error('Get teacher error:', error);
      return { success: false };
    }
  },
};

export default api;
