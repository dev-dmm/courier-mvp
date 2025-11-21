import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function OrdersIndex({ auth, orders, filters }) {
    const [copiedVoucher, setCopiedVoucher] = useState(null);
    const [expandedVouchers, setExpandedVouchers] = useState(new Set());

    const copyToClipboard = (text, voucherId) => {
        navigator.clipboard.writeText(text).then(() => {
            setCopiedVoucher(voucherId);
            setTimeout(() => setCopiedVoucher(null), 1500);
        });
    };

    const toggleVoucherHistory = (voucherId) => {
        const newExpanded = new Set(expandedVouchers);
        if (newExpanded.has(voucherId)) {
            newExpanded.delete(voucherId);
        } else {
            newExpanded.add(voucherId);
        }
        setExpandedVouchers(newExpanded);
    };

    const getStatusColor = (status) => {
        const colors = {
            created: 'bg-gray-100 text-gray-800',
            shipped: 'bg-blue-100 text-blue-800',
            in_transit: 'bg-yellow-100 text-yellow-800',
            delivered: 'bg-green-100 text-green-800',
            returned: 'bg-red-100 text-red-800',
            failed: 'bg-red-100 text-red-800',
        };
        return colors[status] || 'bg-gray-100 text-gray-800';
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString();
    };

    // Debug: Log orders data (remove after debugging)
    // console.log('Orders data:', orders);
    // console.log('First order vouchers:', orders?.data?.[0]?.vouchers);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Orders</h2>}
        >
            <Head title="Orders" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order ID</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shop</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Voucher/Tracking</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {orders.data.map((order) => (
                                        <tr key={order.id}>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {order.external_order_id}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {order.shop?.name || '-'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                {order.total_amount ? `${order.total_amount} ${order.currency}` : '-'}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-900">
                                                {order.vouchers && Array.isArray(order.vouchers) && order.vouchers.length > 0 ? (
                                                    <div className="space-y-3">
                                                        {order.vouchers.map((voucher) => {
                                                            const isExpanded = expandedVouchers.has(voucher.id);
                                                            const hasHistory = voucher.courier_events && Array.isArray(voucher.courier_events) && voucher.courier_events.length > 0;
                                                            
                                                            return (
                                                                <div key={voucher.id} className="border rounded-lg p-3 bg-gray-50">
                                                                    {/* Voucher Header */}
                                                                    <div className="flex items-center justify-between gap-2 mb-2">
                                                                        <div className="flex items-center gap-2 flex-1">
                                                                            {voucher.tracking_url ? (
                                                                                <a
                                                                                    href={voucher.tracking_url}
                                                                                    target="_blank"
                                                                                    rel="noopener noreferrer"
                                                                                    className="text-blue-600 hover:text-blue-800 underline flex items-center gap-1"
                                                                                >
                                                                                    <span className="font-mono text-sm font-semibold">{voucher.voucher_number}</span>
                                                                                    <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                                                    </svg>
                                                                                </a>
                                                                            ) : (
                                                                                <span className="font-mono text-sm font-semibold">{voucher.voucher_number}</span>
                                                                            )}
                                                                            <button
                                                                                onClick={() => copyToClipboard(voucher.voucher_number, voucher.id)}
                                                                                className="text-gray-500 hover:text-gray-700 transition-colors"
                                                                                title="Copy voucher number"
                                                                            >
                                                                                {copiedVoucher === voucher.id ? (
                                                                                    <svg className="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                                                    </svg>
                                                                                ) : (
                                                                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                                                    </svg>
                                                                                )}
                                                                            </button>
                                                                            {voucher.courier_name && (
                                                                                <span className="text-xs text-gray-500 bg-white px-2 py-0.5 rounded">({voucher.courier_name})</span>
                                                                            )}
                                                                            {voucher.status && (
                                                                                <span className={`text-xs px-2 py-0.5 rounded font-medium ${getStatusColor(voucher.status)}`}>
                                                                                    {voucher.status.replace('_', ' ')}
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                        {hasHistory && (
                                                                            <button
                                                                                onClick={() => toggleVoucherHistory(voucher.id)}
                                                                                className="text-xs text-blue-600 hover:text-blue-800 flex items-center gap-1"
                                                                            >
                                                                                {isExpanded ? 'Hide' : 'Show'} History
                                                                                <svg className={`w-4 h-4 transition-transform ${isExpanded ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                                                                </svg>
                                                                            </button>
                                                                        )}
                                                                    </div>

                                                                    {/* Voucher Status Timeline */}
                                                                    <div className="text-xs text-gray-600 space-y-1">
                                                                        {voucher.created_at && (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-2 h-2 bg-gray-400 rounded-full"></span>
                                                                                <span>Created: {formatDate(voucher.created_at)}</span>
                                                                            </div>
                                                                        )}
                                                                        {voucher.shipped_at && (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-2 h-2 bg-blue-400 rounded-full"></span>
                                                                                <span>Shipped: {formatDate(voucher.shipped_at)}</span>
                                                                            </div>
                                                                        )}
                                                                        {voucher.delivered_at && (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-2 h-2 bg-green-400 rounded-full"></span>
                                                                                <span className="font-medium text-green-700">Delivered: {formatDate(voucher.delivered_at)}</span>
                                                                            </div>
                                                                        )}
                                                                        {voucher.returned_at && (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-2 h-2 bg-red-400 rounded-full"></span>
                                                                                <span className="font-medium text-red-700">Returned: {formatDate(voucher.returned_at)}</span>
                                                                            </div>
                                                                        )}
                                                                        {voucher.failed_at && (
                                                                            <div className="flex items-center gap-2">
                                                                                <span className="w-2 h-2 bg-red-400 rounded-full"></span>
                                                                                <span className="font-medium text-red-700">Failed: {formatDate(voucher.failed_at)}</span>
                                                                            </div>
                                                                        )}
                                                                        {voucher.updated_at && voucher.updated_at !== voucher.created_at && (
                                                                            <div className="flex items-center gap-2 text-gray-400">
                                                                                <span className="w-2 h-2 bg-gray-300 rounded-full"></span>
                                                                                <span>Last updated: {formatDate(voucher.updated_at)}</span>
                                                                            </div>
                                                                        )}
                                                                    </div>

                                                                    {/* Courier Events History (Expandable) */}
                                                                    {isExpanded && hasHistory && (
                                                                        <div className="mt-3 pt-3 border-t border-gray-200">
                                                                            <h4 className="text-xs font-semibold text-gray-700 mb-2">Courier Events Timeline</h4>
                                                                            <div className="space-y-2">
                                                                                {voucher.courier_events
                                                                                    .sort((a, b) => new Date(b.event_time) - new Date(a.event_time))
                                                                                    .map((event, idx) => (
                                                                                        <div key={idx} className="bg-white rounded p-2 border-l-2 border-blue-400">
                                                                                            <div className="flex items-start justify-between gap-2">
                                                                                                <div className="flex-1">
                                                                                                    <div className="font-medium text-xs text-gray-900">
                                                                                                        {event.event_description || event.event_code || 'Event'}
                                                                                                    </div>
                                                                                                    {event.location && (
                                                                                                        <div className="text-xs text-gray-600 mt-0.5">
                                                                                                            📍 {event.location}
                                                                                                        </div>
                                                                                                    )}
                                                                                                    {event.event_time && (
                                                                                                        <div className="text-xs text-gray-500 mt-0.5">
                                                                                                            {formatDate(event.event_time)}
                                                                                                        </div>
                                                                                                    )}
                                                                                                </div>
                                                                                                {event.event_code && (
                                                                                                    <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded">
                                                                                                        {event.event_code}
                                                                                                    </span>
                                                                                                )}
                                                                                            </div>
                                                                                        </div>
                                                                                    ))}
                                                                            </div>
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            );
                                                        })}
                                                    </div>
                                                ) : (
                                                    <span className="text-gray-400">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {order.ordered_at ? new Date(order.ordered_at).toLocaleDateString() : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

