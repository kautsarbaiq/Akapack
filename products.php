<?php

declare(strict_types=1);

$allProducts = [
    [
        'id' => 'sp-250',
        'name' => 'Standing Pouch 250g',
        'type' => 'Standing Pouch',
        'material' => 'Paperfoil',
        'price' => 18000,
        'image' => 'https://images.unsplash.com/photo-1585386959984-a4155223168f?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Compact 250g pouch suitable for snacks and coffee.'
    ],
    [
        'id' => 'sp-500',
        'name' => 'Standing Pouch 500g',
        'type' => 'Standing Pouch',
        'material' => 'Kraft',
        'price' => 28000,
        'image' => 'https://images.unsplash.com/photo-1578916171728-46686eac8d58?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Versatile 500g pouch with resealable zip.'
    ],
    [
        'id' => 'sachet-10',
        'name' => 'Sachet 10ml',
        'type' => 'Sachet',
        'material' => 'Aluminum',
        'price' => 9000,
        'image' => 'https://images.unsplash.com/photo-1585238341986-c3f2b9e89861?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Single-serve sachet for sauces and condiments.'
    ],
    [
        'id' => 'zip-1l',
        'name' => 'Ziplock 1L',
        'type' => 'Ziplock',
        'material' => 'Plastic',
        'price' => 22000,
        'image' => 'https://images.unsplash.com/photo-1613478223719-81c4cf9c9022?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Durable 1L ziplock suitable for bulk snacks.'
    ],
    [
        'id' => 'box-mini',
        'name' => 'Box Mini',
        'type' => 'Box',
        'material' => 'Paperfoil',
        'price' => 35000,
        'image' => 'https://images.unsplash.com/photo-1607619056574-7b8d3ee536b2?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Rigid mini box for gifts and confectionery.'
    ],
    [
        'id' => 'sp-1kg',
        'name' => 'Standing Pouch 1kg',
        'type' => 'Standing Pouch',
        'material' => 'Aluminum',
        'price' => 54000,
        'image' => 'https://images.unsplash.com/photo-1613478223696-f2d5a8b3f3e9?q=80&w=1887&auto=format&fit=crop',
        'description' => 'High-capacity pouch ideal for grains and coffee.'
    ],
    [
        'id' => 'sachet-30',
        'name' => 'Sachet 30ml',
        'type' => 'Sachet',
        'material' => 'Plastic',
        'price' => 12000,
        'image' => 'https://images.unsplash.com/photo-1541647376583-8934aaf3448a?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Convenient 30ml sachet for sample packs.'
    ],
    [
        'id' => 'zip-2l',
        'name' => 'Ziplock 2L',
        'type' => 'Ziplock',
        'material' => 'Kraft',
        'price' => 42000,
        'image' => 'https://images.unsplash.com/photo-1602325077970-95d6d41c72ea?q=80&w=1887&auto=format&fit=crop',
        'description' => 'Large 2L ziplock with kraft finish.'
    ],
];

$types = ['Standing Pouch', 'Sachet', 'Ziplock', 'Box'];
$materials = ['Paperfoil', 'Kraft', 'Aluminum', 'Plastic'];
$priceRanges = [
    '0-25000' => [0, 25000],
    '25000-50000' => [25000, 50000],
    '50000-100000' => [50000, 100000],
    '100000+' => [100000, PHP_INT_MAX],
];

$selectedTypes = isset($_GET['type']) && is_array($_GET['type']) ? array_values(array_intersect($types, $_GET['type'])) : [];
$selectedMaterials = isset($_GET['material']) && is_array($_GET['material']) ? array_values(array_intersect($materials, $_GET['material'])) : [];
$selectedPriceKey = isset($_GET['price']) && array_key_exists($_GET['price'], $priceRanges) ? $_GET['price'] : '';

$filtered = array_values(array_filter($allProducts, function (array $p) use ($selectedTypes, $selectedMaterials, $selectedPriceKey, $priceRanges): bool {
    if ($selectedTypes && !in_array($p['type'], $selectedTypes, true)) {
        return false;
    }
    if ($selectedMaterials && !in_array($p['material'], $selectedMaterials, true)) {
        return false;
    }
    if ($selectedPriceKey) {
        [$min, $max] = $priceRanges[$selectedPriceKey];
        if ($p['price'] < $min || $p['price'] > $max) {
            return false;
        }
    }
    return true;
}));

function isChecked(array $haystack, string $needle): string
{
    return in_array($needle, $haystack, true) ? 'checked' : '';
}

function isPriceChecked(string $selected, string $key): string
{
    return $selected === $key ? 'checked' : '';
}

function formatIdr(int $amount): string
{
    return 'Rp ' . number_format($amount, 0, ',', '.');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Akapack — Products</title>
    <meta name="description" content="Browse packaging products: standing pouch, sachet, ziplock, and more." />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#f97316',
                            dark: '#ea580c'
                        }
                    }
                }
            }
        }
    </script>
</head>

<body class="bg-white text-slate-700 antialiased">
    <header class="border-b border-slate-100 bg-white/90 backdrop-blur">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <a href="/index.html" class="flex items-center gap-2">
                <span class="inline-flex h-9 w-9 items-center justify-center rounded-md bg-brand text-white font-bold">A</span>
                <span class="text-lg font-semibold text-slate-900">Akapack</span>
            </a>
            <nav class="hidden md:flex items-center gap-6 text-sm">
                <a href="/index.html#home" class="hover:text-brand">Home</a>
                <a href="/products.php" class="text-brand font-medium">Products</a>
                <a href="/index.html#printing" class="hover:text-brand">Printing Services</a>
                <a href="/index.html#about" class="hover:text-brand">About Us</a>
                <a href="/index.html#contact" class="hover:text-brand">Contact</a>
            </nav>
        </div>
    </header>

    <main class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="mb-6">
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Packaging Products</h1>
                <p class="mt-2 text-sm text-slate-600">Filter by type, material, or price range. Click a product to view details.</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
                <aside class="lg:col-span-1">
                    <form method="get" class="space-y-6 p-4 rounded-xl border border-slate-200">
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">By Type</h2>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($types as $t): ?>
                                    <label class="flex items-center gap-3 text-sm">
                                        <input type="checkbox" name="type[]" value="<?= htmlspecialchars($t) ?>" class="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand" <?= isChecked($selectedTypes, $t) ?> />
                                        <span><?= htmlspecialchars($t) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">By Material</h2>
                            <div class="mt-3 space-y-2">
                                <?php foreach ($materials as $m): ?>
                                    <label class="flex items-center gap-3 text-sm">
                                        <input type="checkbox" name="material[]" value="<?= htmlspecialchars($m) ?>" class="h-4 w-4 rounded border-slate-300 text-brand focus:ring-brand" <?= isChecked($selectedMaterials, $m) ?> />
                                        <span><?= htmlspecialchars($m) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">By Price Range</h2>
                            <div class="mt-3 space-y-2">
                                <?php foreach (array_keys($priceRanges) as $key): ?>
                                    <label class="flex items-center gap-3 text-sm">
                                        <input type="radio" name="price" value="<?= htmlspecialchars($key) ?>" class="h-4 w-4 border-slate-300 text-brand focus:ring-brand" <?= isPriceChecked($selectedPriceKey, $key) ?> />
                                        <span><?= htmlspecialchars($key) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <button type="submit" class="inline-flex items-center rounded-md bg-brand px-4 py-2 text-white font-semibold hover:bg-brand-dark">Apply Filters</button>
                            <a href="/products.php" class="text-sm text-slate-600 hover:text-brand">Reset</a>
                        </div>
                    </form>
                </aside>

                <section class="lg:col-span-3">
                    <?php if (!$filtered): ?>
                        <div class="rounded-lg border border-slate-200 p-6 text-sm text-slate-600">No products found. Adjust filters to see more results.</div>
                    <?php else: ?>
                        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-6">
                            <?php foreach ($filtered as $p): ?>
                                <article class="group rounded-xl border border-slate-200 hover:border-brand/40 shadow-sm hover:shadow-md transition overflow-hidden bg-white">
                                    <a href="/product.php?id=<?= urlencode($p['id']) ?>" class="block">
                                        <div class="aspect-[4/3] bg-slate-100 overflow-hidden">
                                            <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="h-full w-full object-cover group-hover:scale-105 transition duration-300" />
                                        </div>
                                    </a>
                                    <div class="p-4">
                                        <h3 class="text-sm font-semibold text-slate-900 line-clamp-1"><?= htmlspecialchars($p['name']) ?></h3>
                                        <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars($p['type']) ?> • <?= htmlspecialchars($p['material']) ?></p>
                                        <p class="mt-2 font-semibold text-slate-900"><?= htmlspecialchars(formatIdr($p['price'])) ?></p>
                                        <div class="mt-4 flex items-center justify-between">
                                            <a href="/product.php?id=<?= urlencode($p['id']) ?>" class="inline-flex items-center rounded-md bg-brand px-3 py-2 text-white text-sm font-medium hover:bg-brand-dark">View Details</a>
                                            <a href="/product.php?id=<?= urlencode($p['id']) ?>" class="text-sm text-brand hover:underline">Learn more →</a>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </main>

    <footer class="mt-16 bg-slate-900 text-slate-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 text-sm">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-md bg-brand text-white font-bold">A</span>
                <span class="text-white font-semibold">Akapack</span>
            </div>
            <p class="mt-3">© <?= date('Y') ?> Akapack. All rights reserved.</p>
        </div>
    </footer>
</body>

</html>