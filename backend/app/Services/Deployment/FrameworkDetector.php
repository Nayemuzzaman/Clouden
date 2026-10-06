<?php

namespace App\Services\Deployment;

/**
 * Optional framework detection. PrivateCloud V1 always deploys with a Dockerfile;
 * detection is only used to give a helpful hint (and a starter Dockerfile in the
 * docs) when one is missing.
 */
final class FrameworkDetector
{
    /**
     * @param  list<string>  $files  file names in the repository root
     * @return array{framework: ?string, has_dockerfile: bool}
     */
    public static function detect(array $files, ?string $packageJson = null): array
    {
        $has = fn (string $f) => in_array($f, $files, true);
        $framework = null;

        if ($has('artisan') && $has('composer.json')) {
            $framework = 'laravel';
        } elseif ($has('package.json')) {
            $framework = 'node';
            if ($packageJson !== null) {
                $pkg = json_decode($packageJson, true) ?: [];
                $deps = array_merge($pkg['dependencies'] ?? [], $pkg['devDependencies'] ?? []);
                $framework = match (true) {
                    isset($deps['next']) => 'nextjs',
                    isset($deps['vite']), isset($deps['react-scripts']) => isset($deps['react']) ? 'react' : 'static',
                    default => 'node',
                };
            } elseif ($has('next.config.js') || $has('next.config.mjs') || $has('next.config.ts')) {
                $framework = 'nextjs';
            }
        } elseif ($has('requirements.txt') || $has('pyproject.toml')) {
            $framework = 'python';
        } elseif ($has('composer.json')) {
            $framework = 'php';
        } elseif ($has('index.html')) {
            $framework = 'static';
        }

        return ['framework' => $framework, 'has_dockerfile' => $has('Dockerfile')];
    }

    public static function hintFor(string $directory): ?string
    {
        $files = array_map('basename', glob($directory.'/*') ?: []);
        $pkg = is_file($directory.'/package.json') ? (string) file_get_contents($directory.'/package.json') : null;
        $detected = self::detect($files, $pkg)['framework'];

        return $detected === null ? null : sprintf(
            'This looks like a %s project — see docs/deployment.md for a starter Dockerfile.',
            ['laravel' => 'Laravel', 'nextjs' => 'Next.js', 'react' => 'React', 'node' => 'Node.js', 'python' => 'Python', 'php' => 'PHP', 'static' => 'static website'][$detected],
        );
    }
}
