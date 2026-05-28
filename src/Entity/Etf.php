<?php

namespace App\Entity;

use App\Repository\EtfRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EtfRepository::class)]
#[ORM\Table(name: 'etf')]
#[ORM\UniqueConstraint(name: 'uniq_etf_isin', columns: ['isin'])]
#[ORM\UniqueConstraint(name: 'uniq_etf_symbol_exchange', columns: ['symbol', 'exchange'])]
class Etf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $isin;

    #[ORM\Column(length: 32)]
    private string $symbol;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 32)]
    private string $exchange;

    #[ORM\Column(length: 8)]
    private string $currency = 'EUR';

    #[ORM\Column]
    private bool $peaEligible = false;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $boursoIdentifier = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $dataProviderSymbol = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, PricePoint>
     */
    #[ORM\OneToMany(mappedBy: 'etf', targetEntity: PricePoint::class, orphanRemoval: true)]
    private Collection $pricePoints;

    /**
     * @var Collection<int, MomentumSnapshot>
     */
    #[ORM\OneToMany(mappedBy: 'etf', targetEntity: MomentumSnapshot::class, orphanRemoval: true)]
    private Collection $momentumSnapshots;

    public function __construct()
    {
        $now = new \DateTimeImmutable();

        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->pricePoints = new ArrayCollection();
        $this->momentumSnapshots = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIsin(): string
    {
        return $this->isin;
    }

    public function setIsin(string $isin): self
    {
        $this->isin = strtoupper($isin);

        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = strtoupper($symbol);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getExchange(): string
    {
        return $this->exchange;
    }

    public function setExchange(string $exchange): self
    {
        $this->exchange = strtoupper($exchange);

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function isPeaEligible(): bool
    {
        return $this->peaEligible;
    }

    public function setPeaEligible(bool $peaEligible): self
    {
        $this->peaEligible = $peaEligible;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getBoursoIdentifier(): ?string
    {
        return $this->boursoIdentifier;
    }

    public function setBoursoIdentifier(?string $boursoIdentifier): self
    {
        $this->boursoIdentifier = $boursoIdentifier;

        return $this;
    }

    public function getDataProviderSymbol(): ?string
    {
        return $this->dataProviderSymbol;
    }

    public function setDataProviderSymbol(?string $dataProviderSymbol): self
    {
        $this->dataProviderSymbol = $dataProviderSymbol;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    /**
     * @return Collection<int, PricePoint>
     */
    public function getPricePoints(): Collection
    {
        return $this->pricePoints;
    }

    /**
     * @return Collection<int, MomentumSnapshot>
     */
    public function getMomentumSnapshots(): Collection
    {
        return $this->momentumSnapshots;
    }
}
