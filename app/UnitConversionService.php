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
        if ($from['unit_type'] !== $to['unit_type']) {
            throw new RuntimeException("Cannot convert {$fromUnit} to {$toUnit}; unit types do not match.");
        }

        // base_multiplier expresses each unit in the system base unit for its type.
        $baseQuantity = $quantity * (float)$from['base_multiplier'];
        return $baseQuantity / (float)$to['base_multiplier'];
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
