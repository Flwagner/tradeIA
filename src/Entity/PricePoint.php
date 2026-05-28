<?php

namespace App\Entity;

use App\Repository\PricePointRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PricePointRepository::class)]
#[ORM\Table(name: 'price_point')]
#[ORM\UniqueConstraint(name: 'uniq_price_point_etf_date_source', columns: ['etf_id', 'priced_at', 'source'])]
#[ORM\Index(name: 'idx_price_point_etf_date', columns: ['etf_id', 'priced_at'])]
class PricePoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'pricePoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Etf $etf;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $pricedAt;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $openPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $highPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $lowPrice = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $closePrice;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6, nullable: true)]
    private ?string $adjustedClosePrice = null;

    #[ORM\Column(nullable: true)]
    private ?int $volume = null;

    #[ORM\Column(length: 32)]
    private string $source;

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

    public function getPricedAt(): \DateTimeImmutable
    {
        return $this->pricedAt;
    }

    public function setPricedAt(\DateTimeImmutable $pricedAt): self
    {
        $this->pricedAt = $pricedAt;

        return $this;
    }

    public function getOpenPrice(): ?string
    {
        return $this->openPrice;
    }

    public function setOpenPrice(?string $openPrice): self
    {
        $this->openPrice = $openPrice;

        return $this;
    }

    public function getHighPrice(): ?string
    {
        return $this->highPrice;
    }

    public function setHighPrice(?string $highPrice): self
    {
        $this->highPrice = $highPrice;

        return $this;
    }

    public function getLowPrice(): ?string
    {
        return $this->lowPrice;
    }

    public function setLowPrice(?string $lowPrice): self
    {
        $this->lowPrice = $lowPrice;

        return $this;
    }

    public function getClosePrice(): string
    {
        return $this->closePrice;
    }

    public function setClosePrice(string $closePrice): self
    {
        $this->closePrice = $closePrice;

        return $this;
    }

    public function getAdjustedClosePrice(): ?string
    {
        return $this->adjustedClosePrice;
    }

    public function setAdjustedClosePrice(?string $adjustedClosePrice): self
    {
        $this->adjustedClosePrice = $adjustedClosePrice;

        return $this;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function setVolume(?int $volume): self
    {
        $this->volume = $volume;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = strtolower($source);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
