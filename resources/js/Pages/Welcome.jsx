import { Head } from '@inertiajs/react';

export default function Welcome({ auth, laravelVersion, phpVersion }) {
    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen items-center justify-center bg-white">
                <div className="text-center">
                    <h1 className="text-4xl font-semibold text-gray-900">
                        This is Courier MVP
                    </h1>
                </div>
            </div>
        </>
    );
}
