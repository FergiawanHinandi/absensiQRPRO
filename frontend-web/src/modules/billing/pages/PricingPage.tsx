import { useEffect, useState } from 'react';
import { billingService } from '../services/billingService';
import type { SubscriptionPackage } from '../services/billingService';
import { CheckCircle2, Shield, Loader, Star } from 'lucide-react';
import Loading from '../../../components/common/Loading';
import showToast from '../../../utils/toast';

export const PricingPage = () => {
    const [packages, setPackages] = useState<SubscriptionPackage[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [processingId, setProcessingId] = useState<number | null>(null);

    useEffect(() => {
        loadPackages();
        // Load Midtrans Snap script dynamically if not present
    }, []);

    const loadPackages = async () => {
        try {
            const data = await billingService.getPackages();
            setPackages(data);
        } catch (error) {
            console.error(error);
        } finally {
            setIsLoading(false);
        }
    };

    const handlePurchase = async (pkg: SubscriptionPackage) => {
        try {
            setProcessingId(pkg.id);
            const response = await billingService.purchasePackage(pkg.id);

            // Open Snap Popup
            if (window.snap) {
                window.snap.pay(response.token, {
                    onSuccess: function (result: unknown) {
                        showToast.success("Pembayaran berhasil!");
                        console.log(result);
                        window.location.reload();
                    },
                    onPending: function (result: unknown) {
                        showToast.loading("Menunggu pembayaran...");
                        console.log(result);
                    },
                    onError: function (result: unknown) {
                        showToast.error("Pembayaran gagal!");
                        console.log(result);
                    },
                    onClose: function () {
                        showToast.error('Anda menutup popup pembayaran tanpa menyelesaikan pembayaran');
                    }
                });
            } else {
                window.location.href = response.redirect_url;
            }

        } catch (error) {
            showToast.error('Gagal memproses transaksi');
        } finally {
            setProcessingId(null);
        }
    };

    if (isLoading) return <Loading />;

    return (
        <div className="p-8">
            <div className="text-center mb-12">
                <h2 className="text-3xl font-bold text-slate-800 mb-4">Pilih Paket Langganan</h2>
                <p className="text-slate-600 max-w-2xl mx-auto">
                    Tingkatkan kapasitas sekolah Anda dengan fitur premium. Pembayaran aman dan instan dengan Midtrans.
                </p>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-3 gap-8 max-w-6xl mx-auto">
                {packages.map((pkg) => {
                    const features = typeof pkg.features === 'string' ? JSON.parse(pkg.features) : pkg.features;

                    return (
                        <div key={pkg.id} className={`relative bg-white rounded-2xl shadow-sm border ${pkg.is_popular ? 'border-blue-500 ring-2 ring-blue-500/20' : 'border-slate-200'} p-8 flex flex-col hover:shadow-lg transition-all`}>
                            {pkg.is_popular && (
                                <div className="absolute top-0 right-0 bg-blue-500 text-white text-xs font-bold px-3 py-1 rounded-bl-xl rounded-tr-lg flex items-center gap-1">
                                    <Star className="w-3 h-3 fill-current" />
                                    POPULAR
                                </div>
                            )}

                            <h3 className="text-xl font-bold text-slate-800 mb-2">{pkg.name}</h3>
                            <div className="mb-6">
                                <span className="text-4xl font-bold text-slate-900">
                                    Rp {new Intl.NumberFormat('id-ID').format(pkg.price)}
                                </span>
                                <span className="text-slate-500 text-sm"> / bulan</span>
                            </div>

                            <p className="text-slate-600 text-sm mb-6 min-h-[40px]">{pkg.description}</p>

                            <hr className="border-slate-100 mb-6" />

                            <ul className="space-y-3 mb-8 flex-1">
                                {Object.entries(features).map(([key, value]) => (
                                    <li key={key} className="flex items-start gap-3 text-sm text-slate-700">
                                        <CheckCircle2 className="w-5 h-5 text-green-500 shrink-0" />
                                        <span>
                                            <span className="font-semibold">{key.replace(/_/g, ' ')}:</span> {String(value)}
                                        </span>
                                    </li>
                                ))}
                            </ul>

                            <button
                                onClick={() => handlePurchase(pkg)}
                                disabled={processingId === pkg.id}
                                className={`w-full py-3 rounded-xl font-bold transition-all flex justify-center items-center gap-2 ${pkg.is_popular
                                    ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-200 shadow-lg'
                                    : 'bg-slate-900 hover:bg-slate-800 text-white'
                                    } disabled:opacity-70 disabled:cursor-not-allowed`}
                            >
                                {processingId === pkg.id ? (
                                    <Loader className="w-5 h-5 animate-spin" />
                                ) : (
                                    <Shield className="w-4 h-4" />
                                )}
                                {processingId === pkg.id ? 'Memproses...' : 'Pilih Paket Ini'}
                            </button>
                        </div>
                    );
                })}
            </div>
        </div>
    );
};
