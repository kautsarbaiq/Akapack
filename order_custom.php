<?php

declare(strict_types=1);

// Simple POST handling and validation
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$errors = [];
$values = [
    'packaging_type' => '',
    'material' => '',
    'width' => '',
    'height' => '',
    'depth' => '',
    'color_option' => 'Full Color (CMYK)',
    'logo_placement' => 'Center',
    'quantity' => '',
    'notes' => '',
    'contact_name' => '',
    'contact_phone' => '',
    'contact_email' => ''
];

if ($isPost) {
    foreach ($values as $k => $_) {
        if (isset($_POST[$k])) {
            $values[$k] = trim((string)$_POST[$k]);
        }
    }

    // Required fields
    $required = ['packaging_type', 'material', 'width', 'height', 'depth', 'quantity', 'contact_name', 'contact_phone'];
    foreach ($required as $field) {
        if ($values[$field] === '') {
            $errors[$field] = 'This field is required.';
        }
    }

    // Numeric validations
    foreach (['width', 'height', 'depth'] as $dim) {
        if ($values[$dim] !== '' && (!is_numeric($values[$dim]) || (float)$values[$dim] <= 0)) {
            $errors[$dim] = 'Please enter a valid positive number.';
        }
    }
    if ($values['quantity'] !== '' && (!ctype_digit($values['quantity']) || (int)$values['quantity'] <= 0)) {
        $errors['quantity'] = 'Please enter a valid quantity.';
    }

    // File validation (optional)
    $allowedExt = ['jpg', 'jpeg', 'png', 'pdf'];
    if (!empty($_FILES['design_file']['name'])) {
        $fileName = (string)$_FILES['design_file']['name'];
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $errors['design_file'] = 'Allowed file types: JPG, PNG, PDF.';
        }
    }
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Akapack — Custom Packaging Order</title>
    <meta name="description" content="Order custom packaging printing: choose type, material, size, and upload your design." />
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
            <div class="max-w-3xl">
                <h1 class="text-2xl sm:text-3xl font-bold text-slate-900">Custom Packaging Printing Order</h1>
                <p class="mt-2 text-sm text-slate-600">Fill in your requirements below. You can submit the form or get a quick price estimate.</p>
            </div>

            <?php if ($isPost && empty($errors)): ?>
                <div class="mt-6 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    Thank you, <?= h($values['contact_name']) ?>! Your request has been received. We will contact you at <?= h($values['contact_phone']) ?><?php if ($values['contact_email']): ?> / <?= h($values['contact_email']) ?><?php endif; ?>.
                </div>
            <?php elseif ($isPost && $errors): ?>
                <div class="mt-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    Please fix the highlighted fields and try again.
                </div>
            <?php endif; ?>

            <form class="mt-8 grid grid-cols-1 gap-6" action="" method="post" enctype="multipart/form-data" novalidate>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Type of Packaging</label>
                        <select name="packaging_type" class="mt-2 block w-full rounded-md border <?= isset($errors['packaging_type']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                            <option value="">Select type...</option>
                            <?php foreach (['Box', 'Bottle', 'Bag'] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $values['packaging_type'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['packaging_type'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['packaging_type']) ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Material</label>
                        <select name="material" class="mt-2 block w-full rounded-md border <?= isset($errors['material']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                            <option value="">Select material...</option>
                            <?php foreach (['Paper', 'Plastic', 'Cardboard'] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $values['material'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['material'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['material']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-900">Custom Dimensions (mm)</label>
                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <input type="text" inputmode="decimal" name="width" placeholder="Width" value="<?= h($values['width']) ?>" class="block w-full rounded-md border <?= isset($errors['width']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                                <span class="text-xs text-slate-500">mm</span>
                            </div>
                            <?php if (isset($errors['width'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['width']) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <input type="text" inputmode="decimal" name="height" placeholder="Height" value="<?= h($values['height']) ?>" class="block w-full rounded-md border <?= isset($errors['height']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                                <span class="text-xs text-slate-500">mm</span>
                            </div>
                            <?php if (isset($errors['height'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['height']) ?></p><?php endif; ?>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <input type="text" inputmode="decimal" name="depth" placeholder="Depth" value="<?= h($values['depth']) ?>" class="block w-full rounded-md border <?= isset($errors['depth']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                                <span class="text-xs text-slate-500">mm</span>
                            </div>
                            <?php if (isset($errors['depth'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['depth']) ?></p><?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-900">Upload Design (JPG, PNG, PDF)</label>
                    <input type="file" name="design_file" accept=".jpg,.jpeg,.png,.pdf" class="mt-2 block w-full text-sm text-slate-700 file:mr-4 file:rounded-md file:border-0 file:bg-brand file:px-4 file:py-2 file:text-white hover:file:bg-brand-dark" />
                    <?php if (isset($errors['design_file'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['design_file']) ?></p><?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Color</label>
                        <select name="color_option" class="mt-2 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                            <?php foreach (["Full Color (CMYK)", "2 Colors", "1 Color"] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $values['color_option'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Logo Placement</label>
                        <select name="logo_placement" class="mt-2 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand">
                            <?php foreach (["Center", "Top", "Bottom", "Custom"] as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= $values['logo_placement'] === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Quantity</label>
                        <input type="number" min="1" step="1" name="quantity" placeholder="e.g., 1000" value="<?= h($values['quantity']) ?>" class="mt-2 block w-full rounded-md border <?= isset($errors['quantity']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                        <?php if (isset($errors['quantity'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['quantity']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-900">Additional Notes (optional)</label>
                    <textarea name="notes" rows="4" class="mt-2 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" placeholder="Tell us about finishes (matte/glossy), window/foil, or special requests."><?= h($values['notes']) ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Your Name</label>
                        <input type="text" name="contact_name" value="<?= h($values['contact_name']) ?>" class="mt-2 block w-full rounded-md border <?= isset($errors['contact_name']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                        <?php if (isset($errors['contact_name'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['contact_name']) ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900">WhatsApp / Phone</label>
                        <input type="text" name="contact_phone" value="<?= h($values['contact_phone']) ?>" class="mt-2 block w-full rounded-md border <?= isset($errors['contact_phone']) ? 'border-red-300' : 'border-slate-300' ?> bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                        <?php if (isset($errors['contact_phone'])): ?><p class="mt-1 text-xs text-red-600"><?= h($errors['contact_phone']) ?></p><?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-900">Email (optional)</label>
                        <input type="email" name="contact_email" value="<?= h($values['contact_email']) ?>" class="mt-2 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-brand focus:ring-brand" />
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" name="submit" value="1" class="inline-flex items-center rounded-md bg-brand px-5 py-2.5 text-white font-semibold hover:bg-brand-dark">Submit Order</button>
                    <button type="button" id="estimateBtn" class="inline-flex items-center rounded-md border border-brand px-5 py-2.5 text-brand font-semibold hover:bg-brand/5">Get Price Estimate</button>
                    <span class="text-xs text-slate-500">Estimate is approximate and may change after artwork review.</span>
                </div>
            </form>

            <!-- Estimate Result -->
            <div id="estimateCard" class="hidden mt-6 rounded-lg border border-slate-200 bg-white p-4">
                <h2 class="text-sm font-semibold text-slate-900">Estimated Price</h2>
                <p id="estimateText" class="mt-2 text-sm text-slate-700"></p>
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

    <script>
        const number = (v) => {
            const n = parseFloat(String(v).replace(/,/g, '.'));
            return isNaN(n) ? 0 : n;
        };

        const formatIDR = (n) => new Intl.NumberFormat('id-ID', {
            style: 'currency',
            currency: 'IDR',
            maximumFractionDigits: 0
        }).format(Math.round(n));

        document.getElementById('estimateBtn')?.addEventListener('click', () => {
            const type = (document.querySelector('[name="packaging_type"]').value || '').toLowerCase();
            const material = (document.querySelector('[name="material"]').value || '').toLowerCase();
            const color = (document.querySelector('[name="color_option"]').value || '').toLowerCase();
            const placement = (document.querySelector('[name="logo_placement"]').value || '').toLowerCase();
            const w = number(document.querySelector('[name="width"]').value) / 10; // convert mm -> cm
            const h = number(document.querySelector('[name="height"]').value) / 10;
            const d = number(document.querySelector('[name="depth"]').value) / 10;
            const qty = Math.max(1, Math.floor(number(document.querySelector('[name="quantity"]').value)) || 1);

            // Base prices by type (per unit)
            let base = 1500;
            if (type.includes('box')) base = 2000;
            else if (type.includes('bottle')) base = 2500;
            else if (type.includes('bag')) base = 1500;

            // Material multiplier
            let matMul = 1.0;
            if (material.includes('plastic')) matMul = 1.2;
            else if (material.includes('cardboard')) matMul = 1.4;
            else if (material.includes('paper')) matMul = 1.0;

            // Color multiplier
            let colorMul = 1.0;
            if (color.includes('full')) colorMul = 1.2;
            else if (color.includes('2')) colorMul = 1.1;
            else if (color.includes('1')) colorMul = 1.0;

            // Placement multiplier
            let placeMul = 1.0;
            if (placement.includes('custom')) placeMul = 1.1;

            // Size factor (simple volume proxy)
            const volume = Math.max(1, w * h * Math.max(1, d));
            const sizeMul = Math.min(3, 0.01 * volume + 0.8); // clamp multiplier

            // Bulk discount
            let bulkMul = 1.0;
            if (qty >= 10000) bulkMul = 0.7;
            else if (qty >= 5000) bulkMul = 0.8;
            else if (qty >= 1000) bulkMul = 0.9;

            const unit = base * matMul * colorMul * placeMul * sizeMul * bulkMul;
            const total = unit * qty;

            const card = document.getElementById('estimateCard');
            const text = document.getElementById('estimateText');
            text.textContent = `Estimated unit price: ${formatIDR(unit)} — Estimated total: ${formatIDR(total)} for ${qty} pcs (approx.)`;
            card.classList.remove('hidden');
            card.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        });
    </script>
</body>

</html>