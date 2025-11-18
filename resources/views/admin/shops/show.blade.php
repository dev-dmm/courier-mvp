@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto py-8 px-4">
    <div class="mb-6">
        <a href="{{ route('admin.shops.index') }}" class="text-blue-600 hover:text-blue-800">
            ← Back to Shops
        </a>
    </div>

    <h1 class="text-3xl font-bold mb-2">{{ $shop->name }}</h1>
    @if($shop->slug)
        <p class="text-gray-600 mb-8">Slug: {{ $shop->slug }}</p>
    @endif

    @if (session('status'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
            {{ session('status') }}
        </div>
    @endif

    <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
        <div class="flex">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-yellow-700">
                    <strong>Important:</strong> Keep these credentials secure. Only admins with access to this shop can see them.
                </p>
            </div>
        </div>
    </div>

    <div class="bg-white shadow-md rounded-lg p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">API Credentials</h2>
        
        <div class="mb-6">
            <label class="block text-gray-700 text-sm font-bold mb-2">
                API URL
            </label>
            <div class="flex items-center">
                <input 
                    type="text" 
                    value="{{ config('app.url') }}/api" 
                    readonly
                    id="api-url"
                    class="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 focus:outline-none"
                >
                <button 
                    onclick="copyToClipboard('api-url')"
                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-r focus:outline-none focus:shadow-outline"
                    title="Copy to clipboard"
                >
                    Copy
                </button>
            </div>
            <p class="text-gray-600 text-xs italic mt-1">e.g. https://your-hub-domain.com/api</p>
        </div>

        <div class="mb-6">
            <label class="block text-gray-700 text-sm font-bold mb-2">
                API Key
            </label>
            <div class="flex items-center">
                <input 
                    type="text" 
                    value="{{ $shop->api_key }}" 
                    readonly
                    id="api-key"
                    class="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 font-mono text-sm focus:outline-none"
                >
                <button 
                    onclick="copyToClipboard('api-key')"
                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-r focus:outline-none focus:shadow-outline"
                    title="Copy to clipboard"
                >
                    Copy
                </button>
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-gray-700 text-sm font-bold mb-2">
                API Secret
            </label>
            <div class="flex items-center">
                <input 
                    type="text" 
                    value="{{ $shop->api_secret }}" 
                    readonly
                    id="api-secret"
                    class="shadow appearance-none border rounded-l w-full py-2 px-3 text-gray-700 bg-gray-100 font-mono text-sm focus:outline-none"
                >
                <button 
                    onclick="copyToClipboard('api-secret')"
                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-r focus:outline-none focus:shadow-outline"
                    title="Copy to clipboard"
                >
                    Copy
                </button>
            </div>
            <p class="text-gray-600 text-xs italic mt-1">Keep this secret secure. Only admins can see it.</p>
        </div>
    </div>

    <div class="bg-blue-50 border-l-4 border-blue-400 p-4">
        <h3 class="font-semibold text-blue-900 mb-2">Next Steps:</h3>
        <ol class="list-decimal list-inside text-sm text-blue-800 space-y-1">
            <li>Install the WooCommerce plugin on your store</li>
            <li>Navigate to the plugin settings page</li>
            <li>Enter the API URL, API Key, and API Secret above</li>
            <li>Save the settings</li>
        </ol>
    </div>
</div>

<script>
function copyToClipboard(elementId) {
    const element = document.getElementById(elementId);
    element.select();
    element.setSelectionRange(0, 99999); // For mobile devices
    
    try {
        document.execCommand('copy');
        
        // Visual feedback
        const button = element.nextElementSibling;
        const originalText = button.textContent;
        button.textContent = 'Copied!';
        button.classList.add('bg-green-500', 'hover:bg-green-600');
        button.classList.remove('bg-gray-500', 'hover:bg-gray-700');
        
        setTimeout(() => {
            button.textContent = originalText;
            button.classList.remove('bg-green-500', 'hover:bg-green-600');
            button.classList.add('bg-gray-500', 'hover:bg-gray-700');
        }, 2000);
    } catch (err) {
        console.error('Failed to copy:', err);
    }
}
</script>
@endsection

