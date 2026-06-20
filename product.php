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

$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$product = null;
foreach ($allProducts as $p) {
    if ($p['id'] === $id) {
        $product = $p;
        break;
    }
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
    <title><?= $product ? htmlspecialchars($product['name']) . ' — Akapack' : 'Product Not Found — Akapack' ?></title>
    <meta name="description" content="Product details and specifications." />
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
                <a href="/products.php" class="hover:text-brand">Products</a>
                <a href="/index.html#printing" class="hover:text-brand">Printing Services</a>
                <a href="/index.html#about" class="hover:text-brand">About Us</a>
                <a href="/index.html#contact" class="hover:text-brand">Contact</a>
            </nav>
        </div>
    </header>

    <main class="py-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <?php if (!$product): ?>
                <div class="rounded-lg border border-slate-200 p-8 text-center">
                    <h1 class="text-xl font-semibold text-slate-900">Product Not Found</h1>
                    <p class="mt-2 text-sm text-slate-600">The product you are looking for does not exist or has been removed.</p>
                    <div class="mt-6"><a href="/products.php" class="inline-flex items-center rounded-md bg-brand px-4 py-2 text-white font-semibold hover:bg-brand-dark">Back to Products</a></div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">
                    <div class="rounded-xl overflow-hidden border border-slate-200 bg-slate-50">
                        <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-full h-full object-cover" />
                    </div>
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-slate-900"><?= htmlspecialchars($product['name']) ?></h1>
                        <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars($product['type']) ?> • <?= htmlspecialchars($product['material']) ?></p>
                        <p class="mt-4 text-2xl font-bold text-slate-900"><?= htmlspecialchars(formatIdr((int)$product['price'])) ?></p>
                        <p class="mt-4 text-sm text-slate-700"><?= htmlspecialchars($product['description']) ?></p>
                        <div class="mt-6 flex flex-wrap items-center gap-3">
                            <a href="/products.php" class="inline-flex items-center rounded-md border border-brand text-brand px-4 py-2 font-semibold hover:bg-brand/5">Back to Products</a>
                            <a href="/index.html#contact" class="inline-flex items-center rounded-md bg-brand px-4 py-2 text-white font-semibold hover:bg-brand-dark">Get a Quote</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
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