import React from 'react';
import { AlertTriangle, RefreshCw, Home, ArrowLeft } from 'lucide-react';

interface PageErrorFallbackProps {
  title?: string;
  message?: string;
}

const PageErrorFallback: React.FC<PageErrorFallbackProps> = ({
  title = 'Terjadi Kesalahan',
  message = 'Halaman mengalami masalah yang tidak terduga. Silakan coba lagi atau hubungi administrator.',
}) => {
  const handleReload = () => {
    window.location.reload();
  };

  const handleGoBack = () => {
    window.history.back();
  };

  const handleRetry = () => {
    handleReload();
  };

  return (
    <div className="flex items-center justify-center min-h-[60vh] p-6">
      <div className="max-w-lg w-full bg-white rounded-2xl shadow-lg border border-red-100 p-8 text-center">
        {/* Error Icon */}
        <div className="mx-auto w-16 h-16 bg-gradient-to-br from-red-50 to-orange-50 rounded-2xl flex items-center justify-center mb-6 shadow-sm">
          <AlertTriangle className="w-8 h-8 text-red-500" />
        </div>

        {/* Error Title */}
        <h2 className="text-xl font-bold text-gray-900 mb-2">
          {title}
        </h2>

        {/* Error Message */}
        <p className="text-gray-500 mb-6 leading-relaxed">
          {message}
        </p>

        {/* Action Buttons */}
        <div className="flex flex-col sm:flex-row items-center justify-center gap-3">
          <button
            onClick={handleRetry}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 text-white font-medium rounded-xl hover:bg-blue-700 transition-all duration-200 shadow-sm hover:shadow-md active:scale-[0.98]"
          >
            <RefreshCw className="w-4 h-4" />
            Coba Lagi
          </button>
          <button
            onClick={handleGoBack}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-white text-gray-700 font-medium rounded-xl border border-gray-200 hover:bg-gray-50 hover:border-gray-300 transition-all duration-200 active:scale-[0.98]"
          >
            <ArrowLeft className="w-4 h-4" />
            Kembali
          </button>
          <button
            onClick={handleReload}
            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-gray-100 text-gray-600 font-medium rounded-xl hover:bg-gray-200 transition-all duration-200 active:scale-[0.98]"
          >
            <Home className="w-4 h-4" />
            Muat Ulang
          </button>
        </div>
      </div>
    </div>
  );
};

export default PageErrorFallback;
