import React, { useState } from 'react';
import { apiClient } from '../../lib/api';
import { FileDown, FileSpreadsheet, FileText, Loader2, CheckCircle2, AlertCircle } from 'lucide-react';
import showToast from '../../utils/toast';

interface ExportTrendButtonProps {
  /** Period to export */
  period: '7d' | '30d' | '90d';
  /** Optional class name */
  className?: string;
  /** Variant style */
  variant?: 'primary' | 'secondary' | 'icon';
}

const ExportTrendButton: React.FC<ExportTrendButtonProps> = ({
  period,
  className = '',
  variant = 'primary',
}) => {
  const [exporting, setExporting] = useState<'idle' | 'excel' | 'pdf'>('idle');

  const handleExport = async (format: 'excel' | 'pdf') => {
    setExporting(format);
    const toastId = showToast.loading(
      format === 'excel' ? 'Menyiapkan file Excel...' : 'Menyiapkan file PDF...'
    );

    try {
      const response = await apiClient.post('/attendance/trends/export', {
        format,
        period,
      }, {
        responseType: 'blob',
        timeout: 60000, // 60 seconds for export
      });

      const blob = response.data;
      const contentDisposition = response.headers?.['content-disposition'];
      const filename = contentDisposition
        ? contentDisposition.split('filename=')[1]?.replace(/"/g, '') 
        : `tren_kehadiran_${period}_${new Date().toISOString().split('T')[0]}.${format === 'excel' ? 'xlsx' : 'pdf'}`;

      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);

      showToast.dismiss(toastId);
      showToast.success(
        format === 'excel' ? 'File Excel berhasil diunduh!' : 'File PDF berhasil diunduh!'
      );
    } catch (error: any) {
      showToast.dismiss(toastId);
      const errorMsg = error.response?.data?.message || error.message || 'Gagal mengekspor data';
      showToast.error(errorMsg);
    } finally {
      setExporting('idle');
    }
  };

  if (variant === 'icon') {
    return (
      <div className="flex items-center gap-1">
        <button
          onClick={() => handleExport('excel')}
          disabled={exporting !== 'idle'}
          className="p-2 rounded-lg hover:bg-emerald-50 text-emerald-600 hover:text-emerald-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          title="Download Excel"
        >
          {exporting === 'excel' ? (
            <Loader2 className="w-4 h-4 animate-spin" />
          ) : (
            <FileSpreadsheet className="w-4 h-4" />
          )}
        </button>
        <button
          onClick={() => handleExport('pdf')}
          disabled={exporting !== 'idle'}
          className="p-2 rounded-lg hover:bg-red-50 text-red-600 hover:text-red-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          title="Download PDF"
        >
          {exporting === 'pdf' ? (
            <Loader2 className="w-4 h-4 animate-spin" />
          ) : (
            <FileText className="w-4 h-4" />
          )}
        </button>
      </div>
    );
  }

  return (
    <div className={`inline-flex items-center gap-2 ${className}`}>
      <button
        onClick={() => handleExport('excel')}
        disabled={exporting !== 'idle'}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all ${
          variant === 'primary'
            ? 'bg-emerald-600 text-white hover:bg-emerald-700 shadow-sm hover:shadow-md'
            : 'bg-white border border-slate-200 text-slate-700 hover:bg-emerald-50 hover:border-emerald-200'
        } disabled:opacity-50 disabled:cursor-not-allowed`}
      >
        {exporting === 'excel' ? (
          <Loader2 className="w-4 h-4 animate-spin" />
        ) : (
          <FileSpreadsheet className="w-4 h-4" />
        )}
        Excel
      </button>
      <button
        onClick={() => handleExport('pdf')}
        disabled={exporting !== 'idle'}
        className={`inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-all ${
          variant === 'primary'
            ? 'bg-red-600 text-white hover:bg-red-700 shadow-sm hover:shadow-md'
            : 'bg-white border border-slate-200 text-slate-700 hover:bg-red-50 hover:border-red-200'
        } disabled:opacity-50 disabled:cursor-not-allowed`}
      >
        {exporting === 'pdf' ? (
          <Loader2 className="w-4 h-4 animate-spin" />
        ) : (
          <FileText className="w-4 h-4" />
        )}
        PDF
      </button>
    </div>
  );
};

export { ExportTrendButton };
