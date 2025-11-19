import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

export default function ShopsShow({ auth, shop }) {
    const [copied, setCopied] = useState('');

    const copyToClipboard = (text, id) => {
        navigator.clipboard.writeText(text).then(() => {
            setCopied(id);
            setTimeout(() => setCopied(''), 2000);
        });
    };

    const apiUrl = `${window.location.origin}/api`;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">{shop.name}</h2>}
        >
            <Head title={`Shop: ${shop.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="mb-6">
                        <Link
                            href={route('shops.index')}
                            className="text-indigo-600 hover:text-indigo-800"
                        >
                            ← Back to Shops
                        </Link>
                    </div>

                    {shop.slug && (
                        <p className="text-gray-600 mb-8">Slug: {shop.slug}</p>
                    )}

                    <div className="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <svg className="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                </svg>
                            </div>
                            <div className="ml-3">
                                <p className="text-sm text-yellow-700">
                                    <strong>Important:</strong> Keep these credentials secure. Only admins with access to this shop can see them.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white shadow-md rounded-lg p-6 mb-6">
                        <h2 className="text-xl font-semibold mb-4">API Credentials</h2>
                        
                        <div className="mb-6">
                            <label className="block text-gray-700 text-sm font-bold mb-2">
                                API URL
                            </label>
                            <div className="flex items-center">
                                <input
                                    type="text"
                                    value={apiUrl}
                                    readOnly
                                    className="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 focus:outline-none"
                                />
                                <button
                                    onClick={() => copyToClipboard(apiUrl, 'api-url')}
                                    className={`px-4 py-2 rounded-r font-bold text-white focus:outline-none ${
                                        copied === 'api-url' ? 'bg-green-500' : 'bg-gray-500 hover:bg-gray-700'
                                    }`}
                                >
                                    {copied === 'api-url' ? 'Copied!' : 'Copy'}
                                </button>
                            </div>
                            <p className="text-gray-600 text-xs italic mt-1">e.g. https://your-hub-domain.com/api</p>
                        </div>

                        <div className="mb-6">
                            <label className="block text-gray-700 text-sm font-bold mb-2">
                                API Key
                            </label>
                            <div className="flex items-center">
                                <input
                                    type="text"
                                    value={shop.api_key}
                                    readOnly
                                    className="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 font-mono text-sm focus:outline-none"
                                />
                                <button
                                    onClick={() => copyToClipboard(shop.api_key, 'api-key')}
                                    className={`px-4 py-2 rounded-r font-bold text-white focus:outline-none ${
                                        copied === 'api-key' ? 'bg-green-500' : 'bg-gray-500 hover:bg-gray-700'
                                    }`}
                                >
                                    {copied === 'api-key' ? 'Copied!' : 'Copy'}
                                </button>
                            </div>
                        </div>

                        <div className="mb-6">
                            <label className="block text-gray-700 text-sm font-bold mb-2">
                                API Secret
                            </label>
                            <div className="flex items-center">
                                <input
                                    type="text"
                                    value={shop.api_secret}
                                    readOnly
                                    className="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 font-mono text-sm focus:outline-none"
                                />
                                <button
                                    onClick={() => copyToClipboard(shop.api_secret, 'api-secret')}
                                    className={`px-4 py-2 rounded-r font-bold text-white focus:outline-none ${
                                        copied === 'api-secret' ? 'bg-green-500' : 'bg-gray-500 hover:bg-gray-700'
                                    }`}
                                >
                                    {copied === 'api-secret' ? 'Copied!' : 'Copy'}
                                </button>
                            </div>
                            <p className="text-gray-600 text-xs italic mt-1">Keep this secret secure. Only admins can see it.</p>
                        </div>
                    </div>

                    <div className="bg-blue-50 border-l-4 border-blue-400 p-4">
                        <h3 className="font-semibold text-blue-900 mb-2">Next Steps:</h3>
                        <ol className="list-decimal list-inside text-sm text-blue-800 space-y-1">
                            <li>Install the WooCommerce plugin on your store</li>
                            <li>Navigate to the plugin settings page</li>
                            <li>Enter the API URL, API Key, and API Secret above</li>
                            <li>Save the settings</li>
                        </ol>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

