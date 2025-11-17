import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Dashboard({ auth, shops, stats }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Dashboard</h2>}
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-gray-900">
                                <div className="text-sm font-medium text-gray-500">Total Orders</div>
                                <div className="text-3xl font-bold">{stats.total_orders}</div>
                            </div>
                        </div>
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-gray-900">
                                <div className="text-sm font-medium text-gray-500">Total Customers</div>
                                <div className="text-3xl font-bold">{stats.total_customers}</div>
                            </div>
                        </div>
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-gray-900">
                                <div className="text-sm font-medium text-gray-500">Active Shops</div>
                                <div className="text-3xl font-bold">{shops.length}</div>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-semibold mb-4">Quick Links</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <Link
                                    href={route('admin.customers.index')}
                                    className="block p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
                                >
                                    <div className="font-medium">View Customers</div>
                                    <div className="text-sm text-gray-500">Browse and search customers</div>
                                </Link>
                                <Link
                                    href={route('admin.orders.index')}
                                    className="block p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
                                >
                                    <div className="font-medium">View Orders</div>
                                    <div className="text-sm text-gray-500">Browse all orders</div>
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

