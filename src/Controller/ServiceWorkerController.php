<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves /sw.js with its CACHE_VERSION derived from the assets themselves,
 * so a changed file can never ship behind a stale cache version again.
 *
 * The source lives in resources/ rather than public/ on purpose: public/ is
 * the docroot and .htaccess only routes requests for paths that don't exist
 * on disk, so a public/sw.js would be served statically and this controller
 * would never run.
 *
 * The hash deliberately covers every asset file, not just the ones listed
 * in the worker's PRECACHE_URLS. Parsing that list back out of the JS would
 * reintroduce exactly the failure mode this replaces - a silent mismatch
 * between what's cached and what the version reflects - and the cost of
 * over-covering is only that the app shell is re-fetched once after an
 * unrelated asset changes.
 */
final class ServiceWorkerController
{
    private const VERSION_PLACEHOLDER = '__CACHE_VERSION__';

    /** Relative to the project root. */
    private const HASHED_PATHS = [
        'public/assets',
        'public/manifest.json',
        'public/offline.html',
    ];

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $source = @file_get_contents($this->projectRoot . '/resources/sw.js');
        if ($source === false) {
            return $response->withStatus(404);
        }

        $body = str_replace(self::VERSION_PLACEHOLDER, $this->assetsVersion(), $source);
        $response->getBody()->write($body);

        return $response
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            // Must revalidate every time: this response is what tells the
            // browser a new version exists, so caching it would defeat the
            // whole mechanism.
            ->withHeader('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * Short, stable digest of every asset's contents. Path is hashed
     * alongside content so a rename alone still produces a new version.
     */
    private function assetsVersion(): string
    {
        $context = hash_init('xxh128');
        foreach ($this->assetFiles() as $file) {
            hash_update($context, $file);
            hash_update_file($context, $this->projectRoot . '/' . $file);
        }
        return substr(hash_final($context), 0, 12);
    }

    /**
     * @return list<string> project-root-relative paths, sorted so the digest
     *         doesn't depend on filesystem iteration order
     */
    private function assetFiles(): array
    {
        $files = [];
        foreach (self::HASHED_PATHS as $path) {
            $absolute = $this->projectRoot . '/' . $path;
            if (is_file($absolute)) {
                $files[] = $path;
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
            );
            /** @var \SplFileInfo $item */
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $files[] = $path . '/' . str_replace(
                        '\\',
                        '/',
                        substr($item->getPathname(), strlen($absolute) + 1),
                    );
                }
            }
        }

        sort($files);
        return $files;
    }
}
