import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import RiskScoreBadge from '@/Components/RiskScoreBadge';

export default function CustomerShow({ auth, customer, orders, riskLevel }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Customer Details</h2>
                    <Link
                        href={route('admin.customers.index')}
                        className="text-sm text-gray-600 hover:text-gray-900"
                    >
                        ← Back to Customers
                    </Link>
                </div>
            }
        >
            <Head title="Customer Details" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold mb-4">Customer Information</h3>
                            <dl className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <dt className="text-sm font-medium text-gray-500">Hash</dt>
                                    <dd className="mt-1 text-sm text-gray-900 font-mono">{customer.customer_hash}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    {customer.stats && (
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                            <div className="p-6">
                                <h3 className="text-lg font-semibold mb-4">Statistics</h3>
                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <div>
                                        <div className="text-sm text-gray-500">Total Orders</div>
                                        <div className="text-2xl font-bold">{customer.stats.total_orders}</div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-500">Returns</div>
                                        <div className="text-2xl font-bold">{customer.stats.returns || 0}</div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-gray-500">Late Deliveries</div>
                                        <div className="text-2xl font-bold">{customer.stats.late_deliveries || 0}</div>
                                    </div>
                                </div>
                                <div className="mt-4">
                                    <RiskScoreBadge
                                        score={customer.stats.delivery_risk_score}
                                        level={riskLevel}
                                    />
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold mb-4">Orders</h3>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order ID</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Shop</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
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
                                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span className="px-2 py-1 rounded-full text-xs bg-gray-100">
                                                        {order.status}
                                                    </span>
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
            </div>
        </AuthenticatedLayout>
    );
}

