import { useState, useEffect } from 'react';

// Mock Data for MVP
const MOCK_STUDENT = {
    id: 1,
    name: 'Budi Santoso',
    class_name: 'XI IPA 1',
    nis: '2023001',
    photo_url: 'https://ui-avatars.com/api/?name=Budi+Santoso&background=random'
};

const MOCK_TODAY = {
    date: new Date().toISOString().split('T')[0],
    status: 'present', // present, late, absent, alpha
    check_in: '06:45',
    check_out: null,
    location: 'Gerbang Utama'
};

const MOCK_HISTORY = [
    { date: '2024-01-24', status: 'present', check_in: '06:50', check_out: '14:00' },
    { date: '2024-01-23', status: 'present', check_in: '06:45', check_out: '14:05' },
    { date: '2024-01-22', status: 'late', check_in: '07:15', check_out: '14:00' },
    { date: '2024-01-21', status: 'sick', check_in: null, check_out: null },
    { date: '2024-01-20', status: 'present', check_in: '06:55', check_out: '12:00' },
];

export const useStudentInfo = () => {
    const [data, setData] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        // Simulate API call
        setTimeout(() => {
            setData(MOCK_STUDENT);
            setIsLoading(false);
        }, 500);
    }, []);

    return { data, isLoading };
};

export const useTodayAttendance = () => {
    const [data, setData] = useState<any>(null);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        // Simulate API call
        setTimeout(() => {
            setData(MOCK_TODAY);
            setIsLoading(false);
        }, 600);
    }, []);

    return { data, isLoading };
};

export const useAttendanceHistory = () => {
    const [data, setData] = useState<any[]>([]);
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        // Simulate API call
        setTimeout(() => {
            setData(MOCK_HISTORY);
            setIsLoading(false);
        }, 700);
    }, []);

    return { data, isLoading };
};
