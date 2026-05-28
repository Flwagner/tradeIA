<?php

namespace App\Entity;

use App\Repository\MomentumSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MomentumSnapshotRepository::class)]
#[ORM\Table(name: 'momentum_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_momentum_snapshot_etf_date_strategy', columns: ['etf_id', 'computed_at', 'strategy_code'])]
#[ORM\Index(name: 'idx_momentum_snapshot_strategy_score', columns: ['strategy_code', 'score'])]
class MomentumSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'momentumSnapshots')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etf $etf;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $computedAt;

    #[ORM\Column(length: 64)]
    private string $strategyCode;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4)]
    private string $score;

    #[ORM\Column(name: 'performance_1_month', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $performance1Month = null;

    #[ORM\Column(name: 'performance_3_months', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $performance3Months = null;

    #[ORM\Column(name: 'performance_6_months', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $performance6Months = null;

    #[ORM\Column(name: 'performance_12_months', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $performance12Months = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $volatilityAnnualized = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $maxDrawdown = null;

    #[ORM\Column(name: 'moving_average_50', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $movingAverage50 = null;

    #[ORM\Column(name: 'moving_average_200', type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $movingAverage200 = null;

    #[ORM\Column(name: 'distance_to_moving_average_200', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $distanceToMovingAverage200 = null;

    #[ORM\Column(name: 'atr_14', type: 'decimal', precision: 10, scale: 4, nullable: true)]
    private ?string $atr14 = null;

    #[ORM\Column(length: 16)]
    private string $signal = 'watch';

    #[ORM\Column(type: 'json')]
    private array $details = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEtf(): Etf
    {
        return $this->etf;
    }

    public function setEtf(Etf $etf): self
    {
        $this->etf = $etf;

        return $this;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function setComputedAt(\DateTimeImmutable $computedAt): self
    {
        $this->computedAt = $computedAt;

        return $this;
    }

    public function getStrategyCode(): string
    {
        return $this->strategyCode;
    }

    public function setStrategyCode(string $strategyCode): self
    {
        $this->strategyCode = $strategyCode;

        return $this;
    }

    public function getScore(): string
    {
        return $this->score;
    }

    public function setScore(string $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getPerformance1Month(): ?string
    {
        return $this->performance1Month;
    }

    public function setPerformance1Month(?string $performance1Month): self
    {
        $this->performance1Month = $performance1Month;

        return $this;
    }

    public function getPerformance3Months(): ?string
    {
        return $this->performance3Months;
    }

    public function setPerformance3Months(?string $performance3Months): self
    {
        $this->performance3Months = $performance3Months;

        return $this;
    }

    public function getPerformance6Months(): ?string
    {
        return $this->performance6Months;
    }

    public function setPerformance6Months(?string $performance6Months): self
    {
        $this->performance6Months = $performance6Months;

        return $this;
    }

    public function getPerformance12Months(): ?string
    {
        return $this->performance12Months;
    }

    public function setPerformance12Months(?string $performance12Months): self
    {
        $this->performance12Months = $performance12Months;

        return $this;
    }

    public function getVolatilityAnnualized(): ?string
    {
        return $this->volatilityAnnualized;
    }

    public function setVolatilityAnnualized(?string $volatilityAnnualized): self
    {
        $this->volatilityAnnualized = $volatilityAnnualized;

        return $this;
    }

    public function getMaxDrawdown(): ?string
    {
        return $this->maxDrawdown;
    }

    public function setMaxDrawdown(?string $maxDrawdown): self
    {
        $this->maxDrawdown = $maxDrawdown;

        return $this;
    }

    public function getMovingAverage50(): ?string
    {
        return $this->movingAverage50;
    }

    public function setMovingAverage50(?string $movingAverage50): self
    {
        $this->movingAverage50 = $movingAverage50;

        return $this;
    }

    public function getMovingAverage200(): ?string
    {
        return $this->movingAverage200;
    }

    public function setMovingAverage200(?string $movingAverage200): self
    {
        $this->movingAverage200 = $movingAverage200;

        return $this;
    }

    public function getDistanceToMovingAverage200(): ?string
    {
        return $this->distanceToMovingAverage200;
    }

    public function setDistanceToMovingAverage200(?string $distanceToMovingAverage200): self
    {
        $this->distanceToMovingAverage200 = $distanceToMovingAverage200;

        return $this;
    }

    public function getAtr14(): ?string
    {
        return $this->atr14;
    }

    public function setAtr14(?string $atr14): self
    {
        $this->atr14 = $atr14;

        return $this;
    }

    public function getSignal(): string
    {
        return $this->signal;
    }

    public function setSignal(string $signal): self
    {
        $this->signal = $signal;

        return $this;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function setDetails(array $details): self
    {
        $this->details = $details;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
