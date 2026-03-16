<?php

use PHPUnit\Framework\TestCase;

/**
 * CurrencyManager Unit Tests
 */
class CurrencyManagerTest extends TestCase {
    
    public function test_detectCurrencyFromDomain_dk() {
        $currency = \AEBG\Core\CurrencyManager::detectCurrencyFromDomain('https://example.dk');
        $this->assertEquals('DKK', $currency);
    }
    
    public function test_detectCurrencyFromDomain_se() {
        $currency = \AEBG\Core\CurrencyManager::detectCurrencyFromDomain('https://example.se');
        $this->assertEquals('SEK', $currency);
    }
    
    public function test_getProductCurrency_fallbackChain() {
        // Test with existing product currency
        $existing = [['currency' => 'DKK']];
        $product = ['name' => 'Test'];
        $currency = \AEBG\Core\CurrencyManager::getProductCurrency($product, $existing);
        $this->assertEquals('DKK', $currency);
    }
}

