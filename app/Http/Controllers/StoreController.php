<?php

namespace App\Http\Controllers;

use App\Models\ProductModel;
use App\Models\Review;
use App\Services\CatalogService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class StoreController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    public function home()
    {
        $all = $this->catalog->matching([]);
        $featured = $all->filter(fn ($s) => $s['model']->featured)->take(4);
        $deals = $all->filter(fn ($s) => $s['model']->discount_percent > 0)->take(4);
        $recent = $all->sortByDesc(fn ($s) => $s['model']->created_at)->take(4);
        $reviews = Review::where('approved', true)->with('user', 'model')->latest()->take(3)->get();

        return view('store.home', compact('featured', 'deals', 'recent', 'reviews'));
    }

    public function shop(Request $r)
    {
        $filters = $r->validate(['brand' => 'sometimes|array', 'brand.*' => 'string|max:60', 'category' => 'nullable|string|max:60', 'q' => 'nullable|string|max:100', 'processor' => 'sometimes|array', 'processor.*' => 'in:i3,i5,i7,i9,Ryzen', 'generation' => 'nullable|integer|min:1|max:30', 'ram' => 'sometimes|array', 'ram.*' => 'integer|min:4|max:128', 'ssd' => 'sometimes|array', 'ssd.*' => 'integer|min:64|max:8192', 'screen' => 'sometimes|array', 'screen.*' => 'numeric|min:10|max:18', 'grade' => 'sometimes|array', 'grade.*' => 'in:A,B,C', 'min_price' => 'nullable|numeric|min:0', 'max_price' => 'nullable|numeric|min:0', 'sort' => 'nullable|in:featured,price_asc,price_desc,newest,best_selling,discount', 'in_stock' => 'nullable|boolean', 'touchscreen' => 'nullable|boolean', 'graphics' => 'nullable|boolean', 'keyboard' => 'nullable|in:QWERTY,AZERTY', 'battery_health' => 'nullable|integer|min:0|max:100', 'resolution' => 'nullable|in:HD,Full HD,4K', 'os' => 'nullable|in:Windows 11 Pro,Linux,No OS', 'deals' => 'nullable|boolean', 'page' => 'nullable|integer|min:1']);
        $all = $this->catalog->matching($filters)->values();
        $page = max(1, (int) $r->input('page', 1));
        $products = new LengthAwarePaginator($all->slice(($page - 1) * 12, 12)->values(), $all->count(), 12, $page, ['path' => $r->url(), 'query' => $r->query()]);

        return view('store.shop', compact('products', 'filters'));
    }

    public function product(Request $r, string $locale, ProductModel $product)
    {
        abort_unless($product->published, 404);
        $product->load(['units' => fn ($q) => $q->where('status', 'available')->with('tests'), 'reviews' => fn ($q) => $q->where('approved', true)->with('user')]);
        $summary = $this->catalog->summary($product);
        $configuration = $summary['configuration'];
        if ($configuration && $r->has('ram_gb')) {
            $configuration = $this->catalog->configuration($product, array_merge($configuration, $r->only(array_keys($configuration))));
        }
        try {
            $quote = $configuration ? $this->catalog->quote($product, $configuration) : null;
        } catch (ValidationException) {
            $configuration = $summary['configuration'];
            $quote = $configuration ? $this->catalog->quote($product, $configuration) : null;
        }
        $similar = $this->catalog->matching(['category' => $product->category, 'in_stock' => 1])->reject(fn ($s) => $s['model']->id === $product->id)->take(4);

        return view('store.product', compact('product', 'summary', 'configuration', 'quote', 'similar'));
    }

    public function quote(Request $r, string $locale, ProductModel $product)
    {
        $quantity = (int) $r->input('quantity', 1);
        abort_if($quantity < 1 || $quantity > 20, 422);
        $c = $this->catalog->configuration($product, $r->all());
        $availability = $this->catalog->availability($product, $c);
        try {
            $quote = $this->catalog->quote($product, $c, $quantity);

            return response()->json(array_merge($quote, ['availability' => $availability]));
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors(), 'availability' => $availability], 422);
        }
    }

    public function suggestions(Request $r)
    {
        $term = $r->validate(['q' => 'required|string|max:100'])['q'];
        $products = $this->catalog->matching(['q' => $term, 'in_stock' => 1])->take(5);

        return response()->json($products->map(fn ($s) => ['name' => $s['model']->brand.' '.$s['model']->name, 'image' => $s['model']->image(), 'price' => Money::format($s['price_cents']), 'url' => route('products.show', $s['model'])])->values());
    }

    public function compare(Request $r)
    {
        $ids = array_slice(array_filter(explode(',', $r->input('ids', '')), fn ($id) => ctype_digit($id)), 0, 4);
        $products = ProductModel::where('published', true)->whereIn('id', $ids)->with('units')->get()->map(fn ($m) => $this->catalog->summary($m));

        return view('store.compare', compact('products'));
    }

    public function recent(Request $r)
    {
        $d = $r->validate(['ids' => 'required|string|max:100']);
        $ids = array_slice(array_filter(explode(',', $d['ids']), fn ($id) => ctype_digit($id)), 0, 8);
        $products = ProductModel::where('published', true)->whereIn('id', $ids)->with('units')->get()->sortBy(fn ($m) => array_search($m->id, $ids))->map(fn ($m) => $this->catalog->summary($m));

        return view('components.recent-products', compact('products'));
    }

    public function finder(Request $r)
    {
        $filters = [];
        if ($r->filled('budget')) {
            $filters['max_price'] = max(100, min(2000, (int) $r->budget));
        }if ($r->filled('ram')) {
            $filters['ram'] = array_values(array_filter([8, 16, 32, 64], fn ($v) => $v >= (int) $r->ram));
        }if ($r->filled('screen')) {
            $filters['screen'] = [$r->screen];
        }if ($r->filled('brand')) {
            $filters['brand'] = [$r->brand];
        }if (in_array($r->input('use'), ['student', 'business', 'everyday'], true)) {
            $filters['category'] = $r->input('use');
        }$filters['in_stock'] = 1;
        $products = $r->has('budget') ? $this->catalog->matching($filters)->when($r->boolean('portable'), fn ($results) => $results->sortBy(fn ($s) => $s['unit']?->screen_inches ?? 99))->take(6) : collect();

        return view('store.finder', compact('products'));
    }

    public function sitemap()
    {
        $urls = [];
        foreach (config('revilo.locales') as $locale) {
            foreach (['home', 'shop', 'about', 'contact', 'faq', 'shipping', 'warranty', 'process', 'condition', 'business', 'deals'] as $route) {
                $urls[] = route($route, ['locale' => $locale]);
            }foreach (ProductModel::where('published', true)->get() as $p) {
                $urls[] = route('products.show', ['locale' => $locale, 'product' => $p]);
            }
        }

        return response(view('seo.sitemap', compact('urls')), 200, ['Content-Type' => 'application/xml']);
    }
}
