import React, { useState, useEffect } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import type { Schedule, QrCodeData } from '../../types/api.types';
import { apiClient as api } from '../../lib/api';
import { X, RefreshCw, Maximize2 } from 'lucide-react';
import { getErrorMessage } from '../../utils/errorHandler';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    schedule: Schedule;
}

const GenerateQRModal: React.FC<Props> = ({ isOpen, onClose, schedule }) => {
    const [loading, setLoading] = useState(false);
    const [qrCode, setQrCode] = useState<QrCodeData | null>(null);
    const [timer, setTimer] = useState<number>(0);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (isOpen) {
            generateQR();
        }
        return () => clearInterval(interval);
    }, [isOpen]);

    // Timer countdown
    let interval: ReturnType<typeof setInterval>;
    useEffect(() => {
        if (qrCode && isOpen) {
            const expiryTime = new Date(qrCode.valid_until).getTime();
            interval = setInterval(() => {
                const now = new Date().getTime();
                const distance = expiryTime - now;
                setTimer(Math.floor(distance / 1000));

                if (distance < 0) {
                    clearInterval(interval);
                    handleCloseQR(); // Auto close if expired
                }
            }, 1000);
        }
        return () => clearInterval(interval);
    }, [qrCode, isOpen]);

    const generateQR = async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await api.post<{ qr_code: QrCodeData }>('/qr/generate', {
                schedule_id: schedule.id,
                qr_type: 'in', // Default check-in
                expiry_minutes: 5, // Default 5 mins
            });
            setQrCode(response.data.qr_code);
        } catch (err) {
            setError(getErrorMessage(err));
        } finally {
            setLoading(false);
        }
    };

    const handleCloseQR = async () => {
        if (qrCode) {
            try {
                await api.post(`/qr/close`, { qr_code_id: qrCode.id });
            } catch (e) {
                // ignore error on close
            }
        }
        setQrCode(null);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-lg overflow-hidden relative animate-in fade-in zoom-in duration-200">

                {/* Header */}
                <div className="bg-blue-600 px-6 py-4 flex justify-between items-center text-white">
                    <div>
                        <h3 className="text-lg font-bold">QR Absensi - {schedule.class.name}</h3>
                        <p className="text-blue-100 text-sm">{schedule.subject.name}</p>
                    </div>
                    <button
                        onClick={handleCloseQR}
                        className="text-white/80 hover:text-white bg-white/10 hover:bg-white/20 p-2 rounded-full transition-colors"
                    >
                        <X className="w-6 h-6" />
                    </button>
                </div>

                {/* Content */}
                <div className="p-8 flex flex-col items-center justify-center text-center">
                    {loading ? (
                        <div className="py-20 flex flex-col items-center animate-pulse">
                            <RefreshCw className="w-12 h-12 text-blue-500 animate-spin mb-4" />
                            <p className="text-gray-500 font-medium">Membuat Token Aman...</p>
                        </div>
                    ) : error ? (
                        <div className="py-10 text-center">
                            <p className="text-red-500 font-medium mb-4">{error}</p>
                            <button
                                onClick={generateQR}
                                className="px-4 py-2 bg-blue-100 text-blue-700 rounded-lg hover:bg-blue-200"
                            >
                                Coba Lagi
                            </button>
                        </div>
                    ) : qrCode ? (
                        <>
                            <div className="bg-white p-4 rounded-xl border-4 border-gray-100 shadow-inner mb-6">
                                <QRCodeSVG
                                    value={qrCode.token}
                                    size={280}
                                    level="H"
                                    includeMargin={true}
                                />
                            </div>

                            <div className="w-full bg-blue-50 rounded-lg p-4 mb-6">
                                <p className="text-sm text-blue-600 font-bold uppercase tracking-wider mb-2">Sisa Waktu</p>
                                <div className="text-4xl font-mono font-bold text-blue-800">
                                    {Math.floor(timer / 60)}:{(timer % 60).toString().padStart(2, '0')}
                                </div>
                            </div>

                            <div className="flex gap-3 w-full">
                                <button
                                    onClick={generateQR}
                                    className="flex-1 py-3 px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-lg flex items-center justify-center gap-2"
                                >
                                    <RefreshCw className="w-5 h-5" />
                                    Perbarui QR
                                </button>
                                <button
                                    className="flex-1 py-3 px-4 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-lg flex items-center justify-center gap-2"
                                    title="Fullscreen Mode (Coming Soon)"
                                >
                                    <Maximize2 className="w-5 h-5" />
                                    Layar Penuh
                                </button>
                            </div>

                            <div className="mt-4 flex justify-center gap-4 text-xs text-gray-500 font-medium">
                                <span className="flex items-center gap-1 text-green-600">
                                    <span className="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                                    Dynamic Token
                                </span>
                                <span className="flex items-center gap-1 text-blue-600">
                                    <Maximize2 className="w-3 h-3" />
                                    Geo-Fencing Active
                                </span>
                            </div>
                        </>
                    ) : null}
                </div>
            </div>
        </div>
    );
};

export default GenerateQRModal;
