<?php

namespace App\Command;

use App\Repository\EtfRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[AsCommand(
    name: 'app:static-export',
    description: 'Generate a static read-only export of the home page and ETF pages.',
)]
class StaticExportCommand extends Command
{
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly EtfRepository $etfRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory.', 'static-site')
            ->addOption('base-path', null, InputOption::VALUE_REQUIRED, 'Public base path, for example "/tradeIA" on GitHub Pages.', '')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = $this->absolutePath((string) $input->getOption('output'));
        $basePath = $this->normalizeBasePath((string) $input->getOption('base-path'));

        $this->resetDirectory($outputDir);
        $this->copyDirectory($this->projectDir.'/public/assets', $outputDir.'/assets');

        $pages = [
            ['path' => '/', 'target' => 'index.html'],
        ];

        foreach ($this->etfRepository->findBy([], ['symbol' => 'ASC']) as $etf) {
            if (null === $etf->getId()) {
                continue;
            }

            $pages[] = [
                'path' => sprintf('/etfs/%d', $etf->getId()),
                'target' => sprintf('etfs/%d/index.html', $etf->getId()),
            ];
        }

        foreach ($pages as $page) {
            $html = $this->renderPath($page['path']);
            $html = $this->rewriteRootRelativeUrls($html, $basePath);
            $this->writeFile($outputDir.'/'.$page['target'], $html);
        }

        $io->success(sprintf('Static export generated in "%s" with %d page(s).', $outputDir, count($pages)));

        return Command::SUCCESS;
    }

    private function renderPath(string $path): string
    {
        $request = Request::create($path, 'GET', [], [], [], [
            'HTTP_HOST' => 'localhost',
            'HTTPS' => 'off',
        ]);
        $request->attributes->set('static_export', true);

        $response = $this->kernel->handle($request, HttpKernelInterface::SUB_REQUEST);

        if (!$response->isSuccessful()) {
            throw new \RuntimeException(sprintf('Static export failed for "%s" with status code %d.', $path, $response->getStatusCode()));
        }

        return $response->getContent() ?: '';
    }

    private function rewriteRootRelativeUrls(string $html, string $basePath): string
    {
        return preg_replace_callback(
            '/\b(?<attribute>href|src|action)="(?<url>\/(?!\/)[^"]*)"/',
            fn (array $match): string => sprintf(
                '%s="%s"',
                $match['attribute'],
                htmlspecialchars($this->staticUrl($match['url'], $basePath), ENT_QUOTES),
            ),
            $html,
        ) ?? $html;
    }

    private function staticUrl(string $url, string $basePath): string
    {
        if ('/' === $url) {
            return '' !== $basePath ? $basePath.'/' : '/';
        }

        $parts = parse_url($url);
        $path = $parts['path'] ?? $url;
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        if (preg_match('#^/etfs/\d+$#', $path)) {
            $path .= '/';
        }

        return $basePath.$path.$query.$fragment;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $this->projectDir.'/'.trim($path, '/');
    }

    private function normalizeBasePath(string $basePath): string
    {
        $basePath = trim($basePath);

        if ('' === $basePath || '/' === $basePath) {
            return '';
        }

        return '/'.trim($basePath, '/');
    }

    private function resetDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $this->removeDirectory($directory);
        }

        $this->ensureDirectory($directory);
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }

        $this->ensureDirectory($target);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $destination = $target.'/'.substr($item->getPathname(), strlen($source) + 1);

            if ($item->isDir()) {
                $this->ensureDirectory($destination);

                continue;
            }

            $this->writeFile($destination, (string) file_get_contents($item->getPathname()));
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($directory);
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));

        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
    }
}
