<?php
/** Validate every staged byte again inside the WordPress container. No writes. */
function portfolio_import_read_package(string $directory): array {
    $root = realpath($directory);
    if (!$root || !is_dir($root)) { throw new RuntimeException('Package directory missing.'); }
    $raw = file_get_contents($root . '/package.json');
    $expected = trim((string) file_get_contents($root . '/package.sha256'));
    if (!$raw || !hash_equals(hash('sha256', $raw), $expected)) { throw new RuntimeException('Package checksum mismatch.'); }
    $package = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (($package['schema_version'] ?? null) !== 1 || !isset($package['media'], $package['icons'], $package['projects'])) {
        throw new RuntimeException('Unsupported package.');
    }
    foreach (array_merge(array_values($package['media']), array_values($package['icons'])) as $asset) {
        $file = realpath($root . '/' . $asset['file']);
        if (!$file || !str_starts_with($file, $root . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR)
            || !is_file($file) || filesize($file) !== $asset['bytes'] || !hash_equals($asset['sha256'], hash_file('sha256', $file))) {
            throw new RuntimeException('Missing, changed or unsafe asset: ' . $asset['file']);
        }
        if ($asset['mime'] === 'image/svg+xml') {
            portfolio_import_validate_svg(file_get_contents($file));
        } else {
            $info = getimagesize($file);
            if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || $info['mime'] !== $asset['mime']) {
                throw new RuntimeException('Invalid raster image: ' . $asset['file']);
            }
        }
    }
    return $package;
}

function portfolio_import_validate_svg(string $svg): void {
    if (strlen($svg) > 1024 * 1024 || preg_match('/<!\s*(DOCTYPE|ENTITY)|<\?/i', $svg)) {
        throw new RuntimeException('Oversized SVG or forbidden DTD/entities.');
    }
    if (!class_exists('DOMDocument')) { throw new RuntimeException('PHP DOM extension is required for safe SVG import.'); }
    $document = new DOMDocument();
    $prior = libxml_use_internal_errors(true);
    try {
        if (!$document->loadXML($svg, LIBXML_NONET)) { throw new RuntimeException('Invalid SVG XML.'); }
        $root = $document->documentElement;
        if (!$root || $root->tagName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg' || !$root->getAttribute('viewBox')) {
            throw new RuntimeException('Invalid SVG root/viewBox.');
        }
        $allowed = ['svg' => ['xmlns', 'viewBox', 'role'], 'title' => [], 'path' => ['fill', 'd']];
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || !isset($allowed[$node->tagName])) { throw new RuntimeException('Unsupported SVG element.'); }
            foreach ($node->attributes as $attribute) {
                if (!in_array($attribute->nodeName, $allowed[$node->tagName], true) || stripos($attribute->nodeValue, 'url(') !== false) {
                    throw new RuntimeException('Unsupported SVG attribute/reference.');
                }
            }
            if ($node->tagName === 'path' && ($node->getAttribute('fill') !== 'currentColor' || !$node->getAttribute('d'))) {
                throw new RuntimeException('SVG paths require a shape and currentColor fill.');
            }
        }
        foreach ($document->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) { throw new RuntimeException('SVG processing instructions forbidden.'); }
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
    }
}
