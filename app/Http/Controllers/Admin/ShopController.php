<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $shops = $user->shops()->paginate(20);

        return view('admin.shops.index', compact('shops'));
    }

    public function create()
    {
        return view('admin.shops.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:shops,slug',
        ]);

        $user = $request->user();

        $shop = Shop::create($data);

        // Attach current user as owner of this shop
        $user->shops()->attach($shop->id, ['role' => 'owner']);

        return redirect()
            ->route('admin.shops.show', $shop)
            ->with('status', 'Shop created successfully');
    }

    public function show(Request $request, Shop $shop)
    {
        // Make sure the user has access to this shop
        $user = $request->user();
        if (!$user->shops()->where('shops.id', $shop->id)->exists()) {
            abort(403);
        }

        // Temporarily make api_secret visible for display
        $shop->makeVisible('api_secret');

        return view('admin.shops.show', compact('shop'));
    }
}

