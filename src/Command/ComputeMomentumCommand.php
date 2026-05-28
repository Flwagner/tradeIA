<?php

namespace App\Command;

use App\Momentum\MomentumComputer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:momentum:compute',
    description: 'Compute momentum snapshots for active ETFs.',
)]
class ComputeMomentumCommand extends Command
{
    public function __construct(
        private readonly MomentumComputer $momentumComputer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Computation date, parseable by DateTimeImmutable.', 'today')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $computedAt = $this->date((string) $input->getOption('as-of'))->setTime(0, 0);
        $rows = $this->momentumComputer->computeAll($computedAt);

        if ($rows === []) {
            $io->warning('No active ETF found.');

            return Command::SUCCESS;
        }

        $io->table(['ETF', 'Prices', 'Score', 'Signal', 'Status'], array_map(
            static fn (array $row): array => [$row['symbol'], $row['prices'], $row['score'], $row['signal'], $row['status']],
            $rows,
        ));
        $io->success('Momentum computation completed.');

        return Command::SUCCESS;
    }

    private function date(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \RuntimeException(sprintf('Invalid date "%s".', $value), previous: $exception);
        }
    }
}
