import React from 'react';
import { AlertTriangle, X, Phone, Mail } from 'lucide-react';

interface AccountInactiveModalProps {
    isOpen: boolean;
    onClose: () => void;
    message: string;
    details?: {
        reason?: string;
        action?: string;
        contact?: string;
    };
}

export const AccountInactiveModal: React.FC<AccountInactiveModalProps> = ({
    isOpen,
    onClose,
    message,
    details
}) => {
    if (!isOpen) return null;

    const handleClose = () => {
        onClose();
        // Redirect to login after closing
        window.location.href = '/login';
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 animate-in fade-in duration-200">
            <div className="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in duration-200">
                {/* Header */}
                <div className="bg-gradient-to-r from-red-600 to-red-500 px-6 py-4 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center">
                            <AlertTriangle className="w-6 h-6 text-white" />
                        </div>
                        <h3 className="text-lg font-bold text-white">Akun Tidak Aktif</h3>
                    </div>
                    <button
                        onClick={handleClose}
                        className="text-white/80 hover:text-white transition-colors"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Content */}
                <div className="p-6 space-y-4">
                    {/* Main Message */}
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                        <p className="text-red-900 font-semibold text-center">
                            {message}
                        </p>
                    </div>

                    {/* Reason */}
                    {details?.reason && (
                        <div className="space-y-2">
                            <h4 className="font-semibold text-slate-900">Alasan:</h4>
                            <p className="text-slate-600">{details.reason}</p>
                        </div>
                    )}

                    {/* Action */}
                    {details?.action && (
                        <div className="space-y-2">
                            <h4 className="font-semibold text-slate-900">Yang Harus Dilakukan:</h4>
                            <p className="text-slate-600">{details.action}</p>
                        </div>
                    )}

                    {/* Contact Info */}
                    {details?.contact && (
                        <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 space-y-3">
                            <h4 className="font-semibold text-blue-900 flex items-center gap-2">
                                <Phone className="w-4 h-4" />
                                Hubungi Kami:
                            </h4>
                            <div className="space-y-2 text-sm text-blue-800">
                                {details.contact.split('atau').map((contact, idx) => (
                                    <div key={idx} className="flex items-start gap-2">
                                        {contact.includes('Email') ? (
                                            <Mail className="w-4 h-4 mt-0.5 flex-shrink-0" />
                                        ) : (
                                            <Phone className="w-4 h-4 mt-0.5 flex-shrink-0" />
                                        )}
                                        <span>{contact.trim()}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="px-6 py-4 bg-slate-50 border-t border-slate-200">
                    <button
                        onClick={handleClose}
                        className="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition-colors"
                    >
                        Kembali ke Login
                    </button>
                </div>
            </div>
        </div>
    );
};
