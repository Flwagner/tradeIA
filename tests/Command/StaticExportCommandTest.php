<?php

namespace App\Tests\Command;

use App\Command\StaticExportCommand;
use App\Entity\Etf;
use App\Repository\EtfRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class StaticExportCommandTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $temporaryDirectories = [];

    public function testItExportsHomeEtfPagesAndAssetsForStaticHosting(): void
    {
        $projectDir = $this->temporaryDirectory();
        $outputDir = $this->temporaryDirectory();
        $this->writeFile($projectDir.'/public/assets/styles/app.css', 'body { color: #18202f; }');
        $this->writeFile($projectDir.'/public/assets/scripts/app.js', 'console.log("tradeIA");');

        $etf = $this->etf(12);
        $repository = $this->createMock(EtfRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with([], ['symbol' => 'ASC'])
            ->willReturn([$etf])
        ;

        $renderedPaths = [];
        $exportedAtAttributes = [];
        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel
            ->expects(self::exactly(2))
            ->method('handle')
            ->willReturnCallback(
                function (Request $request, int $type) use (&$renderedPaths, &$exportedAtAttributes): Response {
                    self::assertSame(HttpKernelInterface::SUB_REQUEST, $type);
                    self::assertTrue($request->attributes->get('static_export'));
                    self::assertInstanceOf(\DateTimeImmutable::class, $request->attributes->get('static_exported_at'));

                    $path = $request->getPathInfo();
                    $renderedPaths[] = $path;
                    $exportedAtAttributes[] = $request->attributes->get('static_exported_at');

                    return new Response(match ($path) {
                        '/' => '<a href="/etfs/12">ETF</a><script src="/assets/scripts/app.js"></script><form action="/refresh"></form>',
                        '/etfs/12' => '<link href="/assets/styles/app.css"><a href="/">Retour</a>',
                        default => throw new \RuntimeException(sprintf('Unexpected path "%s".', $path)),
                    });
                },
            )
        ;

        $commandTester = new CommandTester(new StaticExportCommand($kernel, $repository, $projectDir));
        $statusCode = $commandTester->execute([
            '--output' => $outputDir,
            '--base-path' => '/tradeIA',
        ]);

        self::assertSame(Command::SUCCESS, $statusCode);
        self::assertSame(['/', '/etfs/12'], $renderedPaths);
        self::assertSame($exportedAtAttributes[0], $exportedAtAttributes[1]);

        self::assertFileExists($outputDir.'/index.html');
        self::assertFileExists($outputDir.'/etfs/12/index.html');
        self::assertSame('body { color: #18202f; }', file_get_contents($outputDir.'/assets/styles/app.css'));
        self::assertSame('console.log("tradeIA");', file_get_contents($outputDir.'/assets/scripts/app.js'));

        $homeHtml = (string) file_get_contents($outputDir.'/index.html');
        self::assertStringContainsString('href="/tradeIA/etfs/12/"', $homeHtml);
        self::assertStringContainsString('src="/tradeIA/assets/scripts/app.js"', $homeHtml);
        self::assertStringContainsString('action="/tradeIA/refresh"', $homeHtml);

        $etfHtml = (string) file_get_contents($outputDir.'/etfs/12/index.html');
        self::assertStringContainsString('href="/tradeIA/assets/styles/app.css"', $etfHtml);
        self::assertStringContainsString('href="/tradeIA/"', $etfHtml);
    }

    public function testItFailsWhenRenderedPageIsNotSuccessful(): void
    {
        $projectDir = $this->temporaryDirectory();
        $outputDir = $this->temporaryDirectory();

        $repository = $this->createMock(EtfRepository::class);
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->willReturn([])
        ;

        $kernel = $this->createMock(HttpKernelInterface::class);
        $kernel
            ->expects(self::once())
            ->method('handle')
            ->willReturn(new Response('Boom', Response::HTTP_INTERNAL_SERVER_ERROR))
        ;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Static export failed for "/" with status code 500.');

        (new CommandTester(new StaticExportCommand($kernel, $repository, $projectDir)))->execute([
            '--output' => $outputDir,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (is_dir($directory)) {
                $this->removeDirectory($directory);
            }
        }

        $this->temporaryDirectories = [];
    }

    private function etf(int $id): Etf
    {
        $etf = (new Etf())
            ->setIsin('FR0010000001')
            ->setSymbol('TEST')
            ->setName('Test ETF')
            ->setExchange('XPAR')
            ->setCurrency('EUR')
        ;

        $idProperty = new \ReflectionProperty(Etf::class, 'id');
        $idProperty->setValue($etf, $id);

        return $etf;
    }

    private function temporaryDirectory(): string
    {
        $directory = sprintf('%s/tradeia-test-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Unable to write file "%s".', $path));
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
}
