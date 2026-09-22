<?php
final class UnitConversionService
{
    private array $cache = [];

    public function __construct(private Database $db) {}

    public function convert(float $quantity, string $fromUnit, string $toUnit): float
    {
        $fromUnit = trim($fromUnit);
        $toUnit = trim($toUnit);
        if ($fromUnit === $toUnit) return $quantity;

        $from = $this->unit($fromUnit);
        $to = $this->unit($toUnit);

        if (!$from || !$to) {
            throw new RuntimeException("Unknown unit conversion: {$fromUnit} → {$toUnit}.");
        }
        return self::convertByFactors(
            $quantity,
            (string)$from['unit_type'],
            (float)$from['base_multiplier'],
            (string)$to['unit_type'],
            (float)$to['base_multiplier'],
            $fromUnit,
            $toUnit
        );
    }

    public static function convertByFactors(
        float $quantity,
        string $fromType,
        float $fromMultiplier,
        string $toType,
        float $toMultiplier,
        string $fromLabel = 'source',
        string $toLabel = 'target'
    ): float {
        if ($fromType !== $toType) {
            throw new RuntimeException("Cannot convert {$fromLabel} to {$toLabel}; unit types do not match.");
        }
        if ($fromMultiplier <= 0 || $toMultiplier <= 0) {
            throw new RuntimeException('Unit conversion multipliers must be greater than zero.');
        }
        return ($quantity * $fromMultiplier) / $toMultiplier;
    }

    public function normalizedUnitCost(float $packagePrice, float $packageQuantity, string $packageUnit, string $inventoryUnit): float
    {
        if ($packageQuantity <= 0) throw new RuntimeException('Package quantity must be greater than zero.');
        $inventoryQuantity = $this->convert($packageQuantity, $packageUnit, $inventoryUnit);
        if ($inventoryQuantity <= 0) throw new RuntimeException('Normalized package quantity must be greater than zero.');
        return $packagePrice / $inventoryQuantity;
    }

    private function unit(string $symbol): ?array
    {
        if (array_key_exists($symbol, $this->cache)) return $this->cache[$symbol];
        return $this->cache[$symbol] = $this->db->one(
            'SELECT symbol,unit_type,base_multiplier FROM units WHERE symbol=?',
            [$symbol]
        );
    }
}
